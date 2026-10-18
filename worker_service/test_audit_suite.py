"""
Suíte de Auditoria e Validação Pré-API Paga do Serviço de Análise Inteligente (`worker_service/test_audit_suite.py`).

Cobre os 10 pontos obrigatórios da auditoria sem realizar nenhuma chamada externa ou paga:
1. Confirmação de que `allow_paid_api` permanece `False` por padrão (`WorkerConfig.from_env()`).
2. Garantia de que nenhuma chamada HTTP/paga ocorre quando `allow_paid_api=False` ou quando o provedor está em `local_heuristic` (mesmo que `llm_api_key` esteja preenchida).
3. Teste com transcrição fictícia offline contendo:
   - Uma história completa (`historia_completa`)
   - Uma dica prática (`dica_pratica`)
   - Uma frase incompleta/truncada e frases fora de contexto (que devem ser rejeitadas)
   - Trechos duplicados/repetidos (que devem ser desduplicados e nunca selecionados duas vezes)
4. Confirmação de que todos os candidatos resultantes respeitam `min_duration_seconds`, `max_duration_seconds`, não possuem sobreposição temporal (`overlap == 0`) e permanecem estritamente dentro da duração do vídeo (`end_time_seconds <= duration_seconds`).
5. Validação de timestamps inválidos ou que ultrapassem a duração do vídeo (`TranscriptSegment` / `TranscriptDocument`) e continuidade de blocos (`build_contextual_chunks`).
6. Validação estrita do parser JSON (`parse_and_validate_llm_candidates_json`) e sanitização contra promessas de viralização.
7. Verificação dos limites de duração do áudio (`max_audio_duration_seconds`), chamadas (`max_llm_calls_per_job`), tokens (`max_llm_tokens_per_job`) e tentativas (`max_retries_per_task`).
8. Confirmação de que chaves de API (`llm_api_key`) e segredos HMAC (`worker_shared_secret`) nunca são exibidos em `repr(config)`, mensagens de erro ou logs (`SensitiveDataLogFilter` e `redact_sensitive_text`).
"""

from __future__ import annotations

import io
import json
import logging
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from clipia_worker.analyzer import TranscriptAnalyzer
from clipia_worker.chunker import build_contextual_chunks
from clipia_worker.config import WorkerConfig, _load_worker_environment
from clipia_worker.models import (
    ClipCandidate,
    ClipSelectionParams,
    TranscriptDocument,
    TranscriptSegment,
    UsageRecord,
    parse_and_validate_llm_candidates_json,
)
from clipia_worker.security import (
    SensitiveDataLogFilter,
    UnauthorizedSourceError,
    redact_sensitive_text,
    validate_authorized_local_media_file,
    validate_backend_api_url,
)
from clipia_worker.transcriber import AudioTranscriber
from main import ClipIAWorkerOrchestrator


class ClipIAAuditTestSuite(unittest.TestCase):
    def setUp(self) -> None:
        self._saved_env = dict(os.environ)
        # Limpa variáveis de provedor que possam interferir no padrão de fábrica
        for key in (
            "CLIPIA_ALLOW_PAID_API",
            "CLIPIA_AI_PROVIDER",
            "CLIPIA_LLM_API_KEY",
        ):
            os.environ.pop(key, None)

    def tearDown(self) -> None:
        os.environ.clear()
        os.environ.update(self._saved_env)

    def test_01_allow_paid_api_disabled_by_default(self) -> None:
        """Item 5: Confirma que allow_paid_api permanece False por padrão, lê OPENAI_API_KEY e mantém local_heuristic."""
        _load_worker_environment()
        cfg = WorkerConfig.from_env()
        self.assertFalse(cfg.allow_paid_api, "allow_paid_api deve ser False por padrão.")
        self.assertEqual(cfg.ai_provider, "local_heuristic")
        self.assertEqual(cfg.max_cost_per_job_brl, 2.50)
        self.assertEqual(cfg.max_monthly_cost_brl, 100.00)

        # Se OPENAI_API_KEY estiver configurada no Windows, garante que foi lida sem ser impressa em repr()
        if os.getenv("OPENAI_API_KEY"):
            self.assertTrue(cfg.has_openai_api_key)
            self.assertNotIn(cfg.openai_api_key, repr(cfg))

        # Mesmo definindo OPENAI_API_KEY no ambiente, allow_paid_api continua False e ai_provider continua local_heuristic
        with patch.dict(
            os.environ,
            {
                "OPENAI_API_KEY": "sk-fictitious-env-openai-key-000000000000",
                "CLIPIA_LLM_API_KEY": "sk-fallback-legacy-key-111111111111",
            },
            clear=False,
        ):
            cfg_with_key = WorkerConfig.from_env()
            self.assertEqual(cfg_with_key.llm_api_key, "sk-fictitious-env-openai-key-000000000000")
            self.assertEqual(cfg_with_key.openai_api_key, "sk-fictitious-env-openai-key-000000000000")
            self.assertTrue(cfg_with_key.has_openai_api_key)
            self.assertFalse(cfg_with_key.allow_paid_api)
            self.assertEqual(cfg_with_key.ai_provider, "local_heuristic")
            self.assertNotIn("sk-fictitious-env-openai-key-000000000000", repr(cfg_with_key))

        # Garante que valor fictício de placeholder (ex.: vindo de .env.example) não é tratado como chave real
        with patch.dict(
            os.environ,
            {
                "OPENAI_API_KEY": "your_openai_api_key_here_do_not_commit",
                "CLIPIA_LLM_API_KEY": "",
            },
            clear=False,
        ):
            cfg_placeholder = WorkerConfig.from_env()
            self.assertFalse(cfg_placeholder.has_openai_api_key)
            self.assertEqual(cfg_placeholder.openai_api_key, "")

    def test_02_no_paid_call_when_disabled_even_with_api_key(self) -> None:
        """Item 6: Verifica que nenhuma chamada HTTP externa pode ocorrer quando allow_paid_api=False ou provedor desabilitado."""
        cfg = WorkerConfig(
            backend_api_url="http://127.0.0.1:8099/pages/worker-api.php",
            worker_shared_secret="segredo-super-confidencial-hmac-2026",
            verify_tls=True,
            whisper_model="base",
            whisper_device="cpu",
            whisper_compute_type="int8",
            whisper_language="pt",
            chunk_window_seconds=90.0,
            chunk_overlap_seconds=20.0,
            ai_provider="openai_compatible",
            allow_paid_api=False,  # Desativado!
            llm_base_url="https://api.openai.com/v1",
            llm_api_key="sk-test-should-never-be-called-1234567890",
            llm_model="gpt-4o-mini",
            max_audio_duration_seconds=5400,
            max_llm_calls_per_job=5,
            max_llm_tokens_per_job=5000,
            max_retries_per_task=1,
        )
        analyzer = TranscriptAnalyzer(cfg)
        doc = TranscriptDocument(
            language="pt",
            duration_seconds=60.0,
            segments=[
                TranscriptSegment(
                    start=0.0,
                    end=20.0,
                    text="A melhor dica prática para gravar aulas claras é estruturar o roteiro em três passos.",
                ),
                TranscriptSegment(
                    start=20.0,
                    end=42.0,
                    text="Primeiro apresente o problema, depois demonstre a prática e finalize com uma recomendação objetiva.",
                ),
            ],
        )
        usage = UsageRecord()
        params = ClipSelectionParams(min_duration_seconds=30.0, max_duration_seconds=50.0, target_clips_count=2)

        with patch("httpx.Client.post", side_effect=AssertionError("Chamada HTTP externa indevida!")) as mock_post:
            candidates = analyzer.analyze(doc, params, usage)
            self.assertEqual(mock_post.call_count, 0)
            self.assertFalse(usage.paid_api_used)
            self.assertEqual(usage.llm_calls_count, 0)
            self.assertEqual(usage.llm_tokens_used, 0)
            self.assertGreaterEqual(len(candidates), 1)

        # Também testa chamada direta a _analyze_chunk_with_llm com allow_paid_api=False
        chunks = build_contextual_chunks(doc, window_seconds=90.0, overlap_seconds=20.0)
        with self.assertRaises(PermissionError):
            analyzer._analyze_chunk_with_llm(chunks[0], params, usage, doc.duration_seconds)

    def test_03_fictional_transcript_story_tip_incomplete_and_duplicates(self) -> None:
        """
        Itens 7 e 8: Teste com transcrição fictícia sem chamada externa contendo:
        - Uma história completa (0s a 42s)
        - Uma frase fora de contexto e uma frase incompleta/truncada (42s a 58s)
        - Uma dica prática completa (58s a 100s)
        - Trechos duplicados da história e da dica (100s a 184s)
        Confirma seleção apenas dos trechos válidos, sem duplicatas, sem frases incompletas,
        respeitando duração mínima, máxima e duração total do vídeo.
        """
        fictional_doc = TranscriptDocument(
            language="pt",
            duration_seconds=185.0,
            source_label="fictional_audit_transcript.json",
            segments=[
                # 1. História completa (0.0s -> 42.0s = 42.0s)
                TranscriptSegment(
                    start=0.0,
                    end=14.0,
                    text="Quando eu apresentei meu primeiro projeto ao vivo, aconteceu uma história que mudou minha forma de preparar reuniões.",
                ),
                TranscriptSegment(
                    start=14.0,
                    end=28.0,
                    text="No começo da demonstração a internet caiu por completo e eu dependia de um painel que estava apenas na nuvem.",
                ),
                TranscriptSegment(
                    start=28.0,
                    end=42.0,
                    text="Aprendi naquele dia a manter uma versão local funcional e o resultado foi fechar o contrato sem atrasar a apresentação.",
                ),
                # 2. Frase fora de contexto + Frase incompleta truncada (42.0s -> 58.0s)
                TranscriptSegment(
                    start=42.0,
                    end=48.0,
                    text="E aí como eu disse antes isso que mudava tudo.",
                ),
                TranscriptSegment(
                    start=48.0,
                    end=58.0,
                    text="Mas quando você tenta configurar o servidor sem verificar se a porta principal porque...",
                ),
                # 3. Dica prática completa (58.0s -> 100.0s = 42.0s)
                TranscriptSegment(
                    start=58.0,
                    end=72.0,
                    text="A melhor dica prática para evitar falhas em gravações longas é aplicar uma regra simples de verificação em duas etapas.",
                ),
                TranscriptSegment(
                    start=72.0,
                    end=86.0,
                    text="O primeiro passo é gravar dez segundos de teste e ouvir com fone fechado para confirmar que não há ruído elétrico.",
                ),
                TranscriptSegment(
                    start=86.0,
                    end=100.0,
                    text="Recomendo também monitorar o espaço livre em disco antes de iniciar para garantir uma sessão segura do início ao fim.",
                ),
                # 4. Trechos duplicados (repetição da história e da dica entre 100.0s e 184.0s)
                TranscriptSegment(
                    start=100.0,
                    end=114.0,
                    text="Quando eu apresentei meu primeiro projeto ao vivo, aconteceu uma história que mudou minha forma de preparar reuniões.",
                ),
                TranscriptSegment(
                    start=114.0,
                    end=128.0,
                    text="No começo da demonstração a internet caiu por completo e eu dependia de um painel que estava apenas na nuvem.",
                ),
                TranscriptSegment(
                    start=128.0,
                    end=142.0,
                    text="Aprendi naquele dia a manter uma versão local funcional e o resultado foi fechar o contrato sem atrasar a apresentação.",
                ),
                TranscriptSegment(
                    start=142.0,
                    end=156.0,
                    text="A melhor dica prática para evitar falhas em gravações longas é aplicar uma regra simples de verificação em duas etapas.",
                ),
                TranscriptSegment(
                    start=156.0,
                    end=170.0,
                    text="O primeiro passo é gravar dez segundos de teste e ouvir com fone fechado para confirmar que não há ruído elétrico.",
                ),
                TranscriptSegment(
                    start=170.0,
                    end=184.0,
                    text="Recomendo também monitorar o espaço livre em disco antes de iniciar para garantir uma sessão segura do início ao fim.",
                ),
            ],
        )

        cfg = WorkerConfig.from_env()
        analyzer = TranscriptAnalyzer(cfg)
        usage = UsageRecord()
        params = ClipSelectionParams(
            min_duration_seconds=35.0,
            max_duration_seconds=50.0,
            target_clips_count=5,
            aspect_ratio="9:16",
        )

        candidates = analyzer.analyze(fictional_doc, params, usage)

        # Deve encontrar exatamente 2 candidatos únicos (a história completa e a dica prática),
        # descartando a frase fora de contexto, a frase incompleta e todas as duplicatas!
        self.assertEqual(
            len(candidates),
            2,
            f"Esperado exatamente 2 candidatos únicos (história + dica), obteve {len(candidates)}: {candidates}",
        )

        categories = {c.category for c in candidates}
        self.assertIn("historia_completa", categories)
        self.assertIn("dica_pratica", categories)

        for idx, cand in enumerate(candidates):
            # Verifica limites de duração mínima, máxima e duração do vídeo
            self.assertGreaterEqual(cand.start_time_seconds, 0.0)
            self.assertLessEqual(cand.end_time_seconds, fictional_doc.duration_seconds)
            self.assertGreaterEqual(cand.duration_seconds, params.min_duration_seconds)
            self.assertLessEqual(cand.duration_seconds, params.max_duration_seconds)

            # Confirma que a frase incompleta e a frase fora de contexto não entraram em nenhum candidato
            self.assertNotIn("sem verificar se a porta principal porque", cand.transcript_excerpt)
            self.assertNotIn("E aí como eu disse antes", cand.transcript_excerpt)
            self.assertTrue(cand.transcript_excerpt.endswith((".", "!", "?")))
            self.assertFalse(cand.transcript_excerpt.endswith("..."))

            # Verifica ausência de sobreposição temporal com os demais candidatos
            for other_idx, other in enumerate(candidates):
                if idx != other_idx:
                    overlap_start = max(cand.start_time_seconds, other.start_time_seconds)
                    overlap_end = min(cand.end_time_seconds, other.end_time_seconds)
                    self.assertLessEqual(
                        overlap_end,
                        overlap_start,
                        f"Candidatos #{idx} e #{other_idx} possuem sobreposição temporal!",
                    )

    def test_04_timestamp_and_video_duration_bounds(self) -> None:
        """Itens 4 e 8: Verifica rejeição de timestamps invertidos ou que ultrapassem a duração do vídeo."""
        with self.assertRaises(ValueError):
            TranscriptSegment(start=30.0, end=20.0, text="Timestamp invertido inválido.")

        with self.assertRaises(ValueError):
            TranscriptDocument(
                language="pt",
                duration_seconds=50.0,
                segments=[
                    TranscriptSegment(start=10.0, end=55.0, text="Segmento que passa do fim do vídeo."),
                ],
            )

        # Verifica que um vídeo mais curto que min_duration_seconds NÃO gera candidato que ultrapasse duration_seconds
        short_doc = TranscriptDocument(
            language="pt",
            duration_seconds=22.0,
            segments=[
                TranscriptSegment(
                    start=0.0,
                    end=22.0,
                    text="Uma explicação curta demais para atingir trinta segundos de duração mínima configurada.",
                ),
            ],
        )
        analyzer = TranscriptAnalyzer(WorkerConfig.from_env())
        usage = UsageRecord()
        params = ClipSelectionParams(min_duration_seconds=30.0, max_duration_seconds=60.0, target_clips_count=3)
        short_candidates = analyzer.analyze(short_doc, params, usage)
        self.assertEqual(len(short_candidates), 0, "Não deve inventar timestamp além da duração real do vídeo.")

    def test_05_limits_audio_duration_calls_tokens_and_retries(self) -> None:
        """Item 9: Verifica os limites de duração de áudio, chamadas de IA, tokens e tentativas (retries)."""
        cfg = WorkerConfig(
            backend_api_url="http://127.0.0.1:8099/pages/worker-api.php",
            worker_shared_secret="segredo-super-confidencial-hmac-2026",
            verify_tls=True,
            whisper_model="base",
            whisper_device="cpu",
            whisper_compute_type="int8",
            whisper_language="pt",
            chunk_window_seconds=60.0,
            chunk_overlap_seconds=15.0,
            ai_provider="openai_compatible",
            allow_paid_api=True,
            llm_base_url="https://api.openai.com/v1",
            llm_api_key="sk-fake-key-for-mocked-budget-test-123456",
            llm_model="gpt-4o-mini",
            max_audio_duration_seconds=120,  # Limite de 120s
            max_llm_calls_per_job=1,         # Limite de 1 chamada por job
            max_llm_tokens_per_job=500,      # Limite de 500 tokens por job
            max_retries_per_task=2,          # Limite de 2 retries (total 3 tentativas)
        )

        # 5A. Limite de duração do áudio
        transcriber = AudioTranscriber(cfg)
        with tempfile.NamedTemporaryFile(suffix=".json", mode="w", encoding="utf-8", delete=False) as tf:
            json.dump(
                {
                    "language": "pt",
                    "duration_seconds": 180.0,  # Excede 120s!
                    "segments": [{"start": 0.0, "end": 20.0, "text": "Teste de limite de duração."}],
                },
                tf,
            )
            tf_path = Path(tf.name)

        try:
            with self.assertRaises(ValueError) as ctx:
                transcriber.load_existing_transcript(tf_path, UsageRecord())
            self.assertIn("excede o limite", str(ctx.exception))
        finally:
            tf_path.unlink(missing_ok=True)

        # 5B. Limite de chamadas e tokens em múltiplos blocos (usando mock local, zero chamada real de rede)
        doc_multi = TranscriptDocument(
            language="pt",
            duration_seconds=110.0,
            segments=[
                TranscriptSegment(
                    start=0.0,
                    end=38.0,
                    text="Quando eu comecei no projeto, aconteceu uma história importante e aprendi uma lição valiosa no resultado final.",
                ),
                TranscriptSegment(
                    start=60.0,
                    end=98.0,
                    text="A melhor dica prática para organizar o código é aplicar testes automatizados antes de cada entrega em produção.",
                ),
            ],
        )
        fake_llm_response = {
            "choices": [
                {
                    "message": {
                        "content": json.dumps(
                            {
                                "candidates": [
                                    {
                                        "start_time_seconds": 0.0,
                                        "end_time_seconds": 38.0,
                                        "title": "História sobre aprendizado no projeto",
                                        "category": "historia_completa",
                                        "summary": "Relata o início do projeto e a lição prática aprendida na entrega.",
                                        "rationale": "História completa com início, meio e conclusão dentro da duração.",
                                        "transcript_excerpt": "Quando eu comecei no projeto, aconteceu uma história importante e aprendi uma lição valiosa no resultado final.",
                                        "relevance_score": 88,
                                    }
                                ]
                            }
                        )
                    }
                }
            ],
            "usage": {"total_tokens": 600},  # Já atinge o limite de 500 tokens na 1ª chamada!
        }

        analyzer = TranscriptAnalyzer(cfg)
        usage = UsageRecord()
        params = ClipSelectionParams(min_duration_seconds=30.0, max_duration_seconds=45.0, target_clips_count=4)

        with patch("httpx.Client.post") as mock_post:
            mock_post.return_value.status_code = 200
            mock_post.return_value.raise_for_status = lambda: None
            mock_post.return_value.json = lambda: fake_llm_response

            cands = analyzer.analyze(doc_multi, params, usage)
            # Mesmo havendo 2 blocos, como max_llm_calls_per_job=1 e tokens=600 >= 500,
            # apenas 1 chamada mockada pôde ocorrer; o 2º bloco foi analisado localmente!
            self.assertEqual(mock_post.call_count, 1)
            self.assertEqual(usage.llm_calls_count, 1)
            self.assertEqual(len(cands), 2)

        # 5C. Limite de tentativas (retries) no orquestrador
        orchestrator = ClipIAWorkerOrchestrator(cfg)
        with tempfile.NamedTemporaryFile(suffix=".mp4", delete=False) as media_tf:
            media_tf.write(b"\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom")
            media_path = Path(media_tf.name)

        try:
            with patch.object(
                orchestrator.transcriber,
                "transcribe_authorized_media",
                side_effect=RuntimeError("Erro simulado de leitura"),
            ) as mock_transcribe:
                with self.assertRaises(RuntimeError) as err_ctx:
                    orchestrator.run_on_local_authorized_file(media_path, params)
                # 1 tentativa inicial + 2 retries = 3 chamadas
                self.assertEqual(mock_transcribe.call_count, 3)
                self.assertIn("após 3 tentativa(s)", str(err_ctx.exception))
        finally:
            media_path.unlink(missing_ok=True)

    def test_06_no_secrets_or_api_keys_in_logs_or_repr(self) -> None:
        """Item 10: Confirma que segredos HMAC e chaves de API nunca aparecem em repr(config) nem nos logs."""
        secret_hmac = "hmac-super-secret-value-998877665544332211"
        secret_api_key = "sk-proj-999888777666555444333222111000"

        cfg = WorkerConfig(
            backend_api_url="http://127.0.0.1:8099/pages/worker-api.php",
            worker_shared_secret=secret_hmac,
            verify_tls=True,
            whisper_model="base",
            whisper_device="cpu",
            whisper_compute_type="int8",
            whisper_language="pt",
            chunk_window_seconds=120.0,
            chunk_overlap_seconds=25.0,
            ai_provider="local_heuristic",
            allow_paid_api=False,
            llm_base_url="https://api.openai.com/v1",
            llm_api_key=secret_api_key,
            llm_model="gpt-4o-mini",
            max_audio_duration_seconds=5400,
            max_llm_calls_per_job=10,
            max_llm_tokens_per_job=20000,
            max_retries_per_task=2,
        )

        # Verifica repr() e str() do WorkerConfig
        config_repr = repr(cfg)
        self.assertNotIn(secret_hmac, config_repr)
        self.assertNotIn(secret_api_key, config_repr)

        # Verifica redact_sensitive_text e SensitiveDataLogFilter
        raw_leak = (
            f"Falha com Authorization: Bearer {secret_api_key} "
            f"e OPENAI_API_KEY={secret_api_key} "
            f"e segredo {secret_hmac} e X-ClipIA-Signature: aabbccddeeff00112233445566778899aabbccddeeff"
        )
        redacted = redact_sensitive_text(raw_leak, [secret_hmac, secret_api_key])
        self.assertNotIn(secret_hmac, redacted)
        self.assertNotIn(secret_api_key, redacted)
        self.assertNotIn("aabbccddeeff00112233445566778899aabbccddeeff", redacted)

        log_stream = io.StringIO()
        test_logger = logging.getLogger("clipia.secret_audit_test")
        test_logger.setLevel(logging.INFO)
        test_logger.propagate = False
        handler = logging.StreamHandler(log_stream)
        handler.addFilter(SensitiveDataLogFilter([secret_hmac, secret_api_key]))
        test_logger.addHandler(handler)

        try:
            test_logger.error("Erro simulado contendo %s e Bearer %s", secret_hmac, secret_api_key)
            logged_output = log_stream.getvalue()
            self.assertNotIn(secret_hmac, logged_output)
            self.assertNotIn(secret_api_key, logged_output)
            self.assertIn("[REDACTED", logged_output)
        finally:
            test_logger.removeHandler(handler)

    def test_07_json_validation_and_security_guards(self) -> None:
        """Itens 3 e 4: Verifica validação JSON do modelo, sanitização anti-viralização e bloqueio de URLs/HTTP remoto."""
        raw_fenced = """```json
        {
          "candidates": [
            {
              "start_time_seconds": 10.0,
              "end_time_seconds": 45.0,
              "title": "Dica prática com viralização garantida na rede",
              "category": "dica_pratica",
              "summary": "Resumo estruturado explicando como organizar o áudio com clareza.",
              "rationale": "Justificativa técnica objetiva sem inventar métricas.",
              "transcript_excerpt": "Para organizar o áudio com clareza, configure o ganho do microfone corretamente.",
              "relevance_score": 85
            }
          ]
        }
        ```"""
        parsed = parse_and_validate_llm_candidates_json(raw_fenced)
        cand = ClipCandidate.model_validate(parsed[0])
        self.assertNotIn("viralização garantida", cand.title.lower())
        self.assertEqual(cand.duration_seconds, 35.0)

        with self.assertRaises(UnauthorizedSourceError):
            validate_authorized_local_media_file("https://youtube.com/watch?v=dQw4w9WgXcQ")

        with self.assertRaises(PermissionError):
            validate_backend_api_url("http://meu-dominio-remoto.com/pages/worker-api.php", require_https_for_remote=True)

    def test_08_real_faster_whisper_pyav_compat_and_multi_segment_clauses(self) -> None:
        """
        Testa transcrição real com faster-whisper (validando o shim de compatibilidade com PyAV 19
        em Python 3.13) e o analisador local sobre frases reais divididas em múltiplos segmentos
        separados por vírgula.
        """
        root_dir = Path(__file__).resolve().parent.parent
        sample_wav = root_dir / "storage" / "samples" / "authorized_demo_podcast.wav"
        if not sample_wav.is_file():
            from create_sample_media import create_sample_authorized_assets
            create_sample_authorized_assets(root_dir)

        cfg = WorkerConfig.from_env()
        self.assertFalse(cfg.allow_paid_api)
        transcriber = AudioTranscriber(cfg)
        usage = UsageRecord()

        doc = transcriber.transcribe_authorized_media(sample_wav, usage, authorized_confirmed=True)
        self.assertGreater(doc.duration_seconds, 5.0)
        self.assertGreaterEqual(len(doc.segments), 2)
        self.assertFalse(usage.paid_api_used)

        # Verifica que o analisador local consegue acumular múltiplos segmentos quando o 1º termina em vírgula
        multi_clause_doc = TranscriptDocument(
            language="pt",
            duration_seconds=35.0,
            segments=[
                TranscriptSegment(
                    start=0.0,
                    end=7.5,
                    text="Quando eu comecei a gravar vídeos educativos em casa, aconteceu uma história curiosa,",
                ),
                TranscriptSegment(
                    start=7.5,
                    end=18.2,
                    text="no começo achei que tinha perdido tudo na demonstração, mas aprendi a fazer um checklist simples",
                ),
                TranscriptSegment(
                    start=18.2,
                    end=26.5,
                    text="e o resultado foi nunca mais perder uma sessão importante de trabalho.",
                ),
            ],
        )
        analyzer = TranscriptAnalyzer(cfg)
        params = ClipSelectionParams(min_duration_seconds=15.0, max_duration_seconds=30.0, target_clips_count=2)
        cands = analyzer.analyze(multi_clause_doc, params, UsageRecord())
        self.assertGreaterEqual(len(cands), 1, "Deve acumular segmentos intermediários separados por vírgula.")
        self.assertEqual(cands[0].start_time_seconds, 0.0)
        self.assertEqual(cands[0].end_time_seconds, 26.5)

    def test_09_ffmpeg_clip_cutter_disk_space_and_temp_cleanup(self) -> None:
        """
        Testa o corte real de vídeo com FFmpeg (FFmpegClipCutter), verificação de espaço livre
        em disco e remoção de arquivos temporários (.part.mp4).
        """
        from clipia_worker.cutter import FFmpegClipCutter

        root_dir = Path(__file__).resolve().parent.parent
        sample_mp4 = root_dir / "storage" / "samples" / "authorized_demo_podcast.mp4"
        if not sample_mp4.is_file() or sample_mp4.stat().st_size < 4096:
            from create_sample_media import create_sample_authorized_assets
            create_sample_authorized_assets(root_dir)

        cfg = WorkerConfig.from_env()
        cutter = FFmpegClipCutter(cfg)

        candidates = [
            ClipCandidate(
                start_time_seconds=0.0,
                end_time_seconds=20.0,
                duration_seconds=20.0,
                title="Corte de teste FFmpeg",
                category="historia_completa",
                summary="Resumo técnico do corte de teste com FFmpeg.",
                rationale="Trecho completo com início, meio e fim para validação do FFmpeg.",
                transcript_excerpt="Quando eu comecei a gravar vídeos educativos em casa, aconteceu uma história curiosa.",
                relevance_score=88,
            )
        ]

        with tempfile.TemporaryDirectory() as tmp_dir_str:
            tmp_dir = Path(tmp_dir_str)
            cut_paths = cutter.cut_candidates(
                media_path=sample_mp4,
                candidates=candidates,
                params=ClipSelectionParams(aspect_ratio="9:16"),
                usage=UsageRecord(),
                output_dir=tmp_dir,
                project_id=99,
                job_id=99,
            )
            self.assertEqual(len(cut_paths), 1)
            self.assertTrue(candidates[0].storage_url)
            self.assertEqual(candidates[0].status, "ready")
            rendered_file = cut_paths[0]
            self.assertTrue(rendered_file.is_file())
            self.assertGreater(rendered_file.stat().st_size, 1024)
            # Garante que nenhum arquivo temporário .part.mp4 ficou órfão
            part_files = list(tmp_dir.glob("*.part.mp4"))
            self.assertEqual(len(part_files), 0)

            # Simula falta de espaço livre em disco
            with patch("shutil.disk_usage") as mock_du:
                mock_du.return_value = type("DU", (), {"total": 1000, "used": 990, "free": 10})()
                with self.assertRaises(OSError):
                    cutter.check_free_disk_space(tmp_dir)

    def test_10_subtitles_srt_ass_word_highlights_burnin_and_local_metadata(self) -> None:
        """
        Testa o sistema completo de legendas sincronizadas (SRT + ASS + FFmpeg burn-in 9:16),
        destaque estático de palavras importantes quando há timestamps confiáveis, personalização
        de fonte/tamanho/cores/posição/estilo e geração local gratuita de título, descrição e hashtags.
        """
        from clipia_worker.cutter import FFmpegClipCutter
        from clipia_worker.models import SubtitleStyleConfig, TranscriptWord
        from clipia_worker.subtitles import SubtitleGenerator, generate_clip_metadata_locally

        root_dir = Path(__file__).resolve().parent.parent
        sample_mp4 = root_dir / "storage" / "samples" / "authorized_demo_podcast.mp4"
        if not sample_mp4.is_file() or sample_mp4.stat().st_size < 4096:
            from create_sample_media import create_sample_authorized_assets
            create_sample_authorized_assets(root_dir)

        cfg = WorkerConfig.from_env()
        self.assertFalse(cfg.allow_paid_api)

        # 1. Testa geração local gratuita de título, descrição curta e hashtags
        excerpt = (
            "A melhor dica prática para quem está começando a produzir conteúdo técnico é aplicar a regra dos três passos. "
            "O primeiro passo é testar o microfone por dez segundos antes de começar a falar o conteúdo principal."
        )
        title_gen, short_desc_gen, hashtags_gen = generate_clip_metadata_locally(excerpt, "dica_pratica")
        self.assertTrue(len(title_gen) >= 12)
        self.assertTrue(len(short_desc_gen) >= 25)
        self.assertGreaterEqual(len(hashtags_gen), 4)
        self.assertTrue(all(tag.startswith("#") for tag in hashtags_gen))

        # 2. Testa sincronização de legendas com timestamps de palavras confiáveis e destaque estático
        doc = TranscriptDocument(
            language="pt",
            duration_seconds=30.0,
            segments=[
                TranscriptSegment(
                    start=0.0,
                    end=6.0,
                    text="A melhor dica prática é testar dez segundos de áudio.",
                    words=[
                        TranscriptWord(word="A", start=0.0, end=0.4, probability=0.95),
                        TranscriptWord(word="melhor", start=0.4, end=1.0, probability=0.94),
                        TranscriptWord(word="dica", start=1.0, end=1.6, probability=0.96),
                        TranscriptWord(word="prática", start=1.6, end=2.3, probability=0.95),
                        TranscriptWord(word="é", start=2.3, end=2.6, probability=0.92),
                        TranscriptWord(word="testar", start=2.6, end=3.3, probability=0.91),
                        TranscriptWord(word="dez", start=3.3, end=3.9, probability=0.97),
                        TranscriptWord(word="segundos", start=3.9, end=4.8, probability=0.93),
                        TranscriptWord(word="de", start=4.8, end=5.1, probability=0.90),
                        TranscriptWord(word="áudio.", start=5.1, end=6.0, probability=0.95),
                    ],
                )
            ],
        )

        custom_style = SubtitleStyleConfig(
            burn_subtitles=True,
            export_srt=True,
            font_family="Verdana",
            font_size="xlarge",
            primary_color="#FFFFFF",
            highlight_color="#FACC15",
            outline_color="#000000",
            position="bottom",
            style_preset="clean_outline",
            highlight_keywords=True,
        )

        cand = ClipCandidate(
            start_time_seconds=0.0,
            end_time_seconds=12.0,
            duration_seconds=12.0,
            title=title_gen,
            short_description=short_desc_gen,
            hashtags=hashtags_gen,
            category="dica_pratica",
            summary="Resumo técnico sobre teste de microfone.",
            rationale="Dica prática completa.",
            transcript_excerpt="A melhor dica prática é testar dez segundos de áudio.",
            relevance_score=90,
            subtitle_style=custom_style,
        )

        cues = SubtitleGenerator.build_cues_for_candidate(cand, doc, custom_style, "9:16")
        self.assertGreaterEqual(len(cues), 2)
        self.assertTrue(any(len(c.highlighted_words) > 0 for c in cues))

        srt_text = SubtitleGenerator.format_srt(cues)
        self.assertIn("00:00:00,000 -->", srt_text)
        self.assertIn("A melhor dica\nprática é testar", srt_text)

        ass_text = SubtitleGenerator.format_ass(cues, custom_style, 720, 1280)
        self.assertIn("Style: Default,Verdana,66", ass_text)
        self.assertIn("{\\c&H0015CCFA}", ass_text)  # Cor de destaque estática (#FACC15 -> ASS &H0015CCFA)
        # Garante que NÃO há animações que prejudiquem a leitura
        self.assertNotIn("\\move(", ass_text)
        self.assertNotIn("\\t(", ass_text)
        self.assertNotIn("\\fad(", ass_text)

        # 3. Testa corte FFmpeg com legenda incorporada (burn_subtitles=True) vs SRT separado (burn_subtitles=False)
        cutter = FFmpegClipCutter(cfg)
        with tempfile.TemporaryDirectory() as tmp_dir_str:
            tmp_dir = Path(tmp_dir_str)
            usage_burn = UsageRecord()
            paths_burn = cutter.cut_candidates(
                media_path=sample_mp4,
                candidates=[cand],
                params=ClipSelectionParams(min_duration_seconds=10.0, max_duration_seconds=20.0, subtitle_style=custom_style),
                usage=usage_burn,
                document=doc,
                output_dir=tmp_dir,
                project_id=77,
                job_id=1,
            )
            self.assertEqual(len(paths_burn), 1)
            self.assertTrue(paths_burn[0].is_file())
            self.assertTrue(cand.subtitles_burned)
            self.assertIsNotNone(cand.srt_storage_url)
            srt_file_1 = tmp_dir / Path(str(cand.srt_storage_url)).name
            self.assertTrue(srt_file_1.is_file())
            self.assertEqual(len(list(tmp_dir.glob(".tmp_*.ass"))), 0, "Arquivos .ass temporários devem ser limpos.")

            # Agora com burn_subtitles=False (vídeo limpo + SRT separado)
            clean_style = custom_style.model_copy(update={"burn_subtitles": False})
            cand_clean = cand.model_copy(update={"subtitle_style": clean_style})
            usage_clean = UsageRecord()
            paths_clean = cutter.cut_candidates(
                media_path=sample_mp4,
                candidates=[cand_clean],
                params=ClipSelectionParams(min_duration_seconds=10.0, max_duration_seconds=20.0, subtitle_style=clean_style),
                usage=usage_clean,
                document=doc,
                output_dir=tmp_dir,
                project_id=77,
                job_id=2,
            )
            self.assertEqual(len(paths_clean), 1)
            self.assertTrue(paths_clean[0].is_file())
            self.assertFalse(cand_clean.subtitles_burned)
            srt_file_2 = tmp_dir / Path(str(cand_clean.srt_storage_url)).name
            self.assertTrue(srt_file_2.is_file())


if __name__ == "__main__":
    unittest.main(verbosity=2)
