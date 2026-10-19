#!/usr/bin/env python3
"""
Verificação local do worker ClipIA — sem HTTP, sem claim_next_job, sem segredos na saída.

Uso (na pasta worker_service):
  python verify_local_config.py
"""
from __future__ import annotations

import os
import shutil
import sys
from pathlib import Path
from urllib.parse import urlparse

from dotenv import dotenv_values

from clipia_worker.config import (
    WorkerConfig,
    _is_valid_non_placeholder_key,
    _read_windows_registry_env_var,
)
from clipia_worker.cutter import FFmpegClipCutter
from clipia_worker.security import build_hmac_headers, validate_backend_api_url

_DEV_HMAC_FALLBACK = "clipia-dev-hmac-secret-key-change-in-production-2026"


def diagnose_worker_shared_secret() -> dict[str, str | bool | int]:
    """
    Explica por que hmac_ready pode ser False sem expor o segredo.
    Se .env contém CLIPIA_WORKER_SHARED_SECRET= (vazio), python-dotenv define os.environ
    com string vazia e WorkerConfig.from_env() não aplica o fallback de desenvolvimento.
    """
    env_file = Path(__file__).resolve().parent / ".env"
    in_os_environ = "CLIPIA_WORKER_SHARED_SECRET" in os.environ
    os_len = len((os.environ.get("CLIPIA_WORKER_SHARED_SECRET") or "").strip())

    dotenv_has_key = False
    dotenv_len = 0
    if env_file.is_file():
        parsed = dotenv_values(env_file)
        if "CLIPIA_WORKER_SHARED_SECRET" in parsed:
            dotenv_has_key = True
            dotenv_len = len((parsed.get("CLIPIA_WORKER_SHARED_SECRET") or "").strip())

    if os_len >= 16:
        status = "configured"
        hint = ""
    elif dotenv_has_key and dotenv_len == 0:
        status = "empty_in_dotenv"
        hint = (
            "No worker_service/.env a linha CLIPIA_WORKER_SHARED_SECRET está vazia. "
            "Preencha com o mesmo WORKER_SHARED_SECRET do private/clipia.env.php na Hostinger "
            "(ou remova a linha so para teste local com backend 127.0.0.1; com HTTPS remoto e obrigatorio preencher)."
        )
    elif in_os_environ and os_len == 0:
        status = "empty_in_environment"
        hint = (
            "CLIPIA_WORKER_SHARED_SECRET está definida no ambiente do Windows, porém vazia. "
            "Preencha no .env ou nas variáveis de usuário do sistema."
        )
    else:
        status = "missing_or_short"
        hint = (
            "Defina CLIPIA_WORKER_SHARED_SECRET no worker_service/.env "
            "(mín. 16 caracteres, igual a WORKER_SHARED_SECRET no PHP)."
        )

    using_dev_fallback = os_len >= 16 and os.environ.get("CLIPIA_WORKER_SHARED_SECRET", "").strip() == _DEV_HMAC_FALLBACK

    return {
        "status": status,
        "hint": hint,
        "env_file_exists": env_file.is_file(),
        "dotenv_has_key": dotenv_has_key,
        "secret_length": os_len,
        "using_dev_fallback": using_dev_fallback,
    }


def diagnose_openai_api_key() -> dict[str, str | bool | int]:
    """Origem da chave OpenAI efetiva, sem expor valor."""
    env_file = Path(__file__).resolve().parent / ".env"
    dotenv_raw = ""
    if env_file.is_file():
        parsed = dotenv_values(env_file)
        dotenv_raw = (parsed.get("OPENAI_API_KEY") or "").strip()

    registry_raw = _read_windows_registry_env_var("OPENAI_API_KEY")
    environ_raw = (os.environ.get("OPENAI_API_KEY") or "").strip()

    dotenv_valid = _is_valid_non_placeholder_key(dotenv_raw)
    registry_valid = _is_valid_non_placeholder_key(registry_raw)
    environ_valid = _is_valid_non_placeholder_key(environ_raw)

    if environ_valid and dotenv_valid and environ_raw == dotenv_raw:
        source = "dotenv_file"
    elif environ_valid and registry_valid and environ_raw == registry_raw.strip():
        source = "windows_registry"
    elif environ_valid:
        source = "process_environment"
    elif dotenv_valid:
        source = "dotenv_file"
    elif registry_valid:
        source = "windows_registry"
    else:
        source = "none"

    dotenv_is_example_placeholder = dotenv_raw.lower() in (
        "your_openai_api_key_here_do_not_commit",
        "your_openai_api_key_here",
        "sk-your-openai-api-key-here",
        "changeme",
    )

    return {
        "source": source,
        "dotenv_is_example_placeholder": dotenv_is_example_placeholder,
        "dotenv_passes_valid_check": dotenv_valid,
        "registry_passes_valid_check": registry_valid,
        "effective_passes_valid_check": environ_valid or dotenv_valid or registry_valid,
    }


def diagnose_ffprobe(ffmpeg_bin: str | None) -> dict[str, bool | str]:
    on_path = shutil.which("ffprobe") is not None
    next_to_ffmpeg = False
    if ffmpeg_bin:
        probe_name = "ffprobe.exe" if os.name == "nt" else "ffprobe"
        probe_path = Path(ffmpeg_bin).resolve().parent / probe_name
        next_to_ffmpeg = probe_path.is_file()
    if on_path:
        status = "on_path"
    elif next_to_ffmpeg:
        status = "next_to_ffmpeg_not_on_path"
    else:
        status = "missing"
    return {
        "on_path": on_path,
        "next_to_ffmpeg": next_to_ffmpeg,
        "usable_for_clipia": on_path or next_to_ffmpeg,
        "status": status,
    }


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

    secret_diag = diagnose_worker_shared_secret()
    secret_len = len(cfg.worker_shared_secret.strip())
    hmac_ready = secret_len >= 16
    if not hmac_ready:
        hint = str(secret_diag.get("hint") or "")
        if hint:
            warnings.append(hint)
        else:
            warnings.append(
                "CLIPIA_WORKER_SHARED_SECRET ausente ou curto (<16). "
                "Preencha com o mesmo valor de WORKER_SHARED_SECRET do PHP (Hostinger)."
            )
    elif secret_diag.get("using_dev_fallback"):
        warnings.append(
            "Segredo HMAC é o fallback de desenvolvimento do .env.example. "
            "Substitua pelo mesmo WORKER_SHARED_SECRET configurado na Hostinger antes de processar jobs reais."
        )

    openai_diag = diagnose_openai_api_key()
    if cfg.has_openai_api_key and cfg.allow_paid_api:
        warnings.append("Chave OpenAI detectada no ambiente; mantenha CLIPIA_ALLOW_PAID_API=false.")
    elif cfg.has_openai_api_key and not cfg.allow_paid_api:
        if openai_diag.get("source") == "windows_registry" and openai_diag.get(
            "dotenv_is_example_placeholder"
        ):
            warnings.append(
                "OPENAI_API_KEY efetiva vem do Registro do Windows (Usuario/Sistema); "
                "o .env ainda tem placeholder de exemplo. APIs pagas permanecem bloqueadas "
                "(CLIPIA_ALLOW_PAID_API=false). Para evitar confusao, remova OPENAI_API_KEY "
                "do Registro ou deixe vazio no .env sem chave real."
            )

    ffmpeg_bin: str | None = None
    try:
        ffmpeg_bin = FFmpegClipCutter(cfg).resolve_ffmpeg_binary()
        ffmpeg_ok = bool(ffmpeg_bin)
    except RuntimeError as exc:
        ffmpeg_ok = False
        warnings.append(str(exc))

    ffprobe_diag = diagnose_ffprobe(ffmpeg_bin)
    ffmpeg_from_imageio = bool(
        ffmpeg_bin and "imageio_ffmpeg" in ffmpeg_bin.replace("\\", "/").lower()
    )
    # O worker usa apenas ffmpeg no corte (cutter.py); ffprobe nao e invocado no codigo atual.
    if ffmpeg_ok and not ffprobe_diag["usable_for_clipia"]:
        if ffmpeg_from_imageio:
            warnings.append(
                "ffprobe ausente: ffmpeg veio do pacote imageio-ffmpeg (sem ffprobe no mesmo diretorio). "
                "Isso e esperado; testes de corte usam so ffmpeg. Para ffprobe no PATH, instale FFmpeg completo "
                "e opcionalmente defina CLIPIA_FFMPEG_BINARY no .env."
            )
        else:
            warnings.append(
                "ffprobe nao encontrado no PATH nem ao lado do ffmpeg resolvido. "
                "Opcional para o ClipIA atual (corte usa ffmpeg). Recomendado instalar FFmpeg completo no PATH."
            )
    elif ffprobe_diag["status"] == "next_to_ffmpeg_not_on_path":
        warnings.append(
            "ffprobe existe ao lado do ffmpeg, mas nao esta no PATH global. "
            "Opcional; adicione a pasta bin ao PATH se outras ferramentas precisarem."
        )

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

    print("=== ClipIA Worker - verify_local_config (offline) ===")
    # Nunca imprimir segredos: apenas URL publica da API e comprimento do HMAC.
    print(f"backend_api_host={urlparse(api_url).netloc or '(vazio)'}")
    print(f"backend_api_path={urlparse(api_url).path or '(vazio)'}")
    print(f"verify_tls={cfg.verify_tls}")
    print(f"allow_paid_api={cfg.allow_paid_api}")
    print(f"ai_provider={cfg.ai_provider}")
    print(f"hmac_ready={hmac_ready}")
    print(f"hmac_secret_length={secret_len}")
    print(f"hmac_secret_status={secret_diag['status']}")
    print(f"env_file_exists={secret_diag['env_file_exists']}")
    print(f"dotenv_has_CLIPIA_WORKER_SHARED_SECRET_key={secret_diag['dotenv_has_key']}")
    print(f"has_openai_api_key={cfg.has_openai_api_key}")
    print(f"openai_key_source={openai_diag['source']}")
    print(f"dotenv_openai_is_example_placeholder={openai_diag['dotenv_is_example_placeholder']}")
    print(f"whisper_model={cfg.whisper_model}")
    print(f"ffmpeg_resolved={ffmpeg_ok}")
    print(f"ffmpeg_from_imageio_bundle={ffmpeg_from_imageio}")
    print(f"ffprobe_on_path={ffprobe_diag['on_path']}")
    print(f"ffprobe_next_to_ffmpeg={ffprobe_diag['next_to_ffmpeg']}")
    print(f"ffprobe_status={ffprobe_diag['status']}")
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
