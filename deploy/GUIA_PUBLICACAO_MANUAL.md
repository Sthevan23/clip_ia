# Guia de publicação manual — ClipIA na Hostinger (hPanel)

**Site alvo:** https://clipia.sthevandev.com.br/  
**Este guia não prova que a produção está OK** — use também [`CHECKLIST_VALIDACAO_PRODUCAO.md`](CHECKLIST_VALIDACAO_PRODUCAO.md) para registrar resultados.

**Pacote no PC:** `deploy/clipia-hostinger-public.zip` (47 entradas, inspecionado localmente)  
**Documentos relacionados:** [`PREPARACAO_PUBLICACAO.md`](PREPARACAO_PUBLICACAO.md) · [`HOSTINGER.md`](HOSTINGER.md) · [`WORKER_WINDOWS.md`](WORKER_WINDOWS.md)

| Caminho no servidor | Uso |
|---------------------|-----|
| `/home/u586160337/domains/sthevandev.com.br/public_html/clipia/` | Aplicação PHP (pública) |
| `/home/u586160337/domains/sthevandev.com.br/private/clipia.env.php` | Credenciais (**fora** de `public_html`) |

---

## Parte 1 — Antes de subir arquivos (hPanel)

### 1.1 PHP

1. hPanel → **Websites** → seu site → **PHP Configuration** (ou **Avançado → Configuração PHP**).
2. Selecione **PHP 8.2** ou **8.3**.
3. Defina:
   - `display_errors` = **Off**
   - `display_startup_errors` = **Off**
   - `upload_max_filesize` = **128M** (mínimo 100M)
   - `post_max_size` = **128M**
   - `max_execution_time` = **120** (ou superior)
   - `memory_limit` = **256M** (recomendado)

O código exige PHP 8.2+ (`config/app.php`).

### 1.2 SSL / HTTPS

1. hPanel → **SSL** → certificado **Ativo** para o domínio.
2. Ative **Forçar HTTPS** se disponível.
3. No env privado (mais adiante), use `APP_URL` = `https://clipia.sthevandev.com.br` e `WORKER_REQUIRE_HTTPS` = `1`.

### 1.3 MySQL — criar banco e usuário

1. hPanel → **Bancos de dados MySQL** → **Criar**.
2. Anote **nome do banco** e **usuário** (ex.: prefixo `u586160337_`).
3. Defina uma **senha forte** e guarde em local seguro (não envie em chats).
4. Associe o usuário ao banco com **todos os privilégios** no banco do ClipIA.
5. No hPanel, confira o **host MySQL** (muitas vezes `127.0.0.1` para conexões na mesma hospedagem).

---

## Parte 2 — Enviar o pacote PHP

### 2.1 Upload e extração

1. No PC, confirme o arquivo: `projeto_clips\deploy\clipia-hostinger-public.zip`.
2. hPanel → **Gerenciador de arquivos** (ou FTP/SFTP: FileZilla / WinSCP).
3. Navegue até **`public_html/`** e crie a pasta **`clipia`** se não existir.
4. Entre em **`public_html/clipia/`**.
5. Envie **`clipia-hostinger-public.zip`**.
6. No Gerenciador, **extraia** o ZIP **dentro** de `clipia/` (conteúdo na raiz da pasta `clipia`, não em subpasta extra sem querer).

**Alternativa:** extraia o ZIP no PC e envie por FTP todos os arquivos para `public_html/clipia/`.

### 2.2 Conferir extração

Na raiz de `public_html/clipia/` devem existir, entre outros:

| Arquivo / pasta | Obrigatório |
|-----------------|-------------|
| `index.php` | Sim |
| `.htaccess` | Sim |
| `LEIA-ME-HOSTINGER.txt` | Sim (instruções resumidas) |
| `login.php`, `dashboard.php`, `projects.php` | Sim |
| `config/app.php`, `config/schema.sql`, `config/clipia_env_loader.php` | Sim |
| `pages/worker-api.php` | Sim (API worker; protegida por HMAC) |
| `storage/uploads/`, `storage/clips/`, `storage/logs/` | Sim (`.gitkeep` + `.htaccess`) |

### 2.3 O que **não** deve existir em `public_html/clipia/`

Se encontrar qualquer item abaixo, **pare** e remova antes de continuar:

- `worker_service/` (Python — fica só no seu PC)
- Qualquer arquivo `.env`
- `config/env.production.php`, `config/env.local.php`, `config/env.example.php`
- `storage/samples/`, `storage/audit_*`, `import_audit_demo.php`
- Vídeos `.mp4`/`.wav`, logs `.log`, pasta `deploy/` do repositório

**Pode existir:** `includes/audit_demo_importer.php` (necessário para `projects.php`; não é mídia de demo).

---

## Parte 3 — Importar o banco (phpMyAdmin)

1. hPanel → **phpMyAdmin** → selecione o **banco** criado para o ClipIA.
2. Aba **Importar** → escolha o arquivo no servidor:  
   `public_html/clipia/config/schema.sql`
3. Execute a importação (sem erros fatais).

### 3.1 Oito tabelas esperadas

O `schema.sql` cria exatamente:

1. `users`  
2. `projects`  
3. `source_videos`  
4. `clip_candidates`  
5. `processing_jobs`  
6. `usage_logs`  
7. `monthly_budgets`  
8. `budget_reservations`  

**Verificação (aba SQL):**

```sql
SELECT COUNT(*) AS tabelas_clipia
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
    'users','projects','source_videos','clip_candidates',
    'processing_jobs','usage_logs','monthly_budgets','budget_reservations'
  );
```

Resultado esperado: **`tabelas_clipia = 8`**.

Essas tabelas correspondem ao uso em `includes/repositories.php` e `pages/worker-api.php`.

---

## Parte 4 — Arquivo privado `clipia.env.php`

### 4.1 Onde criar

**Nunca** dentro de `public_html/clipia/config/`.

Caminho absoluto no servidor:

`/home/u586160337/domains/sthevandev.com.br/private/clipia.env.php`

1. No Gerenciador ou FTP, suba até `domains/sthevandev.com.br/` (irmão de `public_html`).
2. Crie a pasta **`private`** (permissão **700**).
3. Crie o arquivo **`clipia.env.php`** (permissão **600**).

Se a UI do hPanel não permitir subir acima de `public_html`, use **FTP/SFTP** com o usuário da Hostinger.

O loader `config/clipia_env_loader.php` procura esse caminho quando o app está em `public_html/clipia/`.

### 4.2 Modelo e chaves (sem valores reais neste guia)

No **PC**, abra o repositório Git: **`config/env.production.example.php`**.  
Copie a estrutura `<?php return [ ... ];` para o servidor e substitua placeholders.

| Chave | Função |
|-------|--------|
| `APP_ENV` | Deve ser `production` |
| `APP_URL` | `https://clipia.sthevandev.com.br` |
| `APP_TIMEZONE` | Ex.: `America/Sao_Paulo` |
| `DB_DRIVER` | `mysql` |
| `DB_HOST` | Valor do hPanel (geralmente `127.0.0.1`) |
| `DB_PORT` | `3306` |
| `DB_NAME` | Nome do banco criado |
| `DB_USER` | Usuário MySQL |
| `DB_PASS` | Senha MySQL (**obrigatória** para PDO; o 503 não lista `DB_PASS`, mas login falha sem ela) |
| `WORKER_SHARED_SECRET` | Segredo HMAC (**≥ 16 caracteres**; gere no PC, não use placeholder do exemplo) |
| `WORKER_REQUIRE_HTTPS` | `1` |
| `ADMIN_OWNER_EMAIL` | E-mail do proprietário (se `users` vazia) |
| `ADMIN_OWNER_PASSWORD_HASH` | Hash bcrypt (se `users` vazia) |

**Gerar hash admin no PC (não commite a senha):**

```powershell
php -r "echo password_hash('SuaSenhaForteAqui', PASSWORD_DEFAULT);"
```

**Gerar segredo HMAC (use o mesmo no worker Windows depois):**

```powershell
php -r "echo bin2hex(random_bytes(32));"
```

Opcionais no modelo: `CLIPIA_MAX_MONTHLY_COST_BRL` (100), `CLIPIA_MAX_COST_PER_JOB_BRL` (2.50), `STORAGE_*`, `WORKER_JOB_STALE_TIMEOUT_SECONDS` (1800).

---

## Parte 5 — Administrador inicial

- Se a tabela **`users` está vazia:** no **primeiro login POST** bem-sucedido, `UserRepository::ensureOwnerAccountIfEmpty()` cria o admin usando `ADMIN_OWNER_EMAIL` e `ADMIN_OWNER_PASSWORD_HASH` do env privado.
- Se **`users` já tem registros:** o env admin **não** altera senhas existentes — use login com conta já cadastrada.
- Em produção **não** use `ADMIN_OWNER_PASSWORD` em texto claro (`config/app.php` deixa default vazio em production).

---

## Parte 6 — Validar o site (navegador)

Execute **você** no navegador (não substitui o checklist):

| # | URL / ação | Esperado |
|---|------------|----------|
| 1 | https://clipia.sthevandev.com.br/ | **Sem** “Configuração do servidor incompleta” (HTTP 503) |
| 2 | `/login.php` | Formulário; **sem** erro de banco na tela |
| 3 | Login com e-mail/senha que você definiu | Redireciona ao dashboard |
| 4 | `/register.php` | Cadastro público **bloqueado** (`PUBLIC_REGISTRATION_ENABLED=false` em `config/app.php`) |
| 5 | `/projects.php` | Só após login; lista de projetos |
| 6 | Tentativa de login com senha errada | Mensagem genérica “Credenciais inválidas” |

---

## Parte 7 — Logs e erros 503 / 500

| Sintoma | Onde olhar | Causa provável |
|---------|------------|----------------|
| **503** “Configuração incompleta” | `public_html/clipia/storage/logs/php_errors.log` | `private/clipia.env.php` ausente, incompleto ou não carregado; `WORKER_SHARED_SECRET` curto; `DB_NAME`/`DB_USER` vazios |
| Erro de **banco** no login | Mesmo log + conferir env | `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` incorretos |
| **500** genérico | Log PHP | Erro de sintaxe no env, permissões, extensão PHP faltando |
| Login falha sem erro de banco | phpMyAdmin `users` | Admin não criado; hash/e-mail incorretos no env |

**Não** cole senhas ou trechos completos do `clipia.env.php` em chats.

---

## Parte 8 — Worker Windows (somente após site OK)

**Pré-requisitos:** Parte 6 OK; checklist D.3–D.7 em [`CHECKLIST_VALIDACAO_PRODUCAO.md`](CHECKLIST_VALIDACAO_PRODUCAO.md); **mesmo comprimento** de HMAC no servidor e no PC (não compare o valor em locais inseguros).

1. Siga [`WORKER_WINDOWS.md`](WORKER_WINDOWS.md): venv, `pip install -r requirements.txt`, `.env` a partir de `.env.example`.
2. No **`worker_service/.env`** (PC, gitignored):

   - `CLIPIA_BACKEND_API_URL=https://clipia.sthevandev.com.br/pages/worker-api.php`
   - `CLIPIA_WORKER_SHARED_SECRET=` **igual** a `WORKER_SHARED_SECRET` no servidor
   - `CLIPIA_VERIFY_TLS=true`
   - `CLIPIA_AI_PROVIDER=local_heuristic`
   - `CLIPIA_ALLOW_PAID_API=false`

3. Na raiz do projeto: `.\deploy\verify-local.ps1` → `hmac_ready=True`, `allow_paid_api=False`.

**Não** use `--poll` na primeira vez.

---

## Parte 9 — Teste controlado de vídeo (Fase C)

Somente após **Parte 6** + testes de segurança da API (checklist Fase B) + **autorização explícita**:

1. Login no painel → **Novo projeto** → envie um **MP4 pequeno** autorizado (seu arquivo de teste, sem dados sensíveis).
2. No PC, com venv ativo:

   ```powershell
   Set-Location C:\Users\Administrador\OneDrive\Desktop\projeto_clips\worker_service
   python main.py --process-next
   ```

   (**Uma** execução — não é polling contínuo.)

3. No site: projeto **Concluído**, clipes e SRT visíveis conforme o painel.
4. Se **401/403** na API: pare; revise HMAC, HTTPS e relógio do Windows — [`HOSTINGER.md`](HOSTINGER.md) §9.

---

## Parte 10 — Requisitos de segurança (produção)

| Requisito | Onde |
|-----------|------|
| `APP_ENV=production` | `private/clipia.env.php` |
| `APP_URL` HTTPS | Env privado |
| MySQL correto | `DB_*` no env |
| HMAC forte (≠ placeholder) | `WORKER_SHARED_SECRET` |
| HTTPS na API worker | `WORKER_REQUIRE_HTTPS=1` |
| APIs pagas off | Worker: `CLIPIA_ALLOW_PAID_API=false`; PHP orçamento via regras do app |
| Provedor local | Worker: `CLIPIA_AI_PROVIDER=local_heuristic` |
| Erros ocultos na tela | hPanel `display_errors=Off`; `config/app.php` em production |
| Env fora da web | `private/clipia.env.php` |
| Permissões | `private/` 700, arquivo 600 |
| Cadastro público bloqueado | `register.php` + `PUBLIC_REGISTRATION_ENABLED=false` |
| API worker | POST + HMAC (`X-ClipIA-Timestamp`, `X-ClipIA-Signature`); janela ±300 s |

---

## Checklist pós-instalação (preencha Resultado: OK / FALHA / PENDENTE)

### Fase A — Site

| # | Item | Resultado |
|---|------|-----------|
| A1 | HTTPS válido | |
| A2 | Sem HTTP 503 na home | |
| A3 | Login OK | |
| A4 | `/register.php` bloqueado | |
| A5 | `/projects.php` só autenticado | |
| A6 | MySQL: 8 tabelas + login sem erro PDO | |

### Fase B — Segurança

| # | Item | Resultado |
|---|------|-----------|
| B1 | POST `pages/worker-api.php` **sem** HMAC → 401 (não dispare `claim_next_job` em loop) | |
| B2 | HMAC inválido/expirado → rejeitado (401) | |
| B3 | Requisição inválida não processa jobs | |
| B4 | `private/clipia.env.php` não acessível via URL | |
| B5 | `public_html/clipia/` sem `worker_service/`, `.env`, samples | |

### Fase C — Worker (depois de A e B)

| # | Item | Resultado |
|---|------|-----------|
| C1 | Worker: HTTPS + `CLIPIA_VERIFY_TLS=true` | |
| C2 | Comprimento HMAC PC = servidor | |
| C3 | Uma execução `python main.py --process-next` | |
| C4 | Projeto concluído no painel | |
| C5 | `CLIPIA_ALLOW_PAID_API=false` mantido | |

---

**Status:** guia preparado para instalação manual; **produção não validada** até você preencher o checklist acima.
