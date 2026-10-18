"""
Segurança entre serviços e validação estrita de fontes autorizadas.

Regras:
1. NUNCA processa URLs arbitrárias recebidas de usuários.
2. Aceita apenas arquivos locais autorizados (.mp4, .mov, .webm, .wav, .mp3, .m4a)
   ou transcrições JSON já autorizadas.
3. Assina todas as chamadas ao backend PHP usando HMAC-SHA256 com timestamp anti-replay.
"""

from __future__ import annotations

import hashlib
import hmac
import logging
import re
import time
from pathlib import Path
from typing import Iterable
from urllib.parse import urlparse

ALLOWED_LOCAL_MEDIA_EXTENSIONS = {".mp4", ".mov", ".webm", ".wav", ".mp3", ".m4a"}

_SENSITIVE_PATTERNS = [
    re.compile(r"(Bearer\s+)[A-Za-z0-9_\-\.~+/]+=*", re.IGNORECASE),
    re.compile(r"\bsk-[A-Za-z0-9_\-]{12,}\b"),
    re.compile(r"(X-ClipIA-Signature['\"]?\s*[:=]\s*['\"]?)[0-9a-fA-F]{32,}", re.IGNORECASE),
    re.compile(r"(worker_shared_secret['\"]?\s*[:=]\s*['\"]?)[^'\"\s,;]+", re.IGNORECASE),
    re.compile(r"((?:openai_api_key|llm_api_key)['\"]?\s*[:=]\s*['\"]?)[^'\"\s,;]+", re.IGNORECASE),
]


def redact_sensitive_text(text: str, extra_secrets: Iterable[str] | None = None) -> str:
    """
    Remove qualquer chave de API, token Bearer ou segredo HMAC de mensagens de erro e logs.
    """
    if not text:
        return ""
    sanitized = str(text)
    if extra_secrets:
        for secret in extra_secrets:
            if secret and len(secret.strip()) >= 8:
                sanitized = sanitized.replace(secret.strip(), "[REDACTED_SECRET]")

    sanitized = _SENSITIVE_PATTERNS[0].sub(r"\1[REDACTED_KEY]", sanitized)
    sanitized = _SENSITIVE_PATTERNS[1].sub("[REDACTED_API_KEY]", sanitized)
    sanitized = _SENSITIVE_PATTERNS[2].sub(r"\1[REDACTED_SIGNATURE]", sanitized)
    sanitized = _SENSITIVE_PATTERNS[3].sub(r"\1[REDACTED_SECRET]", sanitized)
    sanitized = _SENSITIVE_PATTERNS[4].sub(r"\1[REDACTED_KEY]", sanitized)
    return sanitized


class SensitiveDataLogFilter(logging.Filter):
    """Filtro de logging que impede o registro acidental de chaves de API ou segredos HMAC."""

    def __init__(self, secrets: Iterable[str] | None = None) -> None:
        super().__init__()
        self._secrets = [s.strip() for s in (secrets or []) if s and len(s.strip()) >= 8]

    def filter(self, record: logging.LogRecord) -> bool:
        if record.args:
            try:
                rendered = record.getMessage()
            except Exception:
                rendered = str(record.msg)
            record.msg = redact_sensitive_text(rendered, self._secrets)
            record.args = ()
        else:
            record.msg = redact_sensitive_text(str(record.msg), self._secrets)
        return True


class UnauthorizedSourceError(PermissionError):
    """Exceção disparada quando uma fonte não é autorizada ou tenta passar URL arbitrária."""


def validate_authorized_local_media_file(
    file_path: str | Path,
    authorized_confirmed: bool = True,
) -> Path:
    """
    Garante que a fonte recebida NÃO seja uma URL remota arbitrária e que seja
    um arquivo local existente, legível, com extensão de mídia permitida e autorizado.
    """
    if not authorized_confirmed:
        raise UnauthorizedSourceError(
            "Processamento bloqueado: a fonte de vídeo não possui confirmação de uso autorizado."
        )

    raw_str = str(file_path).strip()
    if not raw_str:
        raise UnauthorizedSourceError("Caminho do arquivo de mídia autorizado está vazio.")

    # Bloqueia estritamente qualquer URL (http://, https://, ftp://, file://, etc.)
    if "://" in raw_str or raw_str.lower().startswith(("http:", "https:", "ftp:")):
        raise UnauthorizedSourceError(
            "Política de segurança ClipIA: o serviço Python não processa nem baixa URLs arbitrárias. "
            "Forneça apenas um arquivo local autorizado."
        )

    resolved = Path(raw_str).expanduser().resolve()
    if not resolved.exists() or not resolved.is_file():
        raise FileNotFoundError(f"Arquivo de mídia autorizado não encontrado: {resolved}")

    ext = resolved.suffix.lower()
    if ext not in ALLOWED_LOCAL_MEDIA_EXTENSIONS:
        raise UnauthorizedSourceError(
            f"Extensão '{ext}' não permitida. Formatos autorizados: {sorted(ALLOWED_LOCAL_MEDIA_EXTENSIONS)}"
        )

    if resolved.stat().st_size <= 0:
        raise ValueError(f"O arquivo de mídia '{resolved.name}' está vazio (0 bytes).")

    return resolved


def validate_backend_api_url(api_url: str, require_https_for_remote: bool = True) -> str:
    """
    Valida a URL da API do backend PHP.
    Exige HTTPS para servidores remotos (Hostinger), permitindo HTTP apenas em loopback local (127.0.0.1 / localhost).
    """
    parsed = urlparse(api_url.strip())
    if parsed.scheme not in ("http", "https") or not parsed.hostname:
        raise ValueError(f"URL da API do backend inválida: {api_url}")

    host = parsed.hostname.lower()
    is_loopback = host in ("127.0.0.1", "localhost", "::1")

    if require_https_for_remote and not is_loopback and parsed.scheme != "https":
        raise PermissionError(
            f"Comunicação insegura bloqueada para '{host}': utilize obrigatoriamente HTTPS para conectar ao backend em produção."
        )

    return api_url.strip()


def build_hmac_headers(shared_secret: str, raw_body: bytes) -> dict[str, str]:
    """
    Gera os cabeçalhos de autenticação HMAC-SHA256 (`X-ClipIA-Timestamp` e `X-ClipIA-Signature`)
    compatíveis com `ExternalWorkerClient::verifyIncomingRequest` no PHP.
    """
    if not shared_secret or len(shared_secret.strip()) < 16:
        raise ValueError("CLIPIA_WORKER_SHARED_SECRET deve ter pelo menos 16 caracteres.")

    timestamp = str(int(time.time()))
    signing_payload = timestamp.encode("utf-8") + b"." + raw_body
    signature = hmac.new(
        shared_secret.encode("utf-8"),
        signing_payload,
        hashlib.sha256,
    ).hexdigest()

    return {
        "Content-Type": "application/json; charset=UTF-8",
        "X-ClipIA-Timestamp": timestamp,
        "X-ClipIA-Signature": signature,
    }
