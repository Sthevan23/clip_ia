"""
Cliente HTTP autenticado entre o Serviço Externo Python e a API PHP (`pages/worker-api.php`).

Garante:
- Autenticação HMAC-SHA256 com timestamp (`X-ClipIA-Timestamp` e `X-ClipIA-Signature`).
- Validação de HTTPS para servidores remotos.
- Suporte para consultar tarefas, baixar a fonte autorizada caso o worker esteja em outra máquina,
  enviar candidatos validados e reportar erros permitindo repetição.
"""

from __future__ import annotations

import base64
import json
import logging
import shutil
import tempfile
from pathlib import Path
from typing import Any

import httpx

from .config import WorkerConfig
from .models import ClipCandidate, UsageRecord
from .security import build_hmac_headers, validate_backend_api_url

logger = logging.getLogger("clipia.api_client")


class BackendApiClient:
    """Cliente seguro de comunicação com o backend PHP do ClipIA."""

    def __init__(self, config: WorkerConfig) -> None:
        self.config = config
        self.api_url = validate_backend_api_url(config.backend_api_url, require_https_for_remote=True)

    def _post_signed_json(self, payload: dict[str, Any]) -> dict[str, Any]:
        raw_body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        headers = build_hmac_headers(self.config.worker_shared_secret, raw_body)

        with httpx.Client(timeout=45.0, verify=self.config.verify_tls) as client:
            response = client.post(self.api_url, content=raw_body, headers=headers)

        try:
            data = response.json()
        except Exception as exc:
            raise RuntimeError(
                f"Resposta inválida da API PHP (HTTP {response.status_code}): {response.text[:240]}"
            ) from exc

        if response.status_code >= 400 or not data.get("ok", False):
            msg = data.get("message", f"Erro HTTP {response.status_code}")
            raise RuntimeError(f"API PHP recusou a operação: {msg}")

        return data

    def claim_next_job(self, specific_project_id: int | None = None) -> dict[str, Any] | None:
        payload: dict[str, Any] = {"action": "claim_next_job"}
        if specific_project_id is not None and specific_project_id > 0:
            payload["project_id"] = int(specific_project_id)

        data = self._post_signed_json(payload)
        if not data.get("has_job"):
            return None
        return data.get("job")

    def download_authorized_source_to_temp(self, job_id: int, project_id: int, original_name: str) -> Path:
        """
        Baixa o arquivo de vídeo autorizado diretamente do armazenamento protegido do backend PHP
        quando o serviço Python está executando em uma máquina separada.
        """
        payload = {
            "action": "download_authorized_source",
            "job_id": int(job_id),
            "project_id": int(project_id),
        }
        raw_body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        headers = build_hmac_headers(self.config.worker_shared_secret, raw_body)

        suffix = Path(original_name).suffix.lower() or ".mp4"
        tmp_dir = Path(tempfile.gettempdir())
        disk = shutil.disk_usage(str(tmp_dir))
        min_free = max(10, int(self.config.min_free_disk_mb)) * 1024 * 1024
        if disk.free < min_free:
            raise OSError(
                f"Espaço livre em disco insuficiente no diretório temporário ({disk.free // (1024 * 1024)} MB livres)."
            )

        tmp_file = tempfile.NamedTemporaryFile(prefix=f"clipia_job_{job_id}_", suffix=suffix, delete=False)
        tmp_path = Path(tmp_file.name)
        tmp_file.close()

        try:
            with httpx.Client(timeout=180.0, verify=self.config.verify_tls) as client:
                resp = client.post(self.api_url, content=raw_body, headers=headers)
                if resp.status_code != 200:
                    tmp_path.unlink(missing_ok=True)
                    raise RuntimeError(
                        f"Falha ao baixar fonte autorizada (HTTP {resp.status_code}): {resp.text[:200]}"
                    )
                tmp_path.write_bytes(resp.content)
        except Exception:
            tmp_path.unlink(missing_ok=True)
            raise

        return tmp_path

    def upload_rendered_clip(
        self,
        job_id: int,
        project_id: int,
        clip_index: int,
        clip_file_path: str | Path,
        srt_content: str = "",
    ) -> tuple[str, str | None]:
        """
        Envia um clipe MP4 cortado pelo FFmpeg (e opcionalmente o arquivo .SRT sincronizado)
        para o armazenamento protegido do backend PHP (`storage/clips`),
        retornando `(storage_url, srt_storage_url)` registrados pelo servidor.
        """
        resolved_clip = Path(clip_file_path).expanduser().resolve()
        if not resolved_clip.is_file() or resolved_clip.stat().st_size <= 0:
            raise FileNotFoundError(f"Arquivo de clipe renderizado inválido ou inexistente: {resolved_clip}")

        clip_bytes = resolved_clip.read_bytes()
        payload: dict[str, Any] = {
            "action": "upload_rendered_clip",
            "job_id": int(job_id),
            "project_id": int(project_id),
            "clip_index": int(clip_index),
            "clip_filename": resolved_clip.name,
            "clip_base64": base64.b64encode(clip_bytes).decode("ascii"),
        }
        if srt_content.strip():
            payload["srt_content"] = srt_content
        data = self._post_signed_json(payload)
        storage_url = str(data.get("storage_url") or "")
        if not storage_url:
            raise RuntimeError("A API PHP não retornou o storage_url do clipe enviado.")
        srt_url = str(data.get("srt_storage_url") or "") or None
        return storage_url, srt_url

    def complete_job(
        self,
        job_id: int,
        project_id: int,
        candidates: list[ClipCandidate],
        usage: UsageRecord,
    ) -> dict[str, Any]:
        payload = {
            "action": "complete_job",
            "job_id": int(job_id),
            "project_id": int(project_id),
            "candidates": [c.model_dump() for c in candidates],
            "usage_metrics": usage.model_dump(),
        }
        return self._post_signed_json(payload)

    def fail_job(self, job_id: int, project_id: int, error_message: str) -> dict[str, Any]:
        payload = {
            "action": "fail_job",
            "job_id": int(job_id),
            "project_id": int(project_id),
            "error_message": str(error_message)[:480],
        }
        return self._post_signed_json(payload)

    def reserve_budget(
        self,
        job_id: int,
        project_id: int,
        reserve_brl: float,
        max_monthly_brl: float | None = None,
        max_job_brl: float | None = None,
    ) -> dict[str, Any]:
        """
        Reserva atomicamente uma cota de custo no banco de dados compartilhado antes de chamar a API paga.
        """
        payload: dict[str, Any] = {
            "action": "reserve_budget",
            "job_id": int(job_id),
            "project_id": int(project_id),
            "reserve_brl": round(float(reserve_brl), 4),
        }
        if max_monthly_brl is not None:
            payload["max_monthly_brl"] = round(float(max_monthly_brl), 4)
        if max_job_brl is not None:
            payload["max_job_brl"] = round(float(max_job_brl), 4)
        return self._post_signed_json(payload)

    def settle_budget(
        self,
        reservation_token: str,
        actual_cost_brl: float,
        prompt_tokens: int = 0,
        completion_tokens: int = 0,
        release_only: bool = False,
    ) -> dict[str, Any]:
        """
        Liquida (`settled`) ou libera (`released`) uma reserva de orçamento no banco de dados compartilhado.
        """
        payload = {
            "action": "settle_budget",
            "reservation_token": str(reservation_token),
            "actual_cost_brl": round(float(actual_cost_brl), 4),
            "prompt_tokens": int(prompt_tokens),
            "completion_tokens": int(completion_tokens),
            "release_only": bool(release_only),
        }
        return self._post_signed_json(payload)

