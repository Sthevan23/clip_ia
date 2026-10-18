# Deploy ClipIA na Hostinger (MySQL)

## 1. Gerar o ZIP para subir no `public_html`

Na raiz do projeto (Windows PowerShell):

```powershell
.\deploy\build-hostinger-package.ps1
```

Saída: **`deploy/clipia-hostinger-public.zip`**

Conteúdo: só PHP + assets + pastas graváveis vazias. **Não inclui** `worker_service/`, amostras de vídeo, SQLite, scripts de auditoria local nem credenciais.

## 2. MySQL (hPanel)

1. Crie banco + usuário em **Bancos de dados**.
2. Abra **phpMyAdmin** e importe o arquivo **`config/schema.sql`** (está dentro do ZIP ou no repositório Git).

## 3. Enviar arquivos

1. Extraia o ZIP dentro de **`public_html/`** (ou envie o ZIP pelo Gerenciador de Arquivos e extraia lá).
2. Permissões graváveis: **`storage/uploads`**, **`storage/clips`**, **`storage/logs`** → `755` ou `750`.

## 4. Credenciais fora do site (obrigatório)

O Auto Deploy publica o repositório em **`public_html/clipia/`** (raiz do app = pasta `clipia`). Credenciais de produção **nunca** devem ir para o repositório.

### Opção recomendada na Hostinger: pasta `private/` na raiz do domínio

Com o app em `public_html/clipia/`, o `config/app.php` resolve:

- `$projectRootDir` → `.../public_html/clipia`
- `$parentOfPublicRoot` → `.../public_html`
- `$domainRootDir` → `.../domains/sthevandev.com.br`

O arquivo privado deve ficar em **`$domainRootDir/private/`** (fora de `public_html`), por exemplo:

`/home/u586160337/domains/sthevandev.com.br/private/clipia.env.php`

Crie **manualmente** no servidor (Gerenciador de Arquivos, FTP ou SFTP):

- Pasta **`private`** em **`domains/sthevandev.com.br/private/`** (irmã de `public_html`, **não** dentro de `public_html/clipia` nem de `public_html/private`).
- Arquivo: **`clipia.env.php`** — deve **`return [ ... ];`** (array associativo PHP), no mesmo formato de **`config/env.production.example.php`**.
- Permissões sugeridas: pasta `private` **700**, arquivo **600**.
- **Não** envie `clipia.env.php` ao GitHub; **não** coloque cópia preenchida no projeto local versionado.

Chaves que o painel precisa (preencha no servidor com valores reais; abaixo só nomes, sem segredos):

| Chave | Finalidade |
|--------|------------|
| `APP_ENV` | `production` |
| `APP_URL` | URL HTTPS pública do site |
| `APP_TIMEZONE` | Ex.: `America/Sao_Paulo` |
| `DB_DRIVER` | `mysql` |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | MySQL do hPanel |
| `ADMIN_OWNER_NAME`, `ADMIN_OWNER_EMAIL`, `ADMIN_OWNER_PASSWORD_HASH` | Conta admin inicial (hash bcrypt) |
| `WORKER_SHARED_SECRET` | HMAC compartilhado com o worker Python (mín. 16 caracteres) |
| `WORKER_REQUIRE_HTTPS` | `1` em produção |
| `WORKER_JOB_STALE_TIMEOUT_SECONDS` | Ex.: `1800` |
| `CLIPIA_MAX_MONTHLY_COST_BRL`, `CLIPIA_MAX_COST_PER_JOB_BRL` | Teto de orçamento (se API paga no futuro) |
| `STORAGE_*` | Limites de upload e retenção de mídia |

Ordem de leitura no PHP: variáveis de ambiente do hPanel (`getenv`) têm **prioridade** sobre o arquivo; entre arquivos, `{domainRootDir}/private/clipia.env.php` é mesclado antes de `config/env.local.php`.

### Alternativas já suportadas pelo código

- `~/clipia_secrets/env.production.php` (fora de `public_html`)
- `~/clipia_env.php`
- `CLIPIA_ENV_FILE` apontando para caminho absoluto no servidor
- `public_html/config/env.local.php` (gitignored; pasta `config/` bloqueada por HTTP)

Modelo versionado no Git (placeholders fictícios): **`config/env.production.example.php`**.

## 5. Worker Python (não vai na Hostinger)

No seu PC/VPS, mantenha **`worker_service/`** com **`.env` local** (gitignored) apontando para:

`https://clipia.sthevandev.com.br/pages/worker-api.php`

Variáveis reconhecidas pelo código: `CLIPIA_BACKEND_API_URL`, `CLIPIA_WORKER_SHARED_SECRET` (= `WORKER_SHARED_SECRET` do PHP), `CLIPIA_VERIFY_TLS=true`, `CLIPIA_AI_PROVIDER=local_heuristic`, `CLIPIA_ALLOW_PAID_API=false`.

Verificação offline (sem reservar tarefas):

```powershell
cd worker_service
python verify_local_config.py
```

O Auto Deploy **não envia** `worker_service/.env` — apenas o código versionado no GitHub.

## 6. Checklist rápido

| Onde | O quê |
|------|--------|
| `public_html/` | Conteúdo do Git (Auto Deploy) |
| MySQL | `schema.sql` importado |
| `.../private/clipia.env.php` | Credenciais de produção (manual, fora do Git) |
| PC/VPS | `worker_service/` + FFmpeg + Whisper |
