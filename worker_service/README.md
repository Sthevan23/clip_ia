# ClipIA Worker Service — Módulo de Análise Inteligente em Python

> **IMPORTANTE:** Este serviço externo de processamento em Python **não deve ser instalado dentro da hospedagem compartilhada Hostinger**. Ele foi projetado para rodar separadamente no computador de desenvolvimento Windows ou em um servidor dedicado compatível (VPS/GPU), comunicando-se com a aplicação PHP/MySQL por meio de uma API protegida com assinatura HMAC-SHA256 (`pages/worker-api.php`).

---

## 1. Arquitetura Modular (`worker_service/`)

- `requirements.txt` — Dependências Python (`faster-whisper`, `pydantic`, `httpx`, `python-dotenv`).
- `.env.example` — Variáveis de ambiente comentadas para transcrição, provedor de IA (`local_heuristic` / `openai_compatible`), limites técnicos e teto mensal de R$ 100,00.
- `clipia_worker/config.py` — Configuração tipada (`WorkerConfig`), parâmetros do `faster-whisper`, janelas de contexto, modelo de IA configurável por variável de ambiente (`CLIPIA_LLM_MODEL`) e travas de orçamento.
- `clipia_worker/budget.py` — Gerenciador persistente de orçamento (`PersistentBudgetGuard` e `BudgetExceededError`), que armazena o consumo mensal em disco (`storage/budget_ledger.json`) com escrita atômica e sincroniza com o MySQL/SQLite.
- `clipia_worker/security.py` — Validação de fontes autorizadas (bloqueia URLs arbitrárias), exigência de HTTPS para servidores remotos, assinaturas HMAC-SHA256 e filtro de logs (`SensitiveDataLogFilter` / `redact_sensitive_text`).
- `clipia_worker/models.py` — Schemas Pydantic (`TranscriptSegment`, `TranscriptDocument`, `TranscriptChunk`, `ClipCandidate`, `ClipSelectionParams`, `UsageRecord`), sanitização contra promessas de viralização e validador estrito de JSON (`parse_and_validate_llm_candidates_json`).
- `clipia_worker/transcriber.py` — Transcrição local via `faster-whisper` (`tiny`, `base`, `small`, `medium`, `large-v3` em `cpu` ou `cuda`) ou carregamento de transcrição já gerada com timestamps.
- `clipia_worker/chunker.py` — Divisão da transcrição em blocos com janela deslizante (`window_seconds` + `overlap_seconds`) e resumo de contexto do bloco anterior.
- `clipia_worker/analyzer.py` — Seleção dos melhores trechos por categoria (`historia_completa`, `dica_pratica`, `curiosidade`, `humor`, `opiniao`, `explicacao`), enviando à API de texto **apenas os segmentos do bloco atual** (nunca o vídeo inteiro), com contingência local automática em caso de erro, timeout ou bloqueio de orçamento.
- `clipia_worker/api_client.py` e `main.py` — Cliente HTTP autenticado e CLI principal.
- `test_audit_suite.py`, `test_openai_provider.py` e `test_integration.py` — Suítes completas de auditoria, testes com mocks da OpenAI e teste de integração ponta a ponta.

---

## 2. Instruções de Instalação e Execução no Windows (PowerShell)

### Passo 1: Criar e ativar o ambiente virtual Python

Abra o **PowerShell** na pasta raiz do projeto (`projeto_clips`) e execute:

```powershell
cd worker_service
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
```

### Passo 2: Configurar o arquivo `.env`

Copie o arquivo `.env.example` para `.env`:

```powershell
Copy-Item .env.example .env
```

Principais variáveis em `worker_service/.env`:
- `OPENAI_API_KEY` — chave de autenticação da OpenAI API (lida prioritariamente por `WorkerConfig.from_env()` em `worker_service/clipia_worker/config.py`; nunca deve ser colocada no PHP, JS, HTML ou `public_html` da Hostinger)
- `CLIPIA_AI_PROVIDER=local_heuristic` (padrão gratuito) ou `openai_compatible`
- `CLIPIA_ALLOW_PAID_API=false` (padrão `false`: impede qualquer cobrança acidental mesmo que `OPENAI_API_KEY` esteja definida)
- `CLIPIA_LLM_MODEL=gpt-4o-mini` (configurável por variável de ambiente)
- `CLIPIA_MAX_COST_PER_JOB_BRL=2.50` (limite de gasto por tarefa)
- `CLIPIA_MAX_MONTHLY_COST_BRL=100.00` (teto mensal global persistente de R$ 100,00)
- `CLIPIA_MIN_COST_PER_CALL_BRL=0.02` (piso mínimo contabilizado por chamada HTTP, não dependendo apenas de estimativa de tokens)

---

## 3. Execução dos Testes no Windows PowerShell

Na raiz do repositório (`projeto_clips`), execute:

```powershell
# 1. Suíte de auditoria offline (transcrição fictícia, limites e proteção de segredos)
python worker_service/test_audit_suite.py

# 2. Suíte de testes com mocks da OpenAI (resposta válida, JSON inválido, timeout, erro 401, limite por tarefa/mensal excedido e falha 500)
python worker_service/test_openai_provider.py

# 3. Teste de integração ponta a ponta (PHP + Banco + Worker Python)
python worker_service/test_integration.py
```
