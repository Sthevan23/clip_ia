# Checklist de validação — ClipIA na Hostinger (manual)

**Objetivo:** preparar e **registrar** a validação de produção **sem** expor credenciais neste documento.  
**Este arquivo não prova que o servidor está OK** — só guia o que **você** deve conferir no hPanel/phpMyAdmin/navegador.

**Procedimento sequencial:** [`PREPARACAO_PUBLICACAO.md`](PREPARACAO_PUBLICACAO.md)  
**Referências:** [`HOSTINGER.md`](HOSTINGER.md) · [`config/env.production.example.php`](../config/env.production.example.php) · [`config/schema.sql`](../config/schema.sql)  
**Pré-voo local (PC, offline):** `.\deploy\verify-local.ps1` · [`RELATORIO_VERIFICACAO_LOCAL.md`](RELATORIO_VERIFICACAO_LOCAL.md)

| Ambiente | Caminho / URL |
|----------|----------------|
| App PHP | `/home/u586160337/domains/sthevandev.com.br/public_html/clipia/` |
| Config privada | `/home/u586160337/domains/sthevandev.com.br/private/clipia.env.php` |
| Site | https://clipia.sthevandev.com.br/ |
| Login | https://clipia.sthevandev.com.br/login.php |

---

## A. O que o código exige (análise estática — já confirmável no repo)

| Item | Fonte no código | Conferência local (sem Hostinger) |
|------|-----------------|-----------------------------------|
| Caminho do env privado | `config/clipia_env_loader.php` → `$domainRootDir/private/clipia.env.php` | `verify-local.ps1` simula o caminho |
| Ordem de arquivos env | `CLIPIA_ENV_FILE` → `clipia_secrets/env.production.php` → `clipia_env.php` → **`private/clipia.env.php`** | Ler loader; caminho oficial = `private/` |
| 503 se incompleto | `clipia_abort_if_production_misconfigured()` — **não** aplica a `worker-api.php` | Saber que API worker pode responder mesmo com 503 nas páginas |
| Validação 503 | `DB_NAME`, `DB_USER`, `WORKER_SHARED_SECRET` (≥16), recomenda `APP_URL`; exige **`private/clipia.env.php` carregado** em layout `public_html` | Comparar com chaves do `env.production.example.php` |
| **`DB_PASS`** | Necessário para PDO em `config/database.php`; **não** entra na lista do 503 | Preencher no servidor; falha = mensagem genérica de banco no login |
| MySQL | `config/schema.sql` — **8 tabelas** (ver seção C) | Contar `CREATE TABLE` no repo |
| Admin inicial | `login.php` → `ensureOwnerAccountIfEmpty()` só se `COUNT(users)=0`; exige `ADMIN_OWNER_EMAIL` + `ADMIN_OWNER_PASSWORD_HASH` em produção | Ler `includes/repositories.php` |
| Login | `password_verify()`; mensagem genérica se falhar | — |
| Cadastro público | `register.php` bloqueado (`PUBLIC_REGISTRATION_ENABLED=false`) | — |

---

## B. Publicação — caminho oficial e riscos conhecidos

| Tema | Detalhe |
|------|---------|
| **Env privado** | Caminho oficial: **`/home/u586160337/domains/sthevandev.com.br/private/clipia.env.php`** (carregado por `config/clipia_env_loader.php`). Legados (`clipia_secrets/`, `clipia_env.php`) só se existirem no servidor. |
| **Modelo do env** | Copiar estrutura a partir de **`config/env.production.example.php` no repositório Git** (não versionar segredos; **não** colocar esse arquivo em `public_html`). |
| **Pacote FTP** | `.\deploy\build-hostinger-package.ps1` → `deploy/clipia-hostinger-public.zip` — só PHP/assets; **`LEIA-ME-HOSTINGER.txt`** traz passos essenciais **sem** depender de `deploy/*.md` no servidor. |
| **Auto Deploy (Git)** | Deploy da **raiz** do repo para `public_html/clipia` pode copiar **`worker_service/`** (Python, testes) para a web **a menos** que o hPanel tenha exclusões comprovadas. **Preferir o ZIP filtrado** ou confirmar exclusão de `worker_service/`, `.env`, mídia e `storage/*` de dados. |
| **503 vs `DB_PASS`** | `clipia_abort_if_production_misconfigured()` **não** valida `DB_PASS`; mesmo assim é **obrigatório** para o PDO conectar. |
| **Pré-voo PC** | Preferir **`.\deploy\verify-local.ps1`** (PHP lint + HMAC + segurança offline), não só `verify_local_config.py`. |

Registre na coluna **Resultado** (seção D.2): **ZIP filtrado** · **Auto Deploy com exclusões** · **Auto Deploy sem exclusões (risco)**.

---

## C. phpMyAdmin — 8 tabelas esperadas

Após importar **`config/schema.sql`** no banco correto (ex.: `u586160337_clipaai`), em **phpMyAdmin → Estrutura**, deve existir:

1. `users`  
2. `projects`  
3. `source_videos`  
4. `clip_candidates`  
5. `processing_jobs`  
6. `usage_logs`  
7. `monthly_budgets`  
8. `budget_reservations`  

**Consulta segura (sem senhas):** aba SQL:

```sql
SELECT COUNT(*) AS tabelas_clipia
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
    'users','projects','source_videos','clip_candidates',
    'processing_jobs','usage_logs','monthly_budgets','budget_reservations'
  );
```

**Resultado esperado:** `tabelas_clipia = 8`.

Para admin (sem ver hash):

```sql
SELECT COUNT(*) AS total_usuarios FROM users;
SELECT id, email, plan_tier, created_at FROM users LIMIT 5;
```

**Não** cole `password_hash` em chats, prints ou neste checklist.

---

## D. Checklist passo a passo (preencha **Resultado**)

Escala sugerida: `OK` · `FALHA` · `N/A` · `PENDENTE`

### D.1 Pré-requisitos no PC (offline)

| # | Passo | Como verificar | Resultado |
|---|--------|----------------|-----------|
| D.1.1 | Worker configurado | `.\deploy\verify-local.ps1` → `hmac_ready=True`, `allow_paid_api=False` | |
| D.1.2 | HMAC PHP↔Python | Mesmo script → `test_hmac_compat_offline` 7/7 OK | |
| D.1.3 | Seguranca worker offline | Mesmo script → `test_security_offline` 6/6 OK | |
| D.1.4 | Modelo env servidor | No **Git local**, existe `config/env.production.example.php`; você montará **`private/clipia.env.php`** no servidor (não em `public_html`) | |

### D.2 Código publicado na Hostinger

| # | Passo | Como verificar (hPanel / Git) | Resultado |
|---|--------|-------------------------------|-----------|
| D.2.1 | Método de publicação | **ZIP filtrado** (`build-hostinger-package.ps1`) **ou** Auto Deploy — anote qual | |
| D.2.2 | Auto Deploy (se usar) | Git → `Sthevan23/clip_ia`, branch `main`, destino `public_html/clipia`; **confirmar que `worker_service/` NÃO está** em `public_html/clipia/worker_service` | |
| D.2.3 | Arquivos PHP | Gerenciador: `public_html/clipia/index.php`, `config/app.php`, `pages/worker-api.php` existem | |
| D.2.4 | Versão / data | Data de modificação de `index.php` **após** deploy esperado (anote só a data) | |
| D.2.5 | PHP | hPanel → PHP **8.2+**; `display_errors` Off; limites upload ≥ 100M | |
| D.2.6 | Sem env de exemplo na web | **Não** deve existir `public_html/clipia/config/env.production.php` nem `env.local.php` com segredos; modelo fica só no Git | |
| D.2.7 | Pasta pública limpa | Conforme [`PREPARACAO_PUBLICACAO.md`](PREPARACAO_PUBLICACAO.md) §3.2: **ausência** de `worker_service/`, `.env`, configs reais, `storage/samples/`, scripts `storage/audit_*` / `import_audit_demo.php`, mídia em `storage/clips|uploads|logs` | |

### D.3 Arquivo privado (sem revelar credenciais)

| # | Passo | Como verificar | Resultado |
|---|--------|----------------|-----------|
| D.3.1 | Caminho | Arquivo existe: `/home/u586160337/domains/sthevandev.com.br/private/clipia.env.php` | |
| D.3.2 | Permissões | Pasta `private` ~700; arquivo ~600 | |
| D.3.3 | Formato | Abrir no editor do hPanel/FTP: começa com `<?php` e `return [` | |
| D.3.4 | Chaves presentes | Conferir **nomes** das chaves (não copiar valores): `APP_ENV`, `APP_URL`, `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `WORKER_SHARED_SECRET`, `WORKER_REQUIRE_HTTPS` | |
| D.3.5 | Valores mínimos | `APP_ENV` = production; `WORKER_SHARED_SECRET` com **≥16 caracteres** (conte no servidor, não anote); `APP_URL` = https://clipia.sthevandev.com.br | |
| D.3.6 | Admin (se banco vazio) | Chaves `ADMIN_OWNER_EMAIL`, `ADMIN_OWNER_PASSWORD_HASH` preenchidas **ou** plano de criar user manualmente | |
| D.3.7 | Paridade HMAC PC | Mesmo comprimento (~64 hex) entre servidor e `CLIPIA_WORKER_SHARED_SECRET` local — compare só **tamanho**, não o valor | |

### D.4 MySQL

| # | Passo | Como verificar | Resultado |
|---|--------|----------------|-----------|
| D.4.1 | Banco | hPanel: banco e usuário criados e vinculados | |
| D.4.2 | Import | `schema.sql` importado sem erro fatal | |
| D.4.3 | Oito tabelas | Consulta seção C → `tabelas_clipia = 8` | |
| D.4.4 | Host MySQL | `DB_HOST` no env = valor do hPanel (muitas vezes `127.0.0.1`) | |

### D.5 Primeiro administrador (análise + conferência)

| Cenário | O que acontece | O que você confere |
|---------|----------------|-------------------|
| **`users` vazia** | No **primeiro login POST** bem-sucedido, `ensureOwnerAccountIfEmpty()` cria 1 linha com e-mail/hash do env | Env com `ADMIN_OWNER_*` correto; depois SQL `COUNT(*) >= 1` |
| **`users` já tem linha** | Função **não** altera senhas nem cria duplicata | Login com e-mail existente; env admin ignorado |
| **Produção** | `ADMIN_OWNER_PASSWORD` plain **não** deve ser usado (`app.php` default vazio) | Apenas **hash** bcrypt em `ADMIN_OWNER_PASSWORD_HASH` |

### D.6 Site antes de testes “pesados”

| # | Passo | Como verificar (navegador) | Resultado |
|---|--------|----------------------------|-----------|
| D.6.1 | HTTPS | Certificado válido; URL com `https://` | |
| D.6.2 | Página inicial | **Não** aparece “Configuração do servidor incompleta” (HTTP 503) | |
| D.6.3 | Login (GET) | `/login.php` carrega formulário (sem erro de banco na tela) | |

### D.7 Teste de login controlado

Faça **uma** tentativa com e-mail/senha que **você** definiu. **Não** registre a senha abaixo.

| # | Passo | Resultado esperado | Resultado |
|---|--------|-------------------|-----------|
| D.7.1 | POST login | Redirecionamento para dashboard; flash de boas-vindas | |
| D.7.2 | Sessão | Navegar uma página autenticada (ex.: projetos) sem voltar ao login | |
| D.7.3 | Falha controlada | Senha errada → mensagem genérica “Credenciais inválidas” (sem vazar se e-mail existe) | |
| D.7.4 | Log servidor | Se falhar: `storage/logs/php_errors.log` no servidor — copiar só mensagens **sem** credenciais | |

### D.8 Após login (opcional, ainda **sem** worker/job)

| # | Passo | Resultado |
|---|--------|-----------|
| D.8.1 | `usage_logs` | Após login OK, pode existir linha `user_login` (phpMyAdmin) | |
| D.8.2 | Registro público | `/register.php` não permite cadastro aberto | |

---

## E. O que **não** fazer nesta fase de validação inicial

- Processar vídeos ou rodar `python main.py --process-next` / `--poll` (ver HOSTINGER.md §10).  
- Habilitar `CLIPIA_ALLOW_PAID_API` ou APIs pagas.  
- Colar segredos, `.env` ou trechos de `clipia.env.php` em chats ou neste arquivo.  
- Considerar “produção validada” até D.2–D.7 estarem **OK** com evidência anotada por você.

---

## F. Registro final (preencha quando terminar)

| Campo | Valor |
|-------|--------|
| Data da validação | |
| Responsável | |
| Commit Git esperado no servidor | |
| Banco MySQL (nome **apenas**) | |
| D.3–D.7 todos OK? | SIM / NÃO |
| Pronto para smoke worker (upload + 1 job)? | SIM / NÃO — só se D.3–D.7 OK **e** autorização explícita |

---

## G. Resumo: local × manual

| Confirmável no PC (offline) | Exige hPanel / phpMyAdmin / navegador |
|-----------------------------|----------------------------------------|
| Caminho `private/clipia.env.php` simulado | Arquivo existe e permissões |
| Lista de 8 tabelas e SQL de contagem | Import real e consulta no banco |
| Regras admin / login no código | Login real e contagem em `users` |
| `verify-local.ps1`, HMAC, worker | SSL, 503, data de `index.php`, PHP version |
| Modelo `env.production.example.php` | Valores reais no `clipia.env.php` |

**Status desta preparação:** documento criado/atualizado; **validação do servidor ainda depende de você executar a seção D.**
