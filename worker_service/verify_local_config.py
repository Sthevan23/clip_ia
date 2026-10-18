#!/usr/bin/env python3
"""
Verificação local do worker ClipIA — sem HTTP, sem claim_next_job, sem segredos na saída.

Uso (na pasta worker_service):
  python verify_local_config.py
"""
from __future__ import annotations

import shutil
import sys
from urllib.parse import urlparse

from clipia_worker.config import WorkerConfig
from clipia_worker.cutter import FFmpegClipCutter
from clipia_worker.security import build_hmac_headers, validate_backend_api_url


def main() -> int:
    cfg = WorkerConfig.from_env()
    errors: list[str] = []
    warnings: list[str] = []

    if cfg.allow_paid_api:
        errors.append("CLIPIA_ALLOW_PAID_API deve ser false para processamento gratuito.")
    if cfg.ai_provider != "local_heuristic":
        errors.append("CLIPIA_AI_PROVIDER deve ser local_heuristic para processamento gratuito.")

    api_url = cfg.backend_api_url.strip()
    if not api_url:
        errors.append("CLIPIA_BACKEND_API_URL está vazio.")
    else:
        try:
            validate_backend_api_url(api_url, require_https_for_remote=True)
        except PermissionError as exc:
            errors.append(str(exc))
        parsed = urlparse(api_url)
        if parsed.path.rstrip("/") != "/pages/worker-api.php":
            warnings.append(
                "CLIPIA_BACKEND_API_URL deve apontar para .../pages/worker-api.php "
                f"(atual: {parsed.path})."
            )

    if not cfg.verify_tls:
        warnings.append("CLIPIA_VERIFY_TLS=false desativa validação de certificado em hosts remotos.")

    secret_len = len(cfg.worker_shared_secret.strip())
    hmac_ready = secret_len >= 16
    if not hmac_ready:
        warnings.append(
            "CLIPIA_WORKER_SHARED_SECRET ausente ou curto (<16). "
            "Preencha com o mesmo valor de WORKER_SHARED_SECRET do PHP (Hostinger)."
        )

    if cfg.has_openai_api_key and cfg.allow_paid_api:
        warnings.append("Chave OpenAI detectada no ambiente; mantenha CLIPIA_ALLOW_PAID_API=false.")

    try:
        ffmpeg_bin = FFmpegClipCutter(cfg).resolve_ffmpeg_binary()
        ffmpeg_ok = bool(ffmpeg_bin)
    except RuntimeError as exc:
        ffmpeg_ok = False
        warnings.append(str(exc))

    ffprobe_ok = shutil.which("ffprobe") is not None

    hmac_self_test_ok = False
    try:
        probe_secret = "offline-verify-local-config-secret"
        body = b'{"action":"claim_next_job"}'
        headers = build_hmac_headers(probe_secret, body)
        hmac_self_test_ok = (
            len(headers.get("X-ClipIA-Signature", "")) == 64
            and headers.get("X-ClipIA-Timestamp", "").isdigit()
        )
    except ValueError:
        hmac_self_test_ok = False

    print("=== ClipIA Worker — verify_local_config (offline) ===")
    print(f"backend_api_url={api_url}")
    print(f"verify_tls={cfg.verify_tls}")
    print(f"allow_paid_api={cfg.allow_paid_api}")
    print(f"ai_provider={cfg.ai_provider}")
    print(f"hmac_ready={hmac_ready}")
    print(f"hmac_secret_length={secret_len}")
    print(f"has_openai_api_key={cfg.has_openai_api_key}")
    print(f"whisper_model={cfg.whisper_model}")
    print(f"ffmpeg_resolved={ffmpeg_ok}")
    print(f"ffprobe_on_path={ffprobe_ok}")
    print(f"hmac_self_test_ok={hmac_self_test_ok}")

    for w in warnings:
        print(f"AVISO: {w}")
    for e in errors:
        print(f"ERRO: {e}")

    if errors:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
