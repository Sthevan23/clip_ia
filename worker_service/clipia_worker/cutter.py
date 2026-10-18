"""
Módulo de Corte e Renderização de Clipes com FFmpeg e Incorporação de Legendas (`cutter.py`).

Responsabilidades:
- Localiza o binário do FFmpeg (`CLIPIA_FFMPEG_BINARY`, `PATH` do sistema ou `imageio-ffmpeg`).
- Verifica espaço livre em disco antes de iniciar a renderização.
- Para cada `ClipCandidate` aprovado pelo analisador, recorta o trecho exato
  (`start_time_seconds` até `end_time_seconds`) usando FFmpeg sem `shell=True`.
- Gera legendas sincronizadas (`.srt` e `.ass`) a partir dos segmentos/palavras do `faster-whisper`.
- Permite incorporar as legendas ao vídeo (`burn_subtitles=True`) com fonte grande, contorno,
  sombra, destaque estático de palavras importantes e posicionamento seguro para vídeos verticais 9:16,
  ou gerar o vídeo limpo com exportação de arquivo `.srt` separado (`burn_subtitles=False`).
- Utiliza arquivos temporários `.part.mp4` e `.tmp.ass` durante a escrita, removendo-os em `finally`.
"""

from __future__ import annotations

import logging
import os
import shutil
import subprocess
from pathlib import Path

from .config import WorkerConfig
from .models import (
    ClipCandidate,
    ClipSelectionParams,
    SubtitleStyleConfig,
    TranscriptDocument,
    UsageRecord,
)
from .security import validate_authorized_local_media_file
from .subtitles import SubtitleGenerator

logger = logging.getLogger("clipia.cutter")

_ASPECT_DIMENSIONS: dict[str, tuple[int, int]] = {
    "9:16": (720, 1280),
    "1:1": (720, 720),
    "16:9": (1280, 720),
}


class FFmpegClipCutter:
    """Executa o corte físico dos trechos selecionados em arquivos MP4 e gera/incorpora legendas sincronizadas."""

    def __init__(self, config: WorkerConfig) -> None:
        self.config = config
        self._ffmpeg_exe: str | None = None

    def resolve_ffmpeg_binary(self) -> str:
        """
        Resolve o caminho do executável FFmpeg na seguinte ordem:
        1. Variável `CLIPIA_FFMPEG_BINARY` (se definida e válida).
        2. `ffmpeg` disponível no `PATH` do sistema operacional.
        3. Binário estático empacotado pelo `imageio-ffmpeg`.
        """
        if self._ffmpeg_exe:
            return self._ffmpeg_exe

        configured = (self.config.ffmpeg_binary or "").strip()
        if configured:
            cfg_path = Path(configured).expanduser()
            if cfg_path.is_file():
                self._ffmpeg_exe = str(cfg_path.resolve())
                return self._ffmpeg_exe
            which_cfg = shutil.which(configured)
            if which_cfg:
                self._ffmpeg_exe = which_cfg
                return self._ffmpeg_exe

        system_ffmpeg = shutil.which("ffmpeg")
        if system_ffmpeg:
            self._ffmpeg_exe = system_ffmpeg
            return self._ffmpeg_exe

        try:
            import imageio_ffmpeg

            bundled = imageio_ffmpeg.get_ffmpeg_exe()
            if bundled and Path(bundled).is_file():
                self._ffmpeg_exe = str(Path(bundled).resolve())
                return self._ffmpeg_exe
        except Exception:
            pass

        raise RuntimeError(
            "FFmpeg não encontrado no sistema. Instale 'imageio-ffmpeg' (pip install imageio-ffmpeg) "
            "ou adicione o executável 'ffmpeg' ao PATH do Windows."
        )

    def check_free_disk_space(self, target_dir: Path) -> None:
        """Garante que há espaço livre mínimo em disco antes de gerar novos arquivos de vídeo."""
        target_dir.mkdir(parents=True, exist_ok=True)
        usage = shutil.disk_usage(str(target_dir))
        min_bytes = max(10, int(self.config.min_free_disk_mb)) * 1024 * 1024
        if usage.free < min_bytes:
            free_mb = usage.free // (1024 * 1024)
            raise OSError(
                f"Espaço em disco insuficiente em '{target_dir}': {free_mb} MB livres "
                f"(mínimo exigido: {self.config.min_free_disk_mb} MB)."
            )

    @staticmethod
    def _build_video_filter(aspect_ratio: str, ass_relative_filename: str | None = None) -> tuple[int, int, str]:
        width, height = _ASPECT_DIMENSIONS.get(aspect_ratio, (720, 1280))
        vf = (
            f"scale={width}:{height}:force_original_aspect_ratio=decrease,"
            f"pad={width}:{height}:(ow-iw)/2:(oh-ih)/2:black,setsar=1"
        )
        if ass_relative_filename:
            safe_ass = ass_relative_filename.replace("\\", "/").replace(":", r"\:").replace("'", r"\'")
            vf = f"{vf},ass='{safe_ass}'"
        return width, height, vf

    def cut_candidates(
        self,
        media_path: str | Path,
        candidates: list[ClipCandidate],
        params: ClipSelectionParams,
        usage: UsageRecord,
        document: TranscriptDocument | None = None,
        output_dir: str | Path | None = None,
        project_id: int | None = None,
        job_id: int | None = None,
    ) -> list[Path]:
        """
        Recorta cada candidato em um arquivo `.mp4` independente usando FFmpeg,
        gera o arquivo `.srt` sincronizado e incorpora as legendas ao vídeo quando
        `style.burn_subtitles` estiver habilitado.
        """
        if not candidates:
            return []

        validated_media = validate_authorized_local_media_file(media_path, authorized_confirmed=True)
        ffmpeg_bin = self.resolve_ffmpeg_binary()

        dest_dir = Path(output_dir or self.config.clips_output_dir).expanduser().resolve()
        self.check_free_disk_space(dest_dir)

        is_audio_only_ext = validated_media.suffix.lower() in {".wav", ".mp3", ".m4a"}

        rendered_paths: list[Path] = []
        proj_tag = f"p{project_id}" if project_id is not None else "local"
        job_tag = f"j{job_id}" if job_id is not None else "run"

        for idx, cand in enumerate(candidates, start=1):
            self.check_free_disk_space(dest_dir)
            style: SubtitleStyleConfig = cand.subtitle_style or params.subtitle_style or SubtitleStyleConfig()
            cand.subtitle_style = style
            cand.subtitles_burned = bool(style.burn_subtitles)

            start_sec = max(0.0, float(cand.start_time_seconds))
            duration_sec = max(1.0, round(float(cand.end_time_seconds - cand.start_time_seconds), 2))

            base_stem = (
                f"clip_{proj_tag}_{job_tag}_{idx:02d}_"
                f"{int(round(start_sec))}s_{int(round(cand.end_time_seconds))}s"
            )
            clip_filename = f"{base_stem}.mp4"
            srt_filename = f"{base_stem}.srt"
            final_clip_path = dest_dir / clip_filename
            final_srt_path = dest_dir / srt_filename
            tmp_clip_path = dest_dir / f".tmp_{os.getpid()}_{clip_filename}.part.mp4"
            tmp_ass_name = f".tmp_{os.getpid()}_{base_stem}.ass"
            tmp_ass_path = dest_dir / tmp_ass_name

            # 1. Constrói cues sincronizados para o intervalo do clipe e salva o arquivo .srt
            cues = SubtitleGenerator.build_cues_for_candidate(
                candidate=cand,
                document=document,
                style=style,
                aspect_ratio=params.aspect_ratio,
            )
            srt_text = SubtitleGenerator.format_srt(cues)
            cand.srt_content = srt_text
            if style.export_srt and srt_text:
                final_srt_path.write_text(srt_text, encoding="utf-8")
                cand.srt_storage_url = f"storage/clips/{final_srt_path.name}"

            try:
                ass_rel_for_ffmpeg: str | None = None
                width, height = _ASPECT_DIMENSIONS.get(params.aspect_ratio, (720, 1280))
                if style.burn_subtitles and cues:
                    ass_text = SubtitleGenerator.format_ass(
                        cues=cues,
                        style=style,
                        width=width,
                        height=height,
                    )
                    tmp_ass_path.write_text(ass_text, encoding="utf-8-sig")
                    ass_rel_for_ffmpeg = tmp_ass_name

                width, height, vf_filter = self._build_video_filter(
                    params.aspect_ratio,
                    ass_relative_filename=ass_rel_for_ffmpeg,
                )

                if is_audio_only_ext:
                    self._run_audio_to_video_cut(
                        ffmpeg_bin=ffmpeg_bin,
                        source_path=validated_media,
                        tmp_output=tmp_clip_path,
                        work_dir=dest_dir,
                        start_sec=start_sec,
                        duration_sec=duration_sec,
                        width=width,
                        height=height,
                        ass_relative_filename=ass_rel_for_ffmpeg,
                    )
                else:
                    try:
                        self._run_video_cut(
                            ffmpeg_bin=ffmpeg_bin,
                            source_path=validated_media,
                            tmp_output=tmp_clip_path,
                            work_dir=dest_dir,
                            start_sec=start_sec,
                            duration_sec=duration_sec,
                            vf_filter=vf_filter,
                        )
                    except RuntimeError:
                        # Contingência caso o container de mídia possua apenas trilha de áudio
                        self._run_audio_to_video_cut(
                            ffmpeg_bin=ffmpeg_bin,
                            source_path=validated_media,
                            tmp_output=tmp_clip_path,
                            work_dir=dest_dir,
                            start_sec=start_sec,
                            duration_sec=duration_sec,
                            width=width,
                            height=height,
                            ass_relative_filename=ass_rel_for_ffmpeg,
                        )

                if not tmp_clip_path.is_file() or tmp_clip_path.stat().st_size <= 0:
                    raise RuntimeError(f"FFmpeg gerou um arquivo vazio para o corte #{idx}.")

                os.replace(tmp_clip_path, final_clip_path)
                cand.storage_url = f"storage/clips/{final_clip_path.name}"
                cand.status = "ready"
                rendered_paths.append(final_clip_path)
                logger.info(
                    "Clipe #%d cortado com FFmpeg (legendas_incorporadas=%s): %s (%.1fs–%.1fs, %d bytes)",
                    idx,
                    "sim" if style.burn_subtitles else "nao",
                    final_clip_path.name,
                    start_sec,
                    cand.end_time_seconds,
                    final_clip_path.stat().st_size,
                )
            finally:
                for leftover in (tmp_clip_path, tmp_ass_path):
                    if leftover.exists():
                        try:
                            leftover.unlink(missing_ok=True)
                        except OSError:
                            pass

        usage.ffmpeg_used = True
        usage.clips_rendered_count = len(rendered_paths)
        usage.subtitles_generated_count = len(rendered_paths)
        usage.subtitles_burned = bool(params.subtitle_style.burn_subtitles)
        return rendered_paths

    @staticmethod
    def _run_video_cut(
        *,
        ffmpeg_bin: str,
        source_path: Path,
        tmp_output: Path,
        work_dir: Path,
        start_sec: float,
        duration_sec: float,
        vf_filter: str,
    ) -> None:
        cmd = [
            ffmpeg_bin,
            "-y",
            "-hide_banner",
            "-loglevel",
            "error",
            "-ss",
            f"{start_sec:.2f}",
            "-t",
            f"{duration_sec:.2f}",
            "-i",
            str(source_path.resolve()),
            "-avoid_negative_ts",
            "make_zero",
            "-vf",
            vf_filter,
            "-c:v",
            "libx264",
            "-preset",
            "ultrafast",
            "-pix_fmt",
            "yuv420p",
            "-c:a",
            "aac",
            "-b:a",
            "96k",
            "-movflags",
            "+faststart",
            str(tmp_output.resolve()),
        ]
        proc = subprocess.run(
            cmd,
            cwd=str(work_dir.resolve()),
            capture_output=True,
            text=True,
            timeout=180,
            check=False,
        )
        if proc.returncode != 0:
            err_detail = (proc.stderr or proc.stdout or "erro desconhecido").strip()[:350]
            raise RuntimeError(f"Falha ao cortar vídeo com FFmpeg: {err_detail}")

    @staticmethod
    def _run_audio_to_video_cut(
        *,
        ffmpeg_bin: str,
        source_path: Path,
        tmp_output: Path,
        work_dir: Path,
        start_sec: float,
        duration_sec: float,
        width: int,
        height: int,
        ass_relative_filename: str | None = None,
    ) -> None:
        cmd = [
            ffmpeg_bin,
            "-y",
            "-hide_banner",
            "-loglevel",
            "error",
            "-f",
            "lavfi",
            "-i",
            f"color=c=0x1e1b4b:s={width}x{height}:r=10:d={duration_sec:.2f}",
            "-ss",
            f"{start_sec:.2f}",
            "-t",
            f"{duration_sec:.2f}",
            "-i",
            str(source_path.resolve()),
            "-avoid_negative_ts",
            "make_zero",
        ]
        if ass_relative_filename:
            safe_ass = ass_relative_filename.replace("\\", "/").replace(":", r"\:").replace("'", r"\'")
            cmd.extend(["-vf", f"ass='{safe_ass}'"])
        cmd.extend(
            [
                "-c:v",
                "libx264",
                "-preset",
                "ultrafast",
                "-tune",
                "stillimage",
                "-pix_fmt",
                "yuv420p",
                "-c:a",
                "aac",
                "-b:a",
                "96k",
                "-shortest",
                "-movflags",
                "+faststart",
                str(tmp_output.resolve()),
            ]
        )
        proc = subprocess.run(
            cmd,
            cwd=str(work_dir.resolve()),
            capture_output=True,
            text=True,
            timeout=180,
            check=False,
        )
        if proc.returncode != 0:
            err_detail = (proc.stderr or proc.stdout or "erro desconhecido").strip()[:350]
            raise RuntimeError(f"Falha ao renderizar corte de áudio/vídeo com FFmpeg: {err_detail}")
