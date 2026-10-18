# ClipIA — Plataforma de Cortes Inteligentes (Uso Exclusivo do Proprietário • Hostinger Business + Worker Python Único)

O **ClipIA** é uma aplicação web desenvolvida em **PHP 8.2+**, **MySQL (PDO)**, **HTML5**, **CSS3** e **JavaScript puro**, projetada para rodar em hospedagem compartilhada **Hostinger Business** em modo de **uso individual exclusivo do proprietário** (sem cadastro público aberto), enquanto o processamento de transcrição de áudio (`faster-whisper`) e seleção inteligente de trechos roda em um **único worker externo separado em Python (`worker_service/`)**.

---

## 1. Arquitetura para Uso Exclusivo do Proprietário

- **Acesso Restrito ao Proprietário (Sem Cadastro Público)**:
  - `PUBLIC_REGISTRATION_ENABLED = false` em `config/app.php`.
  - `register.php` bloqueia qualquer tentativa de cadastro público (`HTTP 403` em `POST`) e redireciona para `login.php`.
  - O login administrativo (`login.php`) autentica a conta do proprietário no banco MySQL com `password_verify()`, regeneração de ID de sessão e CSRF. Caso a tabela `users` esteja vazia na primeira execução, `UserRepository::ensureOwnerAccountIfEmpty()` inicializa automaticamente o administrador a partir de `ADMIN_OWNER_EMAIL` e `ADMIN_OWNER_PASSWORD_HASH` (sem tocar em usuários, projetos, vídeos, cortes ou históricos já existentes).
- **Processamento em Worker Python Único (`worker_service/`)**:
  - Protegido por lock de instância única (`acquire_single_worker_instance_lock` em `storage/single_worker.lock`) com detecção e limpeza automática de PID órfão se o processo anterior tiver sido interrompido.
  - No banco de dados, `WorkerJobRepository::claimNextAuthorizedJob()` executa reserva atômica (`SELECT ... FOR UPDATE` + `UPDATE ... WHERE status = 'queued'`) e `WorkerJobRepository::recoverStaleRunningJobs()` recupera automaticamente tarefas interrompidas em `running` após `WORKER_JOB_STALE_TIMEOUT_SECONDS` (`1800s`).
- **Análise Gratuita Local Padrão + Trava de Orçamento R$ 100/mês e R$ 2,50/tarefa**:
  - Por padrão, opera em `CLIPIA_AI_PROVIDER=local_heuristic` com `faster-whisper` local e `CLIPIA_ALLOW_PAID_API=false` (custo zero).
  - Quando a API paga for ativada futuramente (`CLIPIA_ALLOW_PAID_API=true`), cada chamada realiza reserva atômica pré-chamada (`reserve_budget`) e liquidação (`settle_budget`) nas tabelas `monthly_budgets` e `budget_reservations`, além do ledger local em disco, respeitando estritamente o limite de **R$ 2,50 por tarefa** e o **teto mensal de R$ 100,00**.

---

## 2. Checklist de Publicação na Hostinger Business

### 2.1. Variáveis do Backend PHP (`/home/uXXXXXXXXX/clipia_secrets/env.production.php`)

Crie a pasta `/home/uXXXXXXXXX/clipia_secrets` **fora de `public_html`** (permissão `0700`) e o arquivo `env.production.php` (permissão `0600`):

```php
<?php
return [
    'APP_ENV'                          => 'production',
    'APP_URL'                          => 'https://seudominio.com.br',
    'APP_TIMEZONE'                     => 'America/Sao_Paulo',

    // Conta Administrativa Inicial do Proprietário (usada apenas se a tabela users estiver vazia)
    'ADMIN_OWNER_NAME'                 => 'Administrador ClipIA',
    'ADMIN_OWNER_EMAIL'                => 'admin@seudominio.com.br',
    // Gere o hash no terminal com: php -r "echo password_hash('SuaSenhaForteAqui', PASSWORD_DEFAULT);"
    'ADMIN_OWNER_PASSWORD_HASH'        => '<HASH_BCRYPT_DA_SENHA_DO_PROPRIETARIO>',

    // Banco de Dados MySQL (Hostinger Business)
    'DB_DRIVER'                        => 'mysql',
    'DB_HOST'                          => '127.0.0.1',
    'DB_PORT'                          => '3306',
    'DB_NAME'                          => 'uXXXXXXXXX_clipia',
    'DB_USER'                          => 'uXXXXXXXXX_admin',
    'DB_PASS'                          => '<SENHA_FORTE_DO_MYSQL>',

    // Segurança entre PHP e Worker Python Único (HMAC + HTTPS)
    'WORKER_SHARED_SECRET'             => '<SEGREDO_HMAC_ALEATORIO_DE_64_CARACTERES>',
    'WORKER_REQUIRE_HTTPS'             => '1',
    'WORKER_JOB_STALE_TIMEOUT_SECONDS' => '1800',

    // Limites de Orçamento no Banco de Dados (para quando a API paga for ativada futuramente)
    'CLIPIA_MAX_MONTHLY_COST_BRL'      => '100.00',
    'CLIPIA_MAX_COST_PER_JOB_BRL'      => '2.50',

    // Limites de Espaço e Expiração de Mídia (storage/uploads)
    'STORAGE_MAX_FILE_SIZE_BYTES'      => '104857600',  // 100 MB por vídeo
    'STORAGE_MAX_USER_QUOTA_BYTES'     => '524288000',  // 500 MB
    'STORAGE_MAX_TOTAL_QUOTA_BYTES'    => '5368709120', // 5 GB total
    'STORAGE_MEDIA_RETENTION_HOURS'    => '72',         // Expiração após 72 horas
];
```

### 2.2. Variáveis do Worker Python Único (`worker_service/.env`)

Na máquina externa do proprietário (Windows ou VPS separada), configure `worker_service/.env`:

```ini
CLIPIA_BACKEND_API_URL=https://seudominio.com.br/pages/worker-api.php
CLIPIA_WORKER_SHARED_SECRET=<MESMO_SEGREDO_HMAC_DO_PHP>
CLIPIA_VERIFY_TLS=true

CLIPIA_WHISPER_MODEL=base
CLIPIA_WHISPER_DEVICE=cpu
CLIPIA_WHISPER_COMPUTE_TYPE=int8
CLIPIA_WHISPER_LANGUAGE=pt

CLIPIA_CHUNK_WINDOW_SECONDS=120
CLIPIA_CHUNK_OVERLAP_SECONDS=25

# Análise local gratuita padrão (API paga desativada por padrão)
CLIPIA_AI_PROVIDER=local_heuristic
CLIPIA_ALLOW_PAID_API=false
CLIPIA_LLM_BASE_URL=https://api.openai.com/v1
OPENAI_API_KEY=your_openai_api_key_here_do_not_commit
CLIPIA_LLM_MODEL=gpt-4o-mini
CLIPIA_LLM_TIMEOUT_SECONDS=30

CLIPIA_MAX_AUDIO_DURATION_SECONDS=5400
CLIPIA_MAX_LLM_CALLS_PER_JOB=12
CLIPIA_MAX_LLM_TOKENS_PER_JOB=24000
CLIPIA_MAX_RETRIES_PER_TASK=2

CLIPIA_MAX_COST_PER_JOB_BRL=2.50
CLIPIA_MAX_MONTHLY_COST_BRL=100.00
CLIPIA_USD_TO_BRL_RATE=5.80
CLIPIA_INPUT_COST_PER_1M_USD=0.15
CLIPIA_OUTPUT_COST_PER_1M_USD=0.60
CLIPIA_MIN_COST_PER_CALL_BRL=0.02
CLIPIA_BUDGET_LEDGER_PATH=../storage/budget_ledger.json
```

---

## 3. Etapas de Configuração Manual no Painel da Hostinger (hPanel)

1. **Certificado SSL / HTTPS**:
   - Em **hPanel → Segurança → SSL**, verifique se o certificado SSL está **Ativo** e habilite **Forçar HTTPS**.
2. **Versão do PHP e Limites**:
   - Em **hPanel → Avançado → Configuração PHP**, selecione **PHP 8.2** (ou 8.3).
   - Em **Opções de PHP**, configure: `upload_max_filesize = 128M`, `post_max_size = 128M`, `max_execution_time = 120`, `memory_limit = 256M` e `display_errors = Off`.
3. **Banco de Dados MySQL**:
   - Em **hPanel → Bancos de Dados → Gerenciamento**, crie o banco de dados e o usuário MySQL.
   - No **phpMyAdmin**, importe `config/schema.sql`.
4. **Arquivos e Credenciais Fora da Pasta Pública**:
   - Envie os arquivos da aplicação PHP para `public_html/` (sem enviar `worker_service/`).
   - Crie `/home/uXXXXXXXXX/clipia_secrets/env.production.php` fora de `public_html/` com as credenciais reais e o hash da senha administrativa do proprietário.
   - Verifique se `public_html/storage/uploads` e `public_html/storage/logs` possuem permissão `750` (ou `755`).
