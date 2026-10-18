"""
Gerenciador Persistente de Orçamento e Controle de Custos (`budget.py`).

Garante que:
1. O teto mensal da aplicação (padrão R$ 100,00) e o limite máximo por tarefa sejam
   aplicados de forma persistente em disco (`storage/budget_ledger.json`), sobrevivendo
   a reinicializações do worker Python.
2. O controle de limites NÃO dependa exclusivamente de estimativas de tokens:
   - Utiliza o custo reportado diretamente pelo provedor quando disponível (`usage.cost`,
     `usage.total_cost_usd` ou cabeçalhos de custo).
   - Caso o provedor informe apenas tokens, calcula o custo com base nas tarifas configuradas
     e aplica sempre um piso mínimo de segurança por chamada (`min_cost_per_call_brl`),
     garantindo que nenhuma chamada tenha custo zero mesmo que o provedor omita `usage`.
3. Permite sincronizar de forma conservadora com o gasto mensal registrado no MySQL/SQLite
   pelo backend PHP.
"""

from __future__ import annotations

from contextlib import contextmanager
import json
import os
import tempfile
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator

from .config import WorkerConfig


class BudgetExceededError(PermissionError):
    """Exceção disparada quando o limite por tarefa ou o teto mensal (R$ 100) é atingido."""


class PersistentBudgetGuard:
    """
    Controla e persiste de forma atômica o consumo mensal de IA (suportando múltiplos processos
    via lock de arquivo cross-platform + reserva atômica compartilhada no banco MySQL/SQLite via API PHP)
    para impedir que o orçamento mensal (ex.: R$ 100,00) ou o limite por tarefa seja ultrapassado.
    """

    def __init__(self, config: WorkerConfig) -> None:
        self.config = config
        self.ledger_path = Path(config.budget_ledger_path).expanduser().resolve()
        self.ledger_path.parent.mkdir(parents=True, exist_ok=True)
        self._lock_path = self.ledger_path.with_suffix(".lock")

    @staticmethod
    def current_month_key() -> str:
        return datetime.now(timezone.utc).strftime("%Y-%m")

    @contextmanager
    def _interprocess_lock(self, timeout_sec: float = 10.0) -> Iterator[None]:
        """
        Lock exclusivo entre processos/threads para proteger leituras-modificações-escritas
        no ledger local quando múltiplos workers compartilham o mesmo disco.
        """
        self.ledger_path.parent.mkdir(parents=True, exist_ok=True)
        deadline = time.monotonic() + timeout_sec
        lock_fd: int | None = None

        while True:
            try:
                lock_fd = os.open(str(self._lock_path), os.O_CREAT | os.O_EXCL | os.O_RDWR)
                os.write(lock_fd, f"{os.getpid()}:{time.time()}".encode("utf-8"))
                break
            except FileExistsError:
                # Remove lock órfão caso tenha mais de 30s
                try:
                    if self._lock_path.exists() and (time.time() - self._lock_path.stat().st_mtime) > 30.0:
                        self._lock_path.unlink(missing_ok=True)
                        continue
                except OSError:
                    pass
                if time.monotonic() >= deadline:
                    raise TimeoutError("Timeout ao aguardar lock exclusivo do ledger de orçamento.")
                time.sleep(0.015)

        try:
            yield
        finally:
            if lock_fd is not None:
                try:
                    os.close(lock_fd)
                except OSError:
                    pass
            try:
                self._lock_path.unlink(missing_ok=True)
            except OSError:
                pass

    def _load_ledger_unlocked(self) -> dict[str, Any]:
        if not self.ledger_path.is_file():
            return {"months": {}, "reservations": {}}
        try:
            raw = json.loads(self.ledger_path.read_text(encoding="utf-8"))
            if isinstance(raw, dict) and isinstance(raw.get("months"), dict):
                raw.setdefault("reservations", {})
                return raw
        except Exception:
            pass
        return {"months": {}, "reservations": {}}

    def _save_ledger_atomic_unlocked(self, data: dict[str, Any]) -> None:
        self.ledger_path.parent.mkdir(parents=True, exist_ok=True)
        fd, tmp_name = tempfile.mkstemp(
            prefix="clipia_budget_",
            suffix=".tmp",
            dir=str(self.ledger_path.parent),
        )
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
            os.replace(tmp_name, self.ledger_path)
        finally:
            if os.path.exists(tmp_name):
                try:
                    os.remove(tmp_name)
                except OSError:
                    pass

    def _get_or_init_month_entry(self, ledger: dict[str, Any], month_key: str) -> dict[str, Any]:
        months = ledger.setdefault("months", {})
        if month_key not in months or not isinstance(months[month_key], dict):
            months[month_key] = {
                "spent_brl": 0.0,
                "reserved_brl": 0.0,
                "spent_usd": 0.0,
                "calls_count": 0,
                "prompt_tokens": 0,
                "completion_tokens": 0,
                "updated_at": datetime.now(timezone.utc).isoformat(),
            }
        months[month_key].setdefault("reserved_brl", 0.0)
        return months[month_key]

    def sync_with_backend_monthly_spent(self, backend_monthly_brl: float) -> float:
        """
        Sincroniza conservadoramente o ledger local com o total mensal reportado pelo banco PHP/MySQL,
        prevenindo perda de histórico caso o arquivo local tenha sido limpo.
        """
        if backend_monthly_brl <= 0.0:
            return self.get_monthly_spent_brl()

        with self._interprocess_lock():
            month_key = self.current_month_key()
            ledger = self._load_ledger_unlocked()
            entry = self._get_or_init_month_entry(ledger, month_key)
            current_local = float(entry.get("spent_brl", 0.0))

            if backend_monthly_brl > current_local:
                entry["spent_brl"] = round(backend_monthly_brl, 4)
                entry["updated_at"] = datetime.now(timezone.utc).isoformat()
                self._save_ledger_atomic_unlocked(ledger)

            return float(entry["spent_brl"])

    def get_monthly_spent_brl(self) -> float:
        with self._interprocess_lock():
            month_key = self.current_month_key()
            ledger = self._load_ledger_unlocked()
            entry = self._get_or_init_month_entry(ledger, month_key)
            return round(float(entry.get("spent_brl", 0.0)), 4)

    def estimate_WorstCase_next_call_brl(self, estimated_input_chars: int) -> float:
        """
        Calcula uma reserva conservadora antes de disparar a chamada HTTP, combinando
        o piso mínimo por chamada (`min_cost_per_call_brl`) com uma estimativa de entrada/saída.
        """
        approx_input_tokens = max(200, int(estimated_input_chars / 3.5))
        approx_output_tokens = 900
        usd_est = (
            (approx_input_tokens / 1_000_000.0) * self.config.input_cost_per_1m_usd
            + (approx_output_tokens / 1_000_000.0) * self.config.output_cost_per_1m_usd
        )
        brl_from_tokens = usd_est * self.config.usd_to_brl_rate
        return round(max(self.config.min_cost_per_call_brl, brl_from_tokens), 4)

    def check_can_make_call(self, current_job_cost_brl: float, estimated_input_chars: int = 1200) -> None:
        """
        Verifica antes de realizar qualquer chamada paga se:
        1. O gasto acumulado da tarefa + reserva da próxima chamada excede `max_cost_per_job_brl`.
        2. O gasto acumulado no mês + reservas em trânsito excede `max_monthly_cost_brl` (ex.: R$ 100,00).
        """
        next_call_reserve = self.estimate_WorstCase_next_call_brl(estimated_input_chars)

        with self._interprocess_lock():
            month_key = self.current_month_key()
            ledger = self._load_ledger_unlocked()
            entry = self._get_or_init_month_entry(ledger, month_key)
            monthly_spent = round(float(entry.get("spent_brl", 0.0)) + float(entry.get("reserved_brl", 0.0)), 4)

        if current_job_cost_brl >= self.config.max_cost_per_job_brl or (
            current_job_cost_brl + next_call_reserve > self.config.max_cost_per_job_brl
        ):
            raise BudgetExceededError(
                f"Limite de gasto por tarefa atingido (atual: R$ {current_job_cost_brl:.4f}, "
                f"próxima chamada estimada: R$ {next_call_reserve:.4f}, "
                f"limite por tarefa: R$ {self.config.max_cost_per_job_brl:.2f})."
            )

        if monthly_spent >= self.config.max_monthly_cost_brl or (
            monthly_spent + next_call_reserve > self.config.max_monthly_cost_brl
        ):
            raise BudgetExceededError(
                f"Teto mensal da aplicação atingido (acumulado no mês: R$ {monthly_spent:.2f}, "
                f"próxima chamada estimada: R$ {next_call_reserve:.4f}, "
                f"teto mensal configurado: R$ {self.config.max_monthly_cost_brl:.2f})."
            )

    def reserve_call_budget(
        self,
        current_job_cost_brl: float,
        estimated_input_chars: int = 1200,
        backend_api: Any | None = None,
        job_id: int | None = None,
        project_id: int | None = None,
    ) -> dict[str, Any]:
        """
        Reserva atomicamente o custo estimado da chamada antes de enviar a requisição HTTP ao LLM:
        1. Se `backend_api`, `job_id` e `project_id` estiverem presentes, realiza a reserva atômica
           compartilhada no banco de dados (`monthly_budgets` / `budget_reservations`).
        2. Também grava a reserva atomicamente sob lock de processo no ledger local.
        """
        next_call_reserve = self.estimate_WorstCase_next_call_brl(estimated_input_chars)

        if current_job_cost_brl >= self.config.max_cost_per_job_brl or (
            current_job_cost_brl + next_call_reserve > self.config.max_cost_per_job_brl
        ):
            raise BudgetExceededError(
                f"Limite de gasto por tarefa atingido (atual: R$ {current_job_cost_brl:.4f}, "
                f"próxima chamada estimada: R$ {next_call_reserve:.4f}, "
                f"limite por tarefa: R$ {self.config.max_cost_per_job_brl:.2f})."
            )

        db_token: str | None = None
        if backend_api is not None and job_id is not None and project_id is not None:
            db_res = backend_api.reserve_budget(
                job_id=job_id,
                project_id=project_id,
                reserve_brl=next_call_reserve,
                max_monthly_brl=self.config.max_monthly_cost_brl,
                max_job_brl=self.config.max_cost_per_job_brl,
            )
            if not db_res.get("allowed", False):
                raise BudgetExceededError(
                    str(db_res.get("reason") or "Teto de orçamento atingido no banco de dados compartilhado.")
                )
            db_token = str(db_res.get("reservation_token") or "")

        with self._interprocess_lock():
            month_key = self.current_month_key()
            ledger = self._load_ledger_unlocked()
            entry = self._get_or_init_month_entry(ledger, month_key)
            spent_now = float(entry.get("spent_brl", 0.0))
            reserved_now = float(entry.get("reserved_brl", 0.0))

            if (spent_now + reserved_now + next_call_reserve) > (self.config.max_monthly_cost_brl + 1e-6):
                if db_token and backend_api is not None:
                    try:
                        backend_api.settle_budget(db_token, 0.0, release_only=True)
                    except Exception:
                        pass
                raise BudgetExceededError(
                    f"Teto mensal da aplicação atingido (gasto: R$ {spent_now:.2f}, "
                    f"reservado: R$ {reserved_now:.2f}, próxima chamada: R$ {next_call_reserve:.4f}, "
                    f"teto mensal configurado: R$ {self.config.max_monthly_cost_brl:.2f})."
                )

            local_token = db_token or f"local_{uuid.uuid4().hex}"
            entry["reserved_brl"] = round(reserved_now + next_call_reserve, 4)
            entry["updated_at"] = datetime.now(timezone.utc).isoformat()
            reservations = ledger.setdefault("reservations", {})
            reservations[local_token] = {
                "month_key": month_key,
                "reserved_brl": next_call_reserve,
                "db_token": db_token,
                "status": "reserved",
            }
            self._save_ledger_atomic_unlocked(ledger)

        return {
            "reservation_token": local_token,
            "db_token": db_token,
            "reserved_brl": next_call_reserve,
        }

    def release_call_reservation(
        self,
        reservation: dict[str, Any] | None,
        backend_api: Any | None = None,
    ) -> None:
        """
        Libera uma reserva pré-chamada caso a chamada HTTP não se concretize (ex.: timeout de rede ou falha).
        """
        if not reservation:
            return
        local_token = str(reservation.get("reservation_token") or "")
        db_token = reservation.get("db_token")

        if db_token and backend_api is not None:
            try:
                backend_api.settle_budget(str(db_token), 0.0, release_only=True)
            except Exception:
                pass

        with self._interprocess_lock():
            ledger = self._load_ledger_unlocked()
            reservations = ledger.setdefault("reservations", {})
            res_info = reservations.pop(local_token, None)
            if isinstance(res_info, dict) and res_info.get("status") == "reserved":
                m_key = str(res_info.get("month_key") or self.current_month_key())
                entry = self._get_or_init_month_entry(ledger, m_key)
                rev = float(res_info.get("reserved_brl", 0.0))
                entry["reserved_brl"] = round(max(0.0, float(entry.get("reserved_brl", 0.0)) - rev), 4)
                entry["updated_at"] = datetime.now(timezone.utc).isoformat()
                self._save_ledger_atomic_unlocked(ledger)

    def extract_and_record_call_cost(
        self,
        response_payload: dict[str, Any] | None,
        response_headers: dict[str, str] | None = None,
        reservation: dict[str, Any] | None = None,
        backend_api: Any | None = None,
    ) -> dict[str, Any]:
        """
        Extrai o consumo e o custo reportados pelo provedor (quando disponíveis),
        aplica piso mínimo por chamada para nunca depender apenas de estimativa de tokens,
        liquida a reserva atômica no banco compartilhado e no ledger local e retorna o resumo auditável.
        """
        usage_obj = (response_payload or {}).get("usage")
        if not isinstance(usage_obj, dict):
            usage_obj = {}

        prompt_tokens = int(usage_obj.get("prompt_tokens", 0) or 0)
        completion_tokens = int(usage_obj.get("completion_tokens", 0) or 0)
        total_tokens = int(usage_obj.get("total_tokens", 0) or (prompt_tokens + completion_tokens))

        # 1. Verifica se o provedor reportou custo explícito no corpo JSON ou nos cabeçalhos
        provider_cost_usd: float | None = None
        for key in ("total_cost_usd", "cost_usd", "cost", "total_cost"):
            raw_val = usage_obj.get(key)
            if isinstance(raw_val, (int, float)) and float(raw_val) >= 0.0:
                provider_cost_usd = float(raw_val)
                break

        if provider_cost_usd is None and response_headers:
            lowered_headers = {k.lower(): v for k, v in response_headers.items()}
            for h_key in ("x-openai-cost-usd", "x-request-cost-usd", "x-usage-cost-usd"):
                if h_key in lowered_headers:
                    try:
                        provider_cost_usd = float(lowered_headers[h_key])
                        break
                    except ValueError:
                        pass

        # 2. Calcula custo pelas tarifas configuradas de entrada/saída
        token_based_usd = (
            (prompt_tokens / 1_000_000.0) * self.config.input_cost_per_1m_usd
            + (completion_tokens / 1_000_000.0) * self.config.output_cost_per_1m_usd
        )

        effective_usd = provider_cost_usd if provider_cost_usd is not None else token_based_usd
        calculated_brl = effective_usd * self.config.usd_to_brl_rate

        # 3. Nunca depende exclusivamente de tokens: aplica sempre o piso mínimo por chamada HTTP realizada
        final_call_brl = round(max(self.config.min_cost_per_call_brl, calculated_brl), 4)
        final_call_usd = round(max(effective_usd, final_call_brl / max(0.01, self.config.usd_to_brl_rate)), 6)

        db_monthly_spent: float | None = None
        if reservation and reservation.get("db_token") and backend_api is not None:
            settled_resp = backend_api.settle_budget(
                reservation_token=str(reservation["db_token"]),
                actual_cost_brl=final_call_brl,
                prompt_tokens=prompt_tokens,
                completion_tokens=completion_tokens,
                release_only=False,
            )
            if "monthly_spent_brl" in settled_resp:
                db_monthly_spent = float(settled_resp["monthly_spent_brl"])

        with self._interprocess_lock():
            month_key = self.current_month_key()
            ledger = self._load_ledger_unlocked()
            entry = self._get_or_init_month_entry(ledger, month_key)

            if reservation:
                local_tok = str(reservation.get("reservation_token") or "")
                res_info = ledger.setdefault("reservations", {}).pop(local_tok, None)
                if isinstance(res_info, dict):
                    rev = float(res_info.get("reserved_brl", 0.0))
                    entry["reserved_brl"] = round(max(0.0, float(entry.get("reserved_brl", 0.0)) - rev), 4)

            new_local_spent = round(float(entry.get("spent_brl", 0.0)) + final_call_brl, 4)
            if db_monthly_spent is not None and db_monthly_spent > new_local_spent:
                new_local_spent = round(db_monthly_spent, 4)

            entry["spent_brl"] = new_local_spent
            entry["spent_usd"] = round(float(entry.get("spent_usd", 0.0)) + final_call_usd, 6)
            entry["calls_count"] = int(entry.get("calls_count", 0)) + 1
            entry["prompt_tokens"] = int(entry.get("prompt_tokens", 0)) + prompt_tokens
            entry["completion_tokens"] = int(entry.get("completion_tokens", 0)) + completion_tokens
            entry["updated_at"] = datetime.now(timezone.utc).isoformat()

            self._save_ledger_atomic_unlocked(ledger)

        return {
            "prompt_tokens": prompt_tokens,
            "completion_tokens": completion_tokens,
            "total_tokens": total_tokens,
            "provider_reported_cost_usd": provider_cost_usd,
            "call_cost_brl": final_call_brl,
            "monthly_spent_brl": float(entry["spent_brl"]),
        }

