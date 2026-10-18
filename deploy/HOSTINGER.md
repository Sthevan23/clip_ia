# Deploy ClipIA na Hostinger (MySQL + worker Windows)

Ambiente deste guia:

| Item | Valor |
|------|--------|
| Site | https://clipia.sthevandev.com.br/ |
| Repositório | https://github.com/Sthevan23/clip_ia.git |
| Branch Auto Deploy | `main` |
| App no servidor | `/home/u586160337/domains/sthevandev.com.br/public_html/clipia/` |
| Config privada | `/home/u586160337/domains/sthevandev.com.br/private/clipia.env.php` |

---

## 1. Conferir o Auto Deploy

1. hPanel → **Git** / **Deploy** → repositório `Sthevan23/clip_ia`, branch **`main`**.
2. Confirme que o destino é **`public_html/clipia`** (ou equivalente com subpasta `clipia`).
3. Após push no GitHub, aguarde o deploy e verifique no Gerenciador de Arquivos se `public_html/clipia/index.php` foi atualizado.
4. **Não** versione `.env`, `config/env.local.php`, `private/clipia.env.php` nem mídia de clientes.

Se o site mostrar *“Configuração do servidor incompleta”* (HTTP 503), faltam credenciais — siga a seção 2.

---

## 2. Pasta `private` fora de `public_html`

Com o app em `public_html/clipia/`, o PHP calcula:

- `$projectRootDir` → `.../public_html/clipia`
- `$domainRootDir` → `.../domains/sthevandev.com.br`

O arquivo privado **deve** estar em:

`/home/u586160337/domains/sthevandev.com.br/private/clipia.env.php`

**Gerenciador de Arquivos (quando permitir subir acima de public_html):**

1. Navegue até `domains/sthevandev.com.br/` (irmão de `public_html`).
2. Crie a pasta **`private`** (permissão **700**).
3. Crie o arquivo **`clipia.env.php`** dentro dela (permissão **600**).

**Se a UI não subir acima de `public_html`:** use **FTP/SFTP** (FileZilla / WinSCP) com o usuário FTP da Hostinger e acesse `/domains/sthevandev.com.br/private/`.

**Nunca** coloque segredos em `public_html/clipia/config/env.local.php` em produção (mesmo com `.htaccess`, o disco fica na árvore pública).

---

## 3. Criar `clipia.env.php` a partir do modelo

1. No repositório local, abra **`config/env.production.example.php`** (somente placeholders).
2. Copie o conteúdo para o servidor como **`private/clipia.env.php`**.
3. Substitua placeholders fictícios pelos valores reais **no servidor** (MySQL, HMAC, hash admin).
4. Garanta `return [ ... ];` — array associativo PHP.

Chaves **obrigatórias** em produção (validadas pelo código):

- `APP_ENV` = `production`
- `DB_NAME`, `DB_USER`, `DB_PASS`
- `WORKER_SHARED_SECRET` (mínimo 16 caracteres)

**Recomendadas:** `APP_URL`, `WORKER_REQUIRE_HTTPS` = `1`, `APP_TIMEZONE`, `ADMIN_OWNER_*` (se a tabela `users` estiver vazia).

**Opcionais:** `STORAGE_*`, `CLIPIA_MAX_*_BRL`, `WORKER_JOB_STALE_TIMEOUT_SECONDS`.

Alternativa: definir as mesmas chaves como **variáveis de ambiente** no hPanel (têm prioridade sobre arquivos).

---

## 4. MySQL

1. hPanel → **Bancos de dados** → criar banco e usuário.
2. **phpMyAdmin** → importar **`config/schema.sql`** (do repositório).
3. Preencha `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` em `clipia.env.php` (valores exibidos no hPanel).

---

## 5. Hash da senha administrativa (sem expor a senha)

No **Windows 11** (PowerShell ou Git Bash), com PHP instalado:

```bash
php -r "echo password_hash('SuaSenhaForteAqui', PASSWORD_DEFAULT);"
```

Cole **apenas o hash** em `ADMIN_OWNER_PASSWORD_HASH` no `clipia.env.php`. Não commite a senha em texto claro.

---

## 6. Mesmo segredo HMAC (PHP + worker)

1. Gere uma string longa **no seu PC** (não envie no chat):

   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```

2. Coloque o **mesmo valor** em:
   - `WORKER_SHARED_SECRET` no `clipia.env.php` (servidor)
   - `CLIPIA_WORKER_SHARED_SECRET` no `worker_service/.env` (Windows, gitignored)

3. Mantenha `WORKER_REQUIRE_HTTPS=1` no PHP e `CLIPIA_VERIFY_TLS=true` no worker.

Protocolo (já implementado): `HMAC-SHA256(secret, "{timestamp}.{corpo HTTP bruto}")`, cabeçalhos `X-ClipIA-Timestamp` e `X-ClipIA-Signature`, janela ±300 s, `hash_equals` no PHP.

---

## 7. `.env` local do worker (Windows 11)

```powershell
cd C:\Users\Administrador\OneDrive\Desktop\projeto_clips\worker_service
Copy-Item .env.example .env
```

Edite **`.env`** (nunca commitar):

- `CLIPIA_BACKEND_API_URL=https://clipia.sthevandev.com.br/pages/worker-api.php`
- `CLIPIA_WORKER_SHARED_SECRET=` (igual ao PHP)
- `CLIPIA_VERIFY_TLS=true`
- `CLIPIA_AI_PROVIDER=local_heuristic`
- `CLIPIA_ALLOW_PAID_API=false`

---

## 8. Verificador offline (sem fila, sem HTTP de produção)

```powershell
cd worker_service
python verify_local_config.py
python -m unittest test_hmac_compat_offline.py -v
```

Confirme: `allow_paid_api=False`, `ai_provider=local_heuristic`, `hmac_ready=True` (após preencher segredo), `hmac_self_test_ok=True`.

---

## 9. Erros comuns (sem mostrar credenciais)

| Sintoma | Causa provável | Ação |
|--------|----------------|------|
| HTTP 503 “Configuração incompleta” | `clipia.env.php` ausente ou incompleto | Criar `private/clipia.env.php` |
| Login falha / erro de banco | MySQL incorreto | Revisar `DB_*` no hPanel |
| Worker 401 na API | HMAC diferente ou relógio | Igualar segredo; sincronizar hora Windows |
| Worker 403 | HTTP em vez de HTTPS | Usar URL `https://` |
| Worker 503 secret | `WORKER_SHARED_SECRET` curto/ausente | ≥16 caracteres no PHP |

Logs PHP: `public_html/clipia/storage/logs/php_errors.log` (sem imprimir senhas).

---

## 10. Teste de produção (somente após autorização explícita)

**Não** execute durante deploy inicial. Quando autorizado:

1. Login no painel → criar projeto → upload MP4 pequeno autorizado.
2. No PC: `python main.py --process-next` (uma vez) ou `--poll 15`.
3. Verifique projeto **Concluído**, clipes e SRT no painel.

Até lá, use apenas `verify_local_config.py` e testes offline.

---

## Checklist rápido

| Onde | O quê |
|------|--------|
| GitHub `main` | Código PHP (Auto Deploy) |
| `public_html/clipia/` | Aplicação publicada |
| `domains/.../private/clipia.env.php` | Credenciais (manual) |
| MySQL | `schema.sql` importado |
| Windows `worker_service/.env` | Worker + mesmo HMAC |

Pacote ZIP manual (opcional): `.\deploy\build-hostinger-package.ps1` — não substitui o Auto Deploy se já configurado.
