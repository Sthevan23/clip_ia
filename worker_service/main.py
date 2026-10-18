"""
Orquestrador de Tarefas e Ponto de Entrada CLI (`worker_service/main.py`).

Suporta dois modos principais de operação:
1. Modo Local Direto (`--local-file caminho/do/video.mp4`):
   Testa diretamente um arquivo de vídeo/áudio local autorizado no Windows (com opção de
   transcrição `faster-whisper` ou arquivo `.transcript.json`), exibindo e salvando o JSON
   estruturado dos candidatos e o relatório de consumo/custos.
2. Modo Integrado com Backend PHP (`--process-next` ou `--poll`):
   Consulta tarefas autenticadas via HMAC-SHA256 em `pages/worker-api.php`, processa a fonte
   autorizada com suporte a repetição automática em caso de falha transitória e envia os
   candidatos validados ao MySQL/SQLite.
"""

from __future__ import annotations

import argparse
from contextlib import contextmanager
import json
import logging
import os
import sys
import time
from pathlib import Path
from typing import Any, Iterator

from clipia_worker.analyzer import TranscriptAnalyzer
from clipia_worker.api_client import BackendApiClient
from clipia_worker.config import WorkerConfig
from clipia_worker.cutter import FFmpegClipCutter
from clipia_worker.models import (
    ClipCandidate,
    ClipSelectionParams,
    SubtitleStyleConfig,
    UsageRecord,
)
from clipia_worker.security import (
    SensitiveDataLogFilter,
    redact_sensitive_text,
    validate_authorized_local_media_file,
)
from clipia_worker.transcriber import AudioTranscriber

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logging.getLogger("httpx").setLevel(logging.WARNING)
logging.getLogger("httpcore").setLevel(logging.WARNING)
logger = logging.getLogger("clipia.main")

_SINGLE_WORKER_LOCK_PATH = Path(__file__).resolve().parent.parent / "storage" / "single_worker.lock"


@contextmanager
def acquire_single_worker_instance_lock(lock_path: Path = _SINGLE_WORKER_LOCK_PATH) -> Iterator[None]:
    """
    Garante que apenas 1 instância do worker Python seja executada por vez na máquina local/VPS,
    evitando tarefas duplicadas ou concorrência desnecessária de CPU/GPU.
    Se um processo anterior tiver sido interrompido abruptamente (PID inexistente), recupera o lock automaticamente.
    """
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    lock_fd: int | None = None

    for _ in range(2):
        try:
            lock_fd = os.open(str(lock_path), os.O_CREAT | os.O_EXCL | os.O_RDWR)
            os.write(lock_fd, f"{os.getpid()}:{int(time.time())}".encode("utf-8"))
            break
        except FileExistsError:
            stale = False
            try:
                raw_content = lock_path.read_text(encoding="utf-8").strip()
                pid_str = raw_content.split(":", 1)[0]
                existing_pid = int(pid_str)
                # Em Windows, os.kill(pid, 0) com o próprio PID envia CTRL_C_EVENT ao console!
                if existing_pid != os.getpid():
                    try:
                        os.kill(existing_pid, 0)
                    except OSError:
                        stale = True
            except Exception:
                stale = True

            if stale:
                logger.warning("Recuperando lock órfão de instância anterior interrompida (%s).", lock_path.name)
                try:
                    lock_path.unlink(missing_ok=True)
                except OSError:
                    pass
                continue

            raise RuntimeError(
                f"Outra instância do ClipIA Worker já está em execução (lock ativo: {lock_path}). "
                "O sistema está configurado para operar com um único worker."
            )

    try:
        yield
    finally:
        if lock_fd is not None:
            try:
                os.close(lock_fd)
            except OSError:
                pass
        try:
            lock_path.unlink(missing_ok=True)
        except OSError:
            pass


class ClipIAWorkerOrchestrator:
    """Coordena transcrição, análise por blocos, controle de falhas/repetição e comunicação com a API PHP."""

    def __init__(self, config: WorkerConfig) -> None:
        self.config = config
        log_filter = SensitiveDataLogFilter([config.worker_shared_secret, config.llm_api_key])
        logging.getLogger().addFilter(log_filter)
        for handler in logging.getLogger().handlers:
            handler.addFilter(log_filter)
        self.transcriber = AudioTranscriber(config)
        self.analyzer = TranscriptAnalyzer(config)
        self.cutter = FFmpegClipCutter(config)

    def run_on_local_authorized_file(
        self,
        media_path: str | Path,
        params: ClipSelectionParams,
        transcript_json_path: str | Path | None = None,
        backend_api: BackendApiClient | None = None,
        job_id: int | None = None,
        project_id: int | None = None,
    ) -> dict[str, Any]:
        """
        Executa o pipeline completo sobre um arquivo local autorizado com suporte a repetição
        interna (`max_retries_per_task`) em caso de erro transitório.
        """
        usage = UsageRecord(
            whisper_model=self.config.whisper_model,
            whisper_device=self.config.whisper_device,
            analysis_provider=self.config.ai_provider,
        )

        last_exc: Exception | None = None
        max_attempts = 1 + self.config.max_retries_per_task

        for attempt in range(1, max_attempts + 1):
            try:
                if attempt > 1:
                    usage.retries_count += 1
                    logger.info("Repetindo tentativa #%d/%d após falha anterior...", attempt, max_attempts)

                document = self.transcriber.transcribe_authorized_media(
                    media_path=media_path,
                    usage=usage,
                    authorized_confirmed=True,
                    companion_transcript_path=transcript_json_path,
                )

                candidates: list[ClipCandidate] = self.analyzer.analyze(
                    document=document,
                    params=params,
                    usage=usage,
                    backend_api=backend_api,
                    job_id=job_id,
                    project_id=project_id,
                )

                if self.config.render_clips_with_ffmpeg and candidates:
                    rendered_files = self.cutter.cut_candidates(
                        media_path=media_path,
                        candidates=candidates,
                        params=params,
                        usage=usage,
                        document=document,
                        project_id=project_id,
                        job_id=job_id,
                    )
                    if backend_api is not None and job_id is not None and project_id is not None:
                        for idx, (cand, clip_file) in enumerate(zip(candidates, rendered_files), start=1):
                            remote_storage_url, remote_srt_url = backend_api.upload_rendered_clip(
                                job_id=job_id,
                                project_id=project_id,
                                clip_index=idx,
                                clip_file_path=clip_file,
                                srt_content=cand.srt_content,
                            )
                            cand.storage_url = remote_storage_url
                            if remote_srt_url:
                                cand.srt_storage_url = remote_srt_url
                            cand.status = "ready"

                return {
                    "ok": True,
                    "source_file": str(Path(media_path).resolve()),
                    "transcript_segments_count": len(document.segments),
                    "audio_duration_seconds": document.duration_seconds,
                    "selection_parameters": params.model_dump(),
                    "candidates": [c.model_dump() for c in candidates],
                    "usage_metrics": usage.model_dump(),
                }
            except Exception as exc:
                last_exc = exc
                safe_msg = redact_sensitive_text(
                    str(exc),
                    [self.config.worker_shared_secret, self.config.llm_api_key],
                )
                err_text = f"Tentativa {attempt}/{max_attempts} falhou: {safe_msg}"
                logger.error(err_text)
                usage.errors_logged.append(err_text)
                if attempt < max_attempts:
                    time.sleep(0.2)

        final_safe_err = redact_sensitive_text(
            str(last_exc),
            [self.config.worker_shared_secret, self.config.llm_api_key],
        )
        raise RuntimeError(f"Tarefa falhou após {max_attempts} tentativa(s): {final_safe_err}") from last_exc

    def process_next_backend_job(self, specific_project_id: int | None = None) -> bool:
        """
        Consulta a próxima tarefa autorizada na API PHP, executa transcrição + análise
        e envia os candidatos validados ou registra a falha para permitir repetição.
        """
        api = BackendApiClient(self.config)
        job = api.claim_next_job(specific_project_id=specific_project_id)

        if not job:
            logger.info("Nenhuma tarefa autorizada aguardando processamento no backend PHP.")
            return False

        job_id = int(job["job_id"])
        project_id = int(job["project_id"])
        project_title = str(job.get("project_title", f"Projeto #{project_id}"))
        auth_source = job.get("authorized_source", {})
        clip_params_raw = job.get("clip_parameters", {})
        backend_monthly_brl = float(job.get("monthly_spent_brl", 0.0) or 0.0)
        self.analyzer.budget_guard.sync_with_backend_monthly_spent(backend_monthly_brl)

        logger.info(
            "Tarefa reservada: job_id=%d | project_id=%d (%s) | tentativa #%s",
            job_id,
            project_id,
            project_title,
            job.get("attempt", 1),
        )

        temp_downloaded_file: Path | None = None
        try:
            if auth_source.get("source_mode") != "uploaded_file":
                raise PermissionError("Fonte rejeitada: o projeto não possui arquivo autorizado vinculado.")

            local_abs = auth_source.get("local_absolute_path", "")
            if local_abs and Path(local_abs).is_file():
                media_path = validate_authorized_local_media_file(local_abs, authorized_confirmed=True)
            else:
                temp_downloaded_file = api.download_authorized_source_to_temp(
                    job_id=job_id,
                    project_id=project_id,
                    original_name=str(auth_source.get("uploaded_file_name", "source.mp4")),
                )
                media_path = validate_authorized_local_media_file(
                    temp_downloaded_file,
                    authorized_confirmed=True,
                )

            raw_sub_style = clip_params_raw.get("subtitle_style")
            sub_style = (
                SubtitleStyleConfig.model_validate(raw_sub_style)
                if isinstance(raw_sub_style, dict)
                else SubtitleStyleConfig()
            )
            params = ClipSelectionParams(
                min_duration_seconds=float(clip_params_raw.get("min_duration_seconds", 30)),
                max_duration_seconds=float(clip_params_raw.get("max_duration_seconds", 60)),
                target_clips_count=int(clip_params_raw.get("target_clips_count", 5)),
                aspect_ratio=str(clip_params_raw.get("aspect_ratio", "9:16")),
                subtitle_style=sub_style,
            )

            # Verifica se existe transcrição autorizada complementar ao lado do arquivo (opcional)
            companion_json = Path(str(media_path) + ".transcript.json")
            transcript_override = companion_json if companion_json.is_file() else None

            result = self.run_on_local_authorized_file(
                media_path=media_path,
                params=params,
                transcript_json_path=transcript_override,
                backend_api=api,
                job_id=job_id,
                project_id=project_id,
            )

            candidates_models = [ClipCandidate.model_validate(c) for c in result["candidates"]]
            usage_model = UsageRecord.model_validate(result["usage_metrics"])

            api_resp = api.complete_job(
                job_id=job_id,
                project_id=project_id,
                candidates=candidates_models,
                usage=usage_model,
            )
            logger.info("Sucesso! Resposta do backend PHP: %s", api_resp.get("message"))
            return True

        except Exception as exc:
            safe_err = redact_sensitive_text(
                str(exc),
                [self.config.worker_shared_secret, self.config.llm_api_key],
            )
            logger.error("Erro ao processar job_id=%d (project_id=%d): %s", job_id, project_id, safe_err)
            try:
                api.fail_job(job_id=job_id, project_id=project_id, error_message=safe_err)
            except Exception as report_exc:
                safe_rep_err = redact_sensitive_text(
                    str(report_exc),
                    [self.config.worker_shared_secret, self.config.llm_api_key],
                )
                logger.error("Falha adicional ao reportar erro à API PHP: %s", safe_rep_err)
            return False
        finally:
            if temp_downloaded_file is not None and temp_downloaded_file.exists():
                temp_downloaded_file.unlink(missing_ok=True)


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="ClipIA Worker Service — Transcrição local faster-whisper e seleção inteligente de trechos."
    )
    parser.add_argument(
        "--local-file",
        type=str,
        help="Caminho de um arquivo de vídeo/áudio local autorizado para teste direto.",
    )
    parser.add_argument(
        "--transcript-json",
        type=str,
        default=None,
        help="Opcional: caminho de uma transcrição JSON com timestamps já gerada.",
    )
    parser.add_argument(
        "--output-json",
        type=str,
        default=None,
        help="Opcional: arquivo onde salvar o resultado JSON da análise local.",
    )
    parser.add_argument(
        "--min-duration",
        type=float,
        default=30.0,
        help="Duração mínima de cada corte candidato em segundos (padrão: 30).",
    )
    parser.add_argument(
        "--max-duration",
        type=float,
        default=60.0,
        help="Duração máxima de cada corte candidato em segundos (padrão: 60).",
    )
    parser.add_argument(
        "--clips-count",
        type=int,
        default=4,
        help="Quantidade máxima de candidatos a selecionar (padrão: 4).",
    )
    parser.add_argument(
        "--no-burn-subtitles",
        action="store_true",
        help="Gera o vídeo MP4 sem legenda queimada, exportando o arquivo .SRT separado.",
    )
    parser.add_argument(
        "--subtitle-font",
        type=str,
        default="Arial",
        choices=["Arial", "Segoe UI", "Verdana", "Tahoma", "Impact", "Trebuchet MS"],
        help="Fonte da legenda incorporada ao vídeo (padrão: Arial).",
    )
    parser.add_argument(
        "--subtitle-size",
        type=str,
        default="large",
        choices=["small", "medium", "large", "xlarge"],
        help="Tamanho da fonte da legenda (padrão: large).",
    )
    parser.add_argument(
        "--subtitle-position",
        type=str,
        default="bottom",
        choices=["bottom", "center", "top"],
        help="Posição vertical da legenda (padrão: bottom).",
    )
    parser.add_argument(
        "--subtitle-preset",
        type=str,
        default="clean_outline",
        choices=["clean_outline", "boxed_contrast", "minimal_shadow"],
        help="Estilo de contorno/caixa da legenda (padrão: clean_outline).",
    )
    parser.add_argument(
        "--subtitle-color",
        type=str,
        default="#FFFFFF",
        help="Cor principal do texto em hexadecimal #RRGGBB (padrão: #FFFFFF).",
    )
    parser.add_argument(
        "--subtitle-highlight-color",
        type=str,
        default="#FACC15",
        help="Cor de destaque das palavras importantes em hexadecimal #RRGGBB (padrão: #FACC15).",
    )
    parser.add_argument(
        "--process-next",
        action="store_true",
        help="Consulta e processa imediatamente a próxima tarefa autorizada na API PHP.",
    )
    parser.add_argument(
        "--project-id",
        type=int,
        default=None,
        help="Filtra um project_id específico ao usar --process-next.",
    )
    parser.add_argument(
        "--poll",
        type=int,
        default=0,
        help="Executa continuamente consultando a fila a cada N segundos.",
    )
    return parser


def main() -> int:
    parser = build_arg_parser()
    args = parser.parse_args()
    config = WorkerConfig.from_env()
    orchestrator = ClipIAWorkerOrchestrator(config)

    if args.local_file:
        sub_style = SubtitleStyleConfig(
            burn_subtitles=not bool(args.no_burn_subtitles),
            export_srt=True,
            font_family=args.subtitle_font,
            font_size=args.subtitle_size,
            position=args.subtitle_position,
            style_preset=args.subtitle_preset,
            primary_color=args.subtitle_color,
            highlight_color=args.subtitle_highlight_color,
        )
        params = ClipSelectionParams(
            min_duration_seconds=args.min_duration,
            max_duration_seconds=args.max_duration,
            target_clips_count=args.clips_count,
            subtitle_style=sub_style,
        )
        result = orchestrator.run_on_local_authorized_file(
            media_path=args.local_file,
            params=params,
            transcript_json_path=args.transcript_json,
        )
        formatted = json.dumps(result, indent=2, ensure_ascii=False)
        print(formatted)

        if args.output_json:
            out_path = Path(args.output_json).resolve()
            out_path.write_text(formatted, encoding="utf-8")
            logger.info("Resultado salvo em: %s", out_path)
        return 0

    if args.process_next:
        with acquire_single_worker_instance_lock():
            orchestrator.process_next_backend_job(specific_project_id=args.project_id)
        return 0

    if args.poll and args.poll > 0:
        interval = max(3, int(args.poll))
        with acquire_single_worker_instance_lock():
            logger.info("Iniciando modo worker único contínuo (intervalo=%ds)...", interval)
            try:
                while True:
                    orchestrator.process_next_backend_job()
                    time.sleep(interval)
            except KeyboardInterrupt:
                logger.info("Serviço encerrado pelo usuário.")
                return 0

    parser.print_help()
    return 0


if __name__ == "__main__":
    sys.exit(main())
