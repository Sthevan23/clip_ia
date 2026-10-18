"""
Módulo de Transcrição de Áudio com Timestamps (`transcriber.py`).

Atende aos requisitos:
- Utiliza `faster-whisper` para transcrever áudio localmente, permitindo configurar o modelo
  (`tiny`, `base`, `small`, `medium`, `large-v3`) e o dispositivo (`cpu`, `cuda`, `auto`).
- Também permite receber uma transcrição pré-existente com timestamps (JSON).
- Aplica limite máximo de duração de áudio (`max_audio_duration_seconds`) para controle de recursos.
"""

from __future__ import annotations

import json
import logging
from pathlib import Path

from .config import WorkerConfig
from .models import TranscriptDocument, TranscriptSegment, TranscriptWord, UsageRecord
from .security import validate_authorized_local_media_file

logger = logging.getLogger("clipia.transcriber")


def _ensure_pyav_compatibility() -> None:
    """
    Garante compatibilidade entre `faster-whisper` (que passa `metadata_errors='ignore'` em `av.open`)
    e versões recentes do `PyAV` (av >= 15/19 no Python 3.13+), onde `metadata_errors` foi removido
    da assinatura Cython de `av.open()`.
    """
    try:
        import av
        import faster_whisper.audio as fw_audio

        orig_open = getattr(av, "_clipia_orig_open", av.open)
        if not hasattr(av, "_clipia_orig_open"):
            setattr(av, "_clipia_orig_open", orig_open)

            def _compat_av_open(*args, **kwargs):
                try:
                    return orig_open(*args, **kwargs)
                except TypeError as exc:
                    if "metadata_errors" in kwargs and "metadata_errors" in str(exc):
                        clean_kwargs = {k: v for k, v in kwargs.items() if k != "metadata_errors"}
                        return orig_open(*args, **clean_kwargs)
                    raise

            av.open = _compat_av_open
            fw_audio.av.open = _compat_av_open
    except Exception:
        pass


class AudioTranscriber:
    """Gerencia a geração ou carregamento de transcrições com timestamps."""

    def __init__(self, config: WorkerConfig) -> None:
        self.config = config
        self._whisper_instance = None

    def _get_whisper_model(self):
        """Inicializa o `WhisperModel` do `faster-whisper` sob demanda."""
        if self._whisper_instance is None:
            try:
                from faster_whisper import WhisperModel
            except ImportError as exc:
                raise RuntimeError(
                    "A biblioteca 'faster-whisper' não está instalada no ambiente Python atual. "
                    "Execute: python -m pip install -r worker_service/requirements.txt"
                ) from exc

            _ensure_pyav_compatibility()

            logger.info(
                "Inicializando faster-whisper [model=%s, device=%s, compute_type=%s]...",
                self.config.whisper_model,
                self.config.whisper_device,
                self.config.whisper_compute_type,
            )
            self._whisper_instance = WhisperModel(
                self.config.whisper_model,
                device=self.config.whisper_device,
                compute_type=self.config.whisper_compute_type,
            )
        return self._whisper_instance

    def load_existing_transcript(
        self,
        transcript_json_path: str | Path,
        usage: UsageRecord,
    ) -> TranscriptDocument:
        """
        Recebe e valida uma transcrição já existente com timestamps (em formato JSON).
        """
        resolved = Path(transcript_json_path).expanduser().resolve()
        if not resolved.is_file():
            raise FileNotFoundError(f"Arquivo de transcrição não encontrado: {resolved}")

        raw_data = json.loads(resolved.read_text(encoding="utf-8"))
        doc = TranscriptDocument.model_validate(raw_data)

        if doc.duration_seconds > self.config.max_audio_duration_seconds:
            raise ValueError(
                f"Duração da transcrição ({doc.duration_seconds:.1f}s) excede o limite configurado "
                f"({self.config.max_audio_duration_seconds}s)."
            )

        usage.transcription_mode = "pre_generated_json"
        usage.audio_duration_seconds = round(doc.duration_seconds, 2)
        return doc

    def transcribe_authorized_media(
        self,
        media_path: str | Path,
        usage: UsageRecord,
        authorized_confirmed: bool = True,
        companion_transcript_path: str | Path | None = None,
    ) -> TranscriptDocument:
        """
        Transcreve um arquivo de vídeo/áudio local autorizado usando `faster-whisper`
        com timestamps precisos por segmento.
        Caso um arquivo `.transcript.json` complementar seja informado explicitamente
        ou exista ao lado da mídia e seja solicitado, também pode carregá-lo.
        """
        validated_media = validate_authorized_local_media_file(
            media_path,
            authorized_confirmed=authorized_confirmed,
        )

        if companion_transcript_path is not None:
            return self.load_existing_transcript(companion_transcript_path, usage)

        model = self._get_whisper_model()
        logger.info("Transcrevendo arquivo autorizado '%s' com faster-whisper...", validated_media.name)

        def _run_transcribe(use_vad: bool):
            return model.transcribe(
                str(validated_media),
                language=self.config.whisper_language or None,
                beam_size=5,
                vad_filter=use_vad,
                vad_parameters={"min_silence_duration_ms": 400} if use_vad else None,
                word_timestamps=True,
            )

        segments_iter, info = _run_transcribe(True)

        total_duration = float(getattr(info, "duration", 0.0) or 0.0)
        if total_duration > self.config.max_audio_duration_seconds:
            raise ValueError(
                f"O áudio possui {total_duration:.1f}s e excede o limite de segurança de "
                f"{self.config.max_audio_duration_seconds}s configurado para evitar custos excessivos."
            )

        collected_segments: list[TranscriptSegment] = []
        max_end_seen = 0.0

        def _collect_segments(seg_iter, dur_hint: float) -> tuple[list[TranscriptSegment], float]:
            out: list[TranscriptSegment] = []
            max_end = 0.0
            for seg in seg_iter:
                text_clean = (seg.text or "").strip()
                if not text_clean:
                    continue
                start_s = round(max(0.0, float(seg.start)), 2)
                end_s = round(max(start_s + 0.5, float(seg.end)), 2)
                max_end = max(max_end, end_s)

                seg_words: list[TranscriptWord] = []
                raw_words = getattr(seg, "words", None) or []
                for w in raw_words:
                    w_text = (getattr(w, "word", "") or "").strip()
                    if not w_text:
                        continue
                    w_start = round(max(start_s, float(getattr(w, "start", start_s))), 2)
                    w_end = round(min(end_s, max(w_start + 0.05, float(getattr(w, "end", w_start + 0.1)))), 2)
                    w_prob = round(min(1.0, max(0.0, float(getattr(w, "probability", 0.85) or 0.85))), 3)
                    seg_words.append(
                        TranscriptWord(
                            word=w_text,
                            start=w_start,
                            end=w_end,
                            probability=w_prob,
                        )
                    )

                out.append(
                    TranscriptSegment(
                        start=start_s,
                        end=end_s,
                        text=text_clean,
                        words=seg_words,
                    )
                )
            return out, max(dur_hint, max_end, 1.0)

        collected_segments, max_end_seen = _collect_segments(segments_iter, total_duration)
        if not collected_segments:
            logger.warning(
                "VAD removeu toda a fala detectável em '%s'; repetindo transcrição sem filtro VAD.",
                validated_media.name,
            )
            segments_iter, info = _run_transcribe(False)
            total_duration = float(getattr(info, "duration", 0.0) or 0.0)
            collected_segments, max_end_seen = _collect_segments(segments_iter, total_duration)

        final_duration = max(total_duration, max_end_seen, 1.0)
        usage.transcription_mode = "faster_whisper_local"
        usage.whisper_model = self.config.whisper_model
        usage.whisper_device = self.config.whisper_device
        usage.audio_duration_seconds = round(final_duration, 2)

        logger.info(
            "Transcrição concluída: %d segmentos em %.1fs de áudio.",
            len(collected_segments),
            final_duration,
        )

        return TranscriptDocument(
            language=getattr(info, "language", self.config.whisper_language) or "pt",
            duration_seconds=round(final_duration, 2),
            source_label=validated_media.name,
            segments=collected_segments,
        )
