"""
Suíte de Testes com Mocks para o Provedor OpenAI e Controle de Orçamento (`worker_service/test_openai_provider.py`).

Valida todos os cenários exigidos sem realizar nenhuma chamada real ou cobrança externa:
1. Resposta válida estruturada da API da OpenAI (com registro de prompt_tokens, completion_tokens e custo reportado pelo provedor).
2. Verificação de que apenas os trechos de transcrição do bloco atual são enviados à API de texto (nunca o vídeo inteiro nem outros blocos fora da janela).
3. Configuração de modelo via variável de ambiente (`CLIPIA_LLM_MODEL`).
4. Resposta com JSON inválido / malformado (aciona contingência local segura sem quebrar a tarefa).
5. Timeout de rede (`httpx.TimeoutException`) com contingência local e registro auditável.
6. Erro de autenticação (`HTTP 401/403`) bloqueando novas chamadas remotas na tarefa e sem vazar `llm_api_key` nos logs ou erros.
7. Falha interna do provedor (`HTTP 500/503`) com fallback local.
8. Limite de gasto por tarefa (`max_cost_per_job_brl`) e teto mensal de R$ 100,00 (`max_monthly_cost_brl`) persistidos em disco (inclusive após reinicializar o worker e mesmo quando o provedor omite `usage`/tokens).
"""

from __future__ import annotations

import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

import httpx

from clipia_worker.analyzer import TranscriptAnalyzer
from clipia_worker.config import WorkerConfig
from clipia_worker.models import (
    ClipSelectionParams,
    TranscriptDocument,
    TranscriptSegment,
    UsageRecord,
)


def _build_sample_two_chunk_document() -> TranscriptDocument:
    return TranscriptDocument(
        language="pt",
        duration_seconds=105.0,
        source_label="openai_mock_test.json",
        segments=[
            # Bloco 1 (0s a 40s)
            TranscriptSegment(
                start=0.0,
                end=14.0,
                text="Quando eu comecei a estruturar equipes remotas, aconteceu uma história importante no primeiro trimestre.",
            ),
            TranscriptSegment(
                start=14.0,
                end=27.0,
                text="No começo realizávamos reuniões diárias de duas horas que cansavam todo o time técnico.",
            ),
            TranscriptSegment(
                start=27.0,
                end=40.0,
                text="Aprendi que resumos escritos objetivos funcionam melhor e o resultado foi dobrar a velocidade de entrega.",
            ),
            # Bloco 2 (60s a 102s)
            TranscriptSegment(
                start=60.0,
                end=74.0,
                text="A melhor dica prática para documentar decisões de arquitetura é registrar o contexto em uma página curta.",
            ),
            TranscriptSegment(
                start=74.0,
                end=88.0,
                text="O primeiro passo é descrever o problema real, listar duas alternativas e explicar por que uma foi escolhida.",
            ),
            TranscriptSegment(
                start=88.0,
                end=102.0,
                text="Recomendo revisar esse documento a cada trimestre para manter a equipe alinhada sem reuniões extras.",
            ),
        ],
    )


class OpenAIProviderAndBudgetTestSuite(unittest.TestCase):
    def setUp(self) -> None:
        self._tmp_dir = tempfile.TemporaryDirectory()
        self.ledger_file = Path(self._tmp_dir.name) / "test_budget_ledger.json"

    def tearDown(self) -> None:
        self._tmp_dir.cleanup()

    def _make_config(
        self,
        *,
        allow_paid_api: bool = True,
        llm_model: str = "gpt-4o-mini",
        max_cost_per_job_brl: float = 2.50,
        max_monthly_cost_brl: float = 100.00,
        min_cost_per_call_brl: float = 0.02,
    ) -> WorkerConfig:
        return WorkerConfig(
            backend_api_url="http://127.0.0.1:8099/pages/worker-api.php",
            worker_shared_secret="segredo-hmac-teste-openai-suite-2026",
            verify_tls=True,
            whisper_model="base",
            whisper_device="cpu",
            whisper_compute_type="int8",
            whisper_language="pt",
            chunk_window_seconds=60.0,
            chunk_overlap_seconds=10.0,
            ai_provider="openai_compatible",
            allow_paid_api=allow_paid_api,
            llm_base_url="https://api.openai.com/v1",
            llm_api_key="sk-mock-secret-openai-key-never-leak-987654321",
            llm_model=llm_model,
            max_audio_duration_seconds=5400,
            max_llm_calls_per_job=10,
            max_llm_tokens_per_job=24000,
            max_retries_per_task=1,
            max_cost_per_job_brl=max_cost_per_job_brl,
            max_monthly_cost_brl=max_monthly_cost_brl,
            usd_to_brl_rate=5.80,
            input_cost_per_1m_usd=0.15,
            output_cost_per_1m_usd=0.60,
            min_cost_per_call_brl=min_cost_per_call_brl,
            llm_timeout_seconds=15.0,
            budget_ledger_path=str(self.ledger_file),
        )

    def test_01_valid_openai_response_chunk_only_and_env_model(self) -> None:
        """
        Testa resposta válida da OpenAI, modelo configurável via ambiente, envio estrito apenas do bloco
        de transcrição atual e registro de tokens e custo reportado pelo provedor.
        """
        with patch.dict(
            os.environ,
            {
                "OPENAI_API_KEY": "sk-mock-env-openai-key-123456789012",
                "CLIPIA_LLM_MODEL": "gpt-4.1-mini",
            },
            clear=False,
        ):
            env_cfg = WorkerConfig.from_env()
            self.assertEqual(env_cfg.llm_api_key, "sk-mock-env-openai-key-123456789012")
            self.assertEqual(env_cfg.openai_api_key, "sk-mock-env-openai-key-123456789012")
            self.assertEqual(env_cfg.llm_model, "gpt-4.1-mini")
            self.assertFalse(env_cfg.allow_paid_api)
            self.assertEqual(env_cfg.ai_provider, "local_heuristic")

        cfg = self._make_config(llm_model="gpt-4.1-mini")
        analyzer = TranscriptAnalyzer(cfg)
        doc = _build_sample_two_chunk_document()
        usage = UsageRecord()
        params = ClipSelectionParams(min_duration_seconds=35.0, max_duration_seconds=50.0, target_clips_count=4)

        sent_Payloads: list[dict] = []

        def fake_post(url: str, headers: dict, content: str):
            body = json.loads(content)
            sent_Payloads.append(body)
            user_msg = body["messages"][1]["content"]

            if "[0.0s–40.0s]" in user_msg:
                cand_obj = {
                    "start_time_seconds": 0.0,
                    "end_time_seconds": 40.0,
                    "category": "historia_completa",
                    "title": "História sobre reuniões remotas e produtividade",
                    "summary": "Relata como a troca de reuniões longas por resumos escritos dobrou a velocidade.",
                    "rationale": "História completa com início, problema e conclusão dentro de 40 segundos.",
                    "transcript_excerpt": (
                        "Quando eu comecei a estruturar equipes remotas, aconteceu uma história importante no primeiro trimestre. "
                        "No começo realizávamos reuniões diárias de duas horas que cansavam todo o time técnico. "
                        "Aprendi que resumos escritos objetivos funcionam melhor e o resultado foi dobrar a velocidade de entrega."
                    ),
                    "relevance_score": 92,
                }
            else:
                cand_obj = {
                    "start_time_seconds": 60.0,
                    "end_time_seconds": 102.0,
                    "category": "dica_pratica",
                    "title": "Dica prática para documentar decisões técnicas",
                    "summary": "Ensina a documentar contexto, alternativas e decisão em uma página curta.",
                    "rationale": "Dica acionável com três passos claros e fechamento conclusivo.",
                    "transcript_excerpt": (
                        "A melhor dica prática para documentar decisões de arquitetura é registrar o contexto em uma página curta. "
                        "O primeiro passo é descrever o problema real, listar duas alternativas e explicar por que uma foi escolhida. "
                        "Recomendo revisar esse documento a cada trimestre para manter a equipe alinhada sem reuniões extras."
                    ),
                    "relevance_score": 90,
                }

            mock_resp = MagicMock()
            mock_resp.status_code = 200
            mock_resp.headers = {"x-openai-cost-usd": "0.0050"}
            mock_resp.json.return_value = {
                "model": "gpt-4.1-mini-2026",
                "choices": [{"message": {"content": json.dumps({"candidates": [cand_obj]}, ensure_ascii=False)}}],
                "usage": {
                    "prompt_tokens": 320,
                    "completion_tokens": 180,
                    "total_tokens": 500,
                    "total_cost_usd": 0.0050,
                },
            }
            return mock_resp

        with patch("httpx.Client.post", side_effect=fake_post):
            candidates = analyzer.analyze(doc, params, usage)

        self.assertEqual(len(candidates), 2)
        self.assertEqual(usage.llm_calls_count, 2)
        self.assertEqual(usage.prompt_tokens, 640)
        self.assertEqual(usage.completion_tokens, 360)
        self.assertEqual(usage.llm_tokens_used, 1000)
        self.assertEqual(usage.llm_model_used, "gpt-4.1-mini-2026")
        self.assertAlmostEqual(usage.provider_reported_cost_usd or 0.0, 0.0100, places=5)
        self.assertGreater(usage.estimated_cost_brl, 0.0)

        # Verifica que o Bloco 1 NÃO enviou o texto do Bloco 2 (nunca envia o vídeo/transcrição inteira de uma vez)
        first_call_user_text = sent_Payloads[0]["messages"][1]["content"]
        self.assertIn("Quando eu comecei a estruturar equipes remotas", first_call_user_text)
        self.assertNotIn("documentar decisões de arquitetura", first_call_user_text)

    def test_02_invalid_json_from_provider_falls_back_safely(self) -> None:
        """Testa resposta com JSON inválido/corrompido da API acionando contingência local sem falhar a tarefa."""
        cfg = self._make_config()
        analyzer = TranscriptAnalyzer(cfg)
        doc = _build_sample_two_chunk_document()
        usage = UsageRecord()
        params = ClipSelectionParams(min_duration_seconds=35.0, max_duration_seconds=50.0, target_clips_count=4)

        mock_resp = MagicMock()
        mock_resp.status_code = 200
        mock_resp.headers = {}
        mock_resp.json.return_value = {
            "model": "gpt-4o-mini",
            "choices": [{"message": {"content": "Isto não é um JSON válido {incompleto..."}}],
            "usage": {"prompt_tokens": 150, "completion_tokens": 40, "total_tokens": 190},
        }

        with patch("httpx.Client.post", return_value=mock_resp):
            candidates = analyzer.analyze(doc, params, usage)

        self.assertEqual(len(candidates), 2, "Contingência local deve produzir os candidatos válidos.")
        self.assertGreaterEqual(len(usage.errors_logged), 1)
        self.assertIn("JSON válido", usage.errors_logged[0])

    def test_03_timeout_error_falls_back_safely(self) -> None:
        """Testa timeout da API (httpx.TimeoutException) com fallback local."""
        cfg = self._make_config()
        analyzer = TranscriptAnalyzer(cfg)
        doc = _build_sample_two_chunk_document()
        usage = UsageRecord()
        params = ClipSelectionParams(min_duration_seconds=35.0, max_duration_seconds=50.0, target_clips_count=4)

        with patch("httpx.Client.post", side_effect=httpx.ReadTimeout("Timeout simulado na chamada OpenAI")):
            candidates = analyzer.analyze(doc, params, usage)

        self.assertEqual(len(candidates), 2)
        self.assertTrue(any("Timeout" in err for err in usage.errors_logged))

    def test_04_authentication_error_401_blocks_subsequent_calls_and_hides_key(self) -> None:
        """Testa erro HTTP 401 de autenticação: interrompe chamadas subsequentes e nunca vaza a chave de API."""
        cfg = self._make_config()
        analyzer = TranscriptAnalyzer(cfg)
        doc = _build_sample_two_chunk_document()
        usage = UsageRecord()
        params = ClipSelectionParams(min_duration_seconds=35.0, max_duration_seconds=50.0, target_clips_count=4)

        mock_resp = MagicMock()
        mock_resp.status_code = 401
        mock_resp.headers = {}
        mock_resp.text = f"Incorrect API key provided: {cfg.llm_api_key}"

        with patch("httpx.Client.post", return_value=mock_resp) as mock_post:
            candidates = analyzer.analyze(doc, params, usage)
            # Como o 1º bloco retornou 401, usage.budget_blocked vira True e o 2º bloco NÃO chama a API!
            self.assertEqual(mock_post.call_count, 1)

        self.assertEqual(len(candidates), 2)
        self.assertTrue(usage.budget_blocked)
        for err in usage.errors_logged:
            self.assertNotIn(cfg.llm_api_key, err)
            self.assertIn("HTTP 401", err)

    def test_05_provider_500_failure_falls_back_safely(self) -> None:
        """Testa falha HTTP 500/503 do provedor com contingência local."""
        cfg = self._make_config()
        analyzer = TranscriptAnalyzer(cfg)
        doc = _build_sample_two_chunk_document()
        usage = UsageRecord()
        params = ClipSelectionParams(min_duration_seconds=35.0, max_duration_seconds=50.0, target_clips_count=4)

        mock_resp = MagicMock()
        mock_resp.status_code = 503
        mock_resp.headers = {}

        with patch("httpx.Client.post", return_value=mock_resp):
            candidates = analyzer.analyze(doc, params, usage)

        self.assertEqual(len(candidates), 2)
        self.assertTrue(any("HTTP 503" in err for err in usage.errors_logged))

    def test_06_per_job_and_persistent_monthly_100_brl_budget_caps(self) -> None:
        """
        Verifica:
        1. Limite de gasto por tarefa (`max_cost_per_job_brl`) mesmo quando o provedor omite tokens (`usage={}`).
        2. Persistência do teto mensal de R$ 100,00 (`max_monthly_cost_brl=100.00`) em disco mesmo após
           criar uma nova instância de `TranscriptAnalyzer` (simulando reinicialização do worker).
        """
        # Parte A: Limite por tarefa (permite apenas 1 chamada de R$ 0.10 quando max_cost_per_job_brl=0.15)
        cfg_job_cap = self._make_config(
            max_cost_per_job_brl=0.15,
            max_monthly_cost_brl=100.00,
            min_cost_per_call_brl=0.10,  # Piso fixo de R$ 0.10 por chamada (independente de estimativa de tokens!)
        )
        analyzer_1 = TranscriptAnalyzer(cfg_job_cap)
        doc = _build_sample_two_chunk_document()
        usage_1 = UsageRecord()
        params = ClipSelectionParams(min_duration_seconds=35.0, max_duration_seconds=50.0, target_clips_count=4)

        # Resposta que omite completamente 'usage' para comprovar que o limite não depende só de tokens
        mock_no_usage_resp = MagicMock()
        mock_no_usage_resp.status_code = 200
        mock_no_usage_resp.headers = {}
        mock_no_usage_resp.json.return_value = {
            "model": "gpt-4o-mini",
            "choices": [{"message": {"content": json.dumps({"candidates": []})}}],
            "usage": {},
        }

        with patch("httpx.Client.post", return_value=mock_no_usage_resp) as mock_post_1:
            analyzer_1.analyze(doc, params, usage_1)
            # 1ª chamada custou R$ 0.10 (piso mínimo). Na 2ª chamada (0.10 + 0.10 = 0.20 > 0.15), bloqueou!
            self.assertEqual(mock_post_1.call_count, 1)
            self.assertTrue(usage_1.budget_blocked)
            self.assertAlmostEqual(usage_1.estimated_cost_brl, 0.10, places=4)

        # Parte B: Simula que o gasto acumulado no mês atingiu R$ 99,95 no disco e reinicia o worker
        analyzer_1.budget_guard.sync_with_backend_spent_test = analyzer_1.budget_guard.sync_with_backend_monthly_spent(
            99.95
        )

        # Nova instância simulando reinício do processo Python lendo o mesmo arquivo `budget_ledger.json`
        cfg_restarted = self._make_config(
            max_cost_per_job_brl=5.00,
            max_monthly_cost_brl=100.00,
            min_cost_per_call_brl=0.10,
        )
        analyzer_restarted = TranscriptAnalyzer(cfg_restarted)
        self.assertAlmostEqual(analyzer_restarted.budget_guard.get_monthly_spent_brl(), 99.95, places=2)

        usage_2 = UsageRecord()
        with patch("httpx.Client.post", side_effect=AssertionError("Não deveria chamar API acima de R$ 100!")) as mock_post_2:
            cands_after_cap = analyzer_restarted.analyze(doc, params, usage_2)
            # Como 99.95 + 0.10 > 100.00, bloqueia imediatamente antes da 1ª requisição HTTP!
            self.assertEqual(mock_post_2.call_count, 0)
            self.assertTrue(usage_2.budget_blocked)
            self.assertEqual(len(cands_after_cap), 2)
            self.assertTrue(any("Teto mensal da aplicação atingido" in err for err in usage_2.errors_logged))


if __name__ == "__main__":
    unittest.main(verbosity=2)
