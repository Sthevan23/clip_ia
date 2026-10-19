# ClipIA — Início rápido Hostinger (só o que você faz manualmente)

**Pacote gerado no PC (não commitar segredos).** Código local testado em 2026-10-18; **site em produção não foi validado daqui.**

---

## Antes de começar (no PC — já feito pelo projeto)

- ZIP final: `deploy\clipia-hostinger-public.zip`
- Modelo do env: `config\env.production.example.php` (copiar estrutura, **não** subir este arquivo para a web)

---

## Passos no hPanel / FTP (ordem)

### 0. Backup (recomendado)

1. Baixar cópia de `public_html/clipia/` (ou ao menos `config/`, `includes/`, `login.php`).
2. Exportar o banco MySQL no phpMyAdmin (**Exportar** → SQL).
3. Copiar `private/clipia.env.php` para um local seguro no PC (não compartilhar).

### 1. Enviar o site

| O quê | Onde |
|-------|------|
| Arquivo | `C:\Users\Administrador\OneDrive\Desktop\projeto_clips\deploy\clipia-hostinger-public.zip` |
| Destino | `public_html/clipia/` |
| Ação | Enviar o ZIP → **Extrair** (substitui PHP/assets; **não** apague pastas de upload com vídeos seus) |

**Preservar:** tudo em `domains/sthevandev.com.br/private/` (especialmente `clipia.env.php` se já existir).

**Não deve aparecer** em `public_html/clipia/`: pasta `worker_service/`, arquivos `.env`, `config/env.production.php`.

---

### 2. PHP e SSL (hPanel)

- PHP **8.2+**
- `display_errors` **Off**
- Upload ≥ **100 MB**
- SSL/HTTPS ativo para `clipia.sthevandev.com.br`

---

### 3. MySQL

1. Criar banco + usuário (anotar **nomes**, não senhas neste doc).
2. phpMyAdmin → banco correto → **Importar** apenas se ainda **não** tiver tabelas: `public_html/clipia/config/schema.sql`
3. Conferir **8 tabelas:** `users`, `projects`, `source_videos`, `clip_candidates`, `processing_jobs`, `usage_logs`, `monthly_budgets`, `budget_reservations`.

Consulta segura:

```sql
SELECT COUNT(*) AS tabelas FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('users','projects','source_videos','clip_candidates',
    'processing_jobs','usage_logs','monthly_budgets','budget_reservations');
```

Esperado: **8**. Se já tinha dados, **não** reimporte o schema (evita apagar).

---

### 4. Arquivo privado (credenciais)

**Caminho (fora da web):**

`/home/u586160337/domains/sthevandev.com.br/private/clipia.env.php`

Permissões: pasta `private` **700**, arquivo **600**.

**Chaves obrigatórias (nomes apenas — preencha valores no servidor):**

| Chave | Notas |
|-------|--------|
| `APP_ENV` | `production` |
| `APP_URL` | `https://clipia.sthevandev.com.br` |
| `DB_DRIVER` | `mysql` |
| `DB_HOST` | geralmente `127.0.0.1` |
| `DB_PORT` | `3306` |
| `DB_NAME` | banco Hostinger |
| `DB_USER` | usuário MySQL |
| `DB_PASS` | senha MySQL |
| `WORKER_SHARED_SECRET` | ≥16 caracteres, **gerado por você**, não use exemplo do Git |
| `WORKER_REQUIRE_HTTPS` | `1` |

**Administrador (tabela `users` vazia) — escolha UMA opção:**

| Opção | No env privado |
|-------|----------------|
| **A — Recomendada** | `ADMIN_OWNER_EMAIL` + `ADMIN_OWNER_PASSWORD_HASH` (hash bcrypt gerado no **seu PC**: `php -r "echo password_hash('SuaSenha', PASSWORD_DEFAULT);"`) |
| **B — Só instalação** | `CLIPIA_BOOTSTRAP_OWNER_ON_FIRST_LOGIN` => `'1'` → login uma vez com e-mail/senha (≥8 chars) → depois mude para `'0'` |

**Nunca** deixe bootstrap `'1'` após o primeiro login.

`CLIPIA_BOOTSTRAP_OWNER_ON_FIRST_LOGIN` padrão no código: **desligado**.

---

### 5. Testar login

1. Abrir `https://clipia.sthevandev.com.br/` → **sem** página “Configuração incompleta” (503).
2. Abrir `/login.php` → formulário, sem erro de banco na tela.
3. Entrar com o e-mail/senha da opção A ou B.
4. Deve ir ao **dashboard**.
5. `/register.php` → cadastro **bloqueado**.

Se “Credenciais inválidas” com `users` vazia: confira opção A/B, se o ZIP novo foi extraído, e `SELECT COUNT(*) FROM users;`.

---

### 6. Verificação final (site)

| # | OK? |
|---|-----|
| HTTPS válido | |
| Login + dashboard | |
| `users` tem 1 admin | |
| Bootstrap = `0` no env | |
| Pasta pública sem `worker_service/` | |

---

### 7. Worker Windows (depois do login OK)

No PC, em `worker_service\.env` ( **não** vai no ZIP):

- `CLIPIA_BACKEND_API_URL=https://clipia.sthevandev.com.br/pages/worker-api.php`
- `CLIPIA_WORKER_SHARED_SECRET` = **mesmo valor** que `WORKER_SHARED_SECRET` no env privado
- `CLIPIA_VERIFY_TLS=true`
- `CLIPIA_ALLOW_PAID_API=false`

**Um teste de job (opcional, após site OK):**

```powershell
cd C:\Users\Administrador\OneDrive\Desktop\projeto_clips\worker_service
python main.py --process-next
```

(Sem `--poll`. Use um MP4 pequeno criado no painel.)

---

## Se algo falhar

| Sintoma | Ver |
|---------|-----|
| 503 configuração | Env privado, `DB_*`, HMAC ≥16 |
| Erro de banco no login | `DB_PASS`, host MySQL |
| Credenciais inválidas | §4 administrador, ZIP atualizado |

Detalhes: `deploy/GUIA_PUBLICACAO_MANUAL.md` · Checklist: `deploy/CHECKLIST_VALIDACAO_PRODUCAO.md`

---

**Produção validada somente após você marcar os itens acima.**
