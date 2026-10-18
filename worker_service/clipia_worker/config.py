"""
Configurações tipadas e limites de custo/uso do serviço externo Python ClipIA.
"""

from __future__ import annotations

import os
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Literal

from dotenv import dotenv_values, load_dotenv

_PLACEHOLDER_API_KEYS = {
    "",
    "your_openai_api_key_here",
    "your_openai_api_key_here_do_not_commit",
    "sk-your-openai-api-key-here",
    "changeme",
}


def _read_windows_registry_env_var(name: str) -> str:
    """
    Lê uma variável de ambiente das chaves de Usuário (HKCU\\Environment) ou Sistema (HKLM)
    no Windows quando o terminal atual foi aberto antes da variável ser definida no sistema.
    Nunca exibe nem registra o valor lido.
    """
    if sys.platform != "win32":
        return ""
    try:
        import winreg

        for hive, subkey in (
            (winreg.HKEY_CURRENT_USER, r"Environment"),
            (
                winreg.HKEY_LOCAL_MACHINE,
                r"SYSTEM\CurrentControlSet\Control\Session Manager\Environment",
            ),
        ):
            try:
                with winreg.OpenKey(hive, subkey, 0, winreg.KEY_READ) as reg_key:
                    val, _ = winreg.QueryValueEx(reg_key, name)
                    if isinstance(val, str) and val.strip():
                        return val.strip()
            except OSError:
                continue
    except Exception:
        return ""
    return ""


def _is_valid_non_placeholder_key(value: str | None) -> bool:
    if not value:
        return False
    cleaned = value.strip()
    if not cleaned:
        return False
    if cleaned.lower() in _PLACEHOLDER_API_KEYS:
        return False
    return True


def _load_worker_environment() -> None:
    """
    Garante compatibilidade entre variáveis de ambiente do sistema operacional (Windows/Linux)
    e o arquivo opcional `worker_service/.env` via `python-dotenv`, sem jamais sobrescrever
    uma `OPENAI_API_KEY` já configurada no Windows por valores vazios ou fictícios do `.env`.
    """
    existing_os_key = os.environ.get("OPENAI_API_KEY", "").strip()
    if not _is_valid_non_placeholder_key(existing_os_key):
        win_key = _read_windows_registry_env_var("OPENAI_API_KEY")
        if _is_valid_non_placeholder_key(win_key):
            os.environ["OPENAI_API_KEY"] = win_key

    env_file = Path(__file__).resolve().parent.parent / ".env"
    if env_file.is_file():
        load_dotenv(env_file, override=False)
        # Se o .env continha um placeholder ou valor vazio e o Windows possui chave válida, restaura a do SO
        current_key = os.environ.get("OPENAI_API_KEY", "").strip()
        if not _is_valid_non_placeholder_key(current_key):
            parsed = dotenv_values(env_file)
            dotenv_key = (parsed.get("OPENAI_API_KEY") or "").strip()
            if _is_valid_non_placeholder_key(dotenv_key):
                os.environ["OPENAI_API_KEY"] = dotenv_key
            else:
                win_fallback = _read_windows_registry_env_var("OPENAI_API_KEY")
                if _is_valid_non_placeholder_key(win_fallback):
                    os.environ["OPENAI_API_KEY"] = win_fallback


_load_worker_environment()

_DEFAULT_LEDGER_PATH = str(
    Path(__file__).resolve().parent.parent.parent / "storage" / "budget_ledger.json"
)
_DEFAULT_CLIPS_DIR = str(
    Path(__file__).resolve().parent.parent.parent / "storage" / "clips"
)


def _env_bool(key: str, default: bool = False) -> bool:
    val = os.getenv(key)
    if val is None:
        return default
    return val.strip().lower() in ("1", "true", "yes", "on")


@dataclass(frozen=True)
class WorkerConfig:
    """Configuração imutável do serviço de processamento inteligente ClipIA."""

    # Integração com Backend PHP
    backend_api_url: str
    worker_shared_secret: str = field(repr=False)
    verify_tls: bool

    # faster-whisper (transcrição local)
    whisper_model: str
    whisper_device: Literal["cpu", "cuda", "auto"]
    whisper_compute_type: str
    whisper_language: str

    # Divisão da transcrição em blocos com manutenção de contexto
    chunk_window_seconds: float
    chunk_overlap_seconds: float

    # Provedor de IA e Trava de Custos (Padrão: local_heuristic e allow_paid_api=False)
    ai_provider: Literal["local_heuristic", "openai_compatible"]
    allow_paid_api: bool
    llm_base_url: str
    llm_api_key: str = field(repr=False)
    llm_model: str

    # Limites estritos de consumo técnico
    max_audio_duration_seconds: int
    max_llm_calls_per_job: int
    max_llm_tokens_per_job: int
    max_retries_per_task: int

    # Limites financeiros (Orçamento por Tarefa e Teto Mensal de R$ 100,00)
    max_cost_per_job_brl: float = 2.50
    max_monthly_cost_brl: float = 100.00
    usd_to_brl_rate: float = 5.80
    input_cost_per_1m_usd: float = 0.15
    output_cost_per_1m_usd: float = 0.60
    min_cost_per_call_brl: float = 0.02
    llm_timeout_seconds: float = 30.0
    budget_ledger_path: str = _DEFAULT_LEDGER_PATH

    # Renderização e corte físico com FFmpeg e proteção de disco
    ffmpeg_binary: str = ""
    clips_output_dir: str = _DEFAULT_CLIPS_DIR
    render_clips_with_ffmpeg: bool = True
    min_free_disk_mb: int = 100

    @property
    def openai_api_key(self) -> str:
        """Alias semântico para a chave configurada via OPENAI_API_KEY."""
        return self.llm_api_key

    @property
    def has_openai_api_key(self) -> bool:
        """Indica de forma segura (booleana) se há chave configurada, sem expor seu conteúdo."""
        return _is_valid_non_placeholder_key(self.llm_api_key)

    @classmethod
    def from_env(cls) -> "WorkerConfig":
        raw_device = os.getenv("CLIPIA_WHISPER_DEVICE", "cpu").strip().lower()
        device: Literal["cpu", "cuda", "auto"] = (
            raw_device if raw_device in ("cpu", "cuda", "auto") else "cpu"  # type: ignore[assignment]
        )

        raw_provider = os.getenv("CLIPIA_AI_PROVIDER", "local_heuristic").strip().lower()
        provider: Literal["local_heuristic", "openai_compatible"] = (
            "openai_compatible" if raw_provider == "openai_compatible" else "local_heuristic"
        )

        configured_model = os.getenv("CLIPIA_LLM_MODEL", "gpt-4o-mini").strip()
        if not configured_model:
            configured_model = "gpt-4o-mini"

        # Prioriza OPENAI_API_KEY via os.getenv / os.environ, com fallback para CLIPIA_LLM_API_KEY
        raw_openai_key = (os.getenv("OPENAI_API_KEY") or os.environ.get("OPENAI_API_KEY") or "").strip()
        if not _is_valid_non_placeholder_key(raw_openai_key):
            raw_openai_key = (os.getenv("CLIPIA_LLM_API_KEY") or os.environ.get("CLIPIA_LLM_API_KEY") or "").strip()
        if not _is_valid_non_placeholder_key(raw_openai_key):
            raw_openai_key = ""

        return cls(
            backend_api_url=os.getenv(
                "CLIPIA_BACKEND_API_URL",
                "http://127.0.0.1:8099/pages/worker-api.php",
            ).strip(),
            worker_shared_secret=os.getenv(
                "CLIPIA_WORKER_SHARED_SECRET",
                "clipia-dev-hmac-secret-key-change-in-production-2026",
            ).strip(),
            verify_tls=_env_bool("CLIPIA_VERIFY_TLS", True),
            whisper_model=os.getenv("CLIPIA_WHISPER_MODEL", "base").strip(),
            whisper_device=device,
            whisper_compute_type=os.getenv("CLIPIA_WHISPER_COMPUTE_TYPE", "int8").strip(),
            whisper_language=os.getenv("CLIPIA_WHISPER_LANGUAGE", "pt").strip(),
            chunk_window_seconds=max(45.0, float(os.getenv("CLIPIA_CHUNK_WINDOW_SECONDS", "120"))),
            chunk_overlap_seconds=max(10.0, float(os.getenv("CLIPIA_CHUNK_OVERLAP_SECONDS", "25"))),
            ai_provider=provider,
            allow_paid_api=_env_bool("CLIPIA_ALLOW_PAID_API", False),
            llm_base_url=os.getenv("CLIPIA_LLM_BASE_URL", "https://api.openai.com/v1").strip().rstrip("/"),
            llm_api_key=raw_openai_key,
            llm_model=configured_model,
            max_audio_duration_seconds=max(60, int(os.getenv("CLIPIA_MAX_AUDIO_DURATION_SECONDS", "5400"))),
            max_llm_calls_per_job=max(1, int(os.getenv("CLIPIA_MAX_LLM_CALLS_PER_JOB", "12"))),
            max_llm_tokens_per_job=max(500, int(os.getenv("CLIPIA_MAX_LLM_TOKENS_PER_JOB", "24000"))),
            max_retries_per_task=max(0, int(os.getenv("CLIPIA_MAX_RETRIES_PER_TASK", "2"))),
            max_cost_per_job_brl=max(0.05, float(os.getenv("CLIPIA_MAX_COST_PER_JOB_BRL", "2.50"))),
            max_monthly_cost_brl=max(1.0, float(os.getenv("CLIPIA_MAX_MONTHLY_COST_BRL", "100.00"))),
            usd_to_brl_rate=max(1.0, float(os.getenv("CLIPIA_USD_TO_BRL_RATE", "5.80"))),
            input_cost_per_1m_usd=max(0.01, float(os.getenv("CLIPIA_INPUT_COST_PER_1M_USD", "0.15"))),
            output_cost_per_1m_usd=max(0.01, float(os.getenv("CLIPIA_OUTPUT_COST_PER_1M_USD", "0.60"))),
            min_cost_per_call_brl=max(0.005, float(os.getenv("CLIPIA_MIN_COST_PER_CALL_BRL", "0.02"))),
            llm_timeout_seconds=max(5.0, float(os.getenv("CLIPIA_LLM_TIMEOUT_SECONDS", "30.0"))),
            budget_ledger_path=os.getenv("CLIPIA_BUDGET_LEDGER_PATH", _DEFAULT_LEDGER_PATH).strip(),
            ffmpeg_binary=os.getenv("CLIPIA_FFMPEG_BINARY", "").strip(),
            clips_output_dir=os.getenv("CLIPIA_CLIPS_OUTPUT_DIR", _DEFAULT_CLIPS_DIR).strip(),
            render_clips_with_ffmpeg=_env_bool("CLIPIA_RENDER_CLIPS", True),
            min_free_disk_mb=max(10, int(os.getenv("CLIPIA_MIN_FREE_DISK_MB", "100"))),
        )
