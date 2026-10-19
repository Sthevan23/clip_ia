# Relatório final — auditoria local ClipIA

**Data da execução:** 2026-10-18 (sessões anteriores + **auditoria HMAC/Git ~21:06 UTC-3**)  
**Escopo:** código, testes offline/locais, pacote ZIP. **Sem** commit, push, deploy, HTTP à produção, jobs reais na Hostinger ou leitura de segredos.

**Conclusão:** o fluxo local PHP + SQLite + worker (`test_integration.py`) e as suítes Python offline **passaram**. Isso **não** significa produção validada nem segurança comprovada no servidor Hostinger.

---

## 1. Estado Git (`git status --short`)

Alterações locais existentes (não descartadas):

- **Modificados:** `.gitignore`, `README.md`, `deploy/HOSTINGER.md`, `deploy/build-hostinger-package.ps1`, `includes/audit_demo_importer.php`, `worker_service/*` (config, verify, tests, README, `.env.example`)
- **Novos (untracked):** `deploy/CHECKLIST_*`, `PREPARACAO_PUBLICACAO.md`, `verify-local.ps1`, `inspect-stray-artifacts.ps1`, docs deploy, `test_security_offline.py`, este relatório

---

## 2. Mapa funcional (inspeção de código)

| Área | Pontos de entrada / módulos |
|------|-----------------------------|
| Auth | `login.php`, `includes/auth.php`, sessão em `config/app.php` (exceto `worker-api.php`) |
| Projetos | `new-project.php`, `projects.php`, `includes/repositories.php` |
| Upload | `includes/video_upload.php`, POST em `projects.php` |
| YouTube | `pages/validate-youtube.php`, `includes/youtube.php` (auth + CSRF) |
| Jobs / worker | `pages/worker-api.php`, `includes/worker_client.php`, `WorkerJobRepository` |
| Clipes / stream | `pages/stream-clip.php` (`require_auth`, path seguro) |
| Orçamento | `reserve_budget` / `settle_budget` em `worker-api.php` + `WorkerJobRepository` + ledger Python |
| Dashboard | `dashboard.php`, `index.php` |
| Env produção | `config/clipia_env_loader.php` → `private/clipia.env.php` |

---

## 3. Testes executados

### Validação final reexecutada (2026-10-18 ~20:53, PC local)

| Comando | Resultado |
|---------|-----------|
| `cd worker_service; python -m unittest discover -s . -p "test_*.py" -v` | **exit 0** — **Ran 29 tests** in ~10,1 s — **OK** |
| `cd worker_service; python test_integration.py` | **exit 0** — **Ran 16 tests** in ~9,4 s — **OK**; passos manuais **[OK] 1–13** (E2E PHP+SQLite+worker) |
| `.\deploy\verify-local.ps1` (raiz do repo) | **exit 0** — PHP lint, HMAC 7/7, security 6/6, pacote ZIP, tinger* |
| `.\deploy\build-hostinger-package.ps1` | **exit 0** — ZIP regenerado; inspeção **47 entradas**, varredura proibidos **OK** |

**Erros nesta execução:** nenhum (exit 0 em todos os comandos acima).

**Avisos (não bloqueiam):** `verify_local_config` — OPENAI_API_KEY via Registro Windows com APIs pagas desligadas; ffprobe ausente com ffmpeg imageio (esperado).

### Detalhe via `verify-local.ps1` (última execução)

| Etapa | Resultado |
|-------|-----------|
| PHP lint (4 arquivos) | OK |
| HMAC offline | 7/7 OK |
| Security offline | 6/6 OK |
| `verify_local_config.py` | exit 0; `allow_paid_api=False`, `hmac_ready=True`, `ffmpeg_from_imageio_bundle=True` |
| `inspect-hostinger-package.ps1` | exit 0; 47 entradas; proibidos OK |

### `test_integration.py` — escopo local (sem produção)

| Item | Evidência no código / execução |
|------|--------------------------------|
| Backend HTTP | Apenas `http://127.0.0.1:8099/pages/worker-api.php` (servidor PHP embutido `php -S`) |
| Banco | SQLite `storage/test_worker_integration.sqlite` (recriado no teste) |
| Mídia | `storage/samples/authorized_demo_podcast.mp4` (+ transcript JSON copiados para `storage/uploads/`) |
| Produção | **Sem** requisições a `clipia.sthevandev.com.br` durante o script (grep no arquivo: só loopback) |
| Jobs “reais” | Job **sintético** de teste; não usa fila MySQL da Hostinger |
| `main.py --process-next` / polling | **Não** invocado como CLI contra produção; orquestrador in-process no teste |

**Nota:** `verify_local_config.py` (fora do integration) **exibe** o host configurado no `.env` (`clipia.sthevandev.com.br`) para diagnóstico, **sem** HTTP à produção nesta bateria.

### Destaques do `test_integration.py` (local, não produção)

- Servidor PHP embutido: `php -S 127.0.0.1:8099`
- Mídia: `storage/samples/authorized_demo_podcast.mp4` (+ transcript JSON)
- **Não** chama `clipia.sthevandev.com.br`

### Testes não executados / lacunas

| Item | Motivo |
|------|--------|
| Testes automatizados PHP (PHPUnit) | **Não existem** no repo; cobertura via `test_integration.py` + lint |
| UI dashboard / JS | Sem testes E2E browser |
| `.htaccess` em Apache Hostinger | Só revisão estática; **não** testado no servidor |
| MySQL real | Integração usa SQLite simplificado; schema MySQL em `config/schema.sql` não exercido em CI local |
| `claim_next_job` contra produção | **Proibido e não executado** |

---

## 4. Vídeo / FFmpeg / ffprobe / Whisper

| Componente | Situação |
|------------|----------|
| Python + faster-whisper | Import OK; teste real em `test_audit_suite` (demo WAV) |
| FFmpeg | Resolvido via **imageio-ffmpeg** (`ffmpeg_resolved=True`) |
| ffprobe | **Ausente** no PATH e ao lado do bundle imageio — **esperado**; `cutter.py` / `transcriber.py` **não invocam ffprobe** |
| Corte + legendas | Validado em audit suite + integração (MP4 burn-in e SRT) |

### Correção aplicada

- **`worker_service/verify_local_config.py`:** aviso de ffprobe alinhado à realidade (bundle imageio sem ffprobe; opcional instalar FFmpeg completo + `CLIPIA_FFMPEG_BINARY`).

### Teste ponta a ponta local

**Concluído** via `test_integration.py`: projeto → job → worker → 4 candidatos `completed` → ficheiros em `storage/clips/`.

---

## 5. Orçamento e ledger

- Limites **R$ 2,50/tarefa** e **R$ 100/mês** confirmados em `test_openai_provider.py` (mocks) e `test_audit_suite` / `test_integration` (reserva/liquidação SQLite).
- `CLIPIA_ALLOW_PAID_API=false` mantido; provedor `local_heuristic` ativo nos testes padrão.
- Ledger em disco (`budget.py`) testado com persistência nos mocks OpenAI.

---

## 6. Segurança (revisão estática)

| Controle | Evidência |
|----------|-----------|
| Worker API HMAC + POST only | `pages/worker-api.php`, `verifyIncomingRequest` |
| HTTPS produção | `WORKER_REQUIRE_HTTPS` |
| CSRF / auth endpoints web | `validate-youtube.php`, páginas autenticadas |
| Upload / paths | `VideoUploadManager::resolveSafeMediaPath` |
| Registro público | `register.php` → 403 POST |
| `.htaccess` raiz | Bloqueio `config|includes|storage|worker_service`, negar `.env`, `.py`, etc. |

**Pendente no servidor:** eficácia real do `.htaccess` LiteSpeed/Hostinger.

---

## 7. Pacote Hostinger ZIP

Gerado: `C:\Users\Administrador\OneDrive\Desktop\projeto_clips\deploy\clipia-hostinger-public.zip` (caminho **absoluto** via `$PSScriptRoot` em `deploy/`).

| Métrica | Valor |
|---------|--------|
| Entradas | **47** |
| Varredura proibidos | **OK** (`inspect-hostinger-package.ps1`) |
| Excluído | `worker_service/`, `.env`, env reais/exemplo, `storage/samples`, audit, mídia `.mp4`/`.wav`, logs |
| Incluído (obrigatório verificado) | `.htaccess`, `pages/worker-api.php`, `config/schema.sql`, `config/clipia_env_loader.php`, `LEIA-ME-HOSTINGER.txt` |

Procedimentos preservados: `PREPARACAO_PUBLICACAO.md`, `CHECKLIST_VALIDACAO_PRODUCAO.md`.

### Incidente PowerShell corrigido (`InvokeMethodOnNull`)

**Reprodução (falha):**

1. `Set-Location ...\worker_service`
2. `[IO.Compression.ZipFile]::OpenRead('deploy\clipia-hostinger-public.zip')`  
   → resolve para `...\worker_service\deploy\clipia-hostinger-public.zip` (inexistente)  
   → `MethodInvocationException` / `DirectoryNotFoundException`
3. `$z` fica `$null`; `$z.Dispose()` na linha seguinte  
   → **`RuntimeException` / `InvokeMethodOnNull`**

**Causa:** caminho relativo ao ZIP com **cwd** em `worker_service/`, não script defeituoso do relatório Markdown.

**Correção:**

- Novo **`deploy/inspect-hostinger-package.ps1`**: abre ZIP só em `Join-Path $PSScriptRoot 'clipia-hostinger-public.zip'`, `try/finally` com `Dispose` guardado.
- **`deploy/build-hostinger-package.ps1`**: falha com exit ≠ 0 se a inspeção falhar.
- **`deploy/verify-local.ps1`**: etapa explícita de pacote; exit ≠ 0 se inspeção falhar.

Comando seguro: `.\deploy\inspect-hostinger-package.ps1` ou `.\deploy\inspect-hostinger-package.ps1 -Build` (sempre a partir da raiz ou via scripts em `deploy/`).

---

## 8. Arquivos modificados nesta auditoria

| Arquivo | Motivo |
|---------|--------|
| `worker_service/verify_local_config.py` | ffprobe opcional; aviso específico para imageio-ffmpeg |
| `deploy/inspect-hostinger-package.ps1` | **Novo** — inspeção ZIP com caminho absoluto e exit code honesto |
| `deploy/build-hostinger-package.ps1` | Validação pós-build |
| `deploy/verify-local.ps1` | Integra inspeção do pacote |
| `deploy/RELATORIO_FINAL_AUDITORIA_LOCAL.md` | Este relatório |

*(Demais alterações já existiam antes desta sessão — ver secção 1.)*

---

## 9. Riscos antes da publicação

1. Auto Deploy Git pode copiar `worker_service/` e `storage/samples/` versionados — usar **ZIP filtrado** ou exclusões no hPanel.
2. Produção **não** testada (env privado, MySQL remoto, SSL, login real).
3. `OPENAI_API_KEY` no Registro Windows (aviso verify-local); APIs pagas permanecem desligadas por config.
4. Artefato local `tinger*` ignorado pelo Git; não enviar ao servidor.

---

## 10. Próxima ação manual (utilizador)

1. Seguir **`deploy/PREPARACAO_PUBLICACAO.md`** (Fases 1–5).
2. Preencher **`deploy/CHECKLIST_VALIDACAO_PRODUCAO.md`** no hPanel/navegador.
3. Só então configurar worker contra HTTPS produção e smoke **autorizado** (`WORKER_WINDOWS.md`).

---

### Pendências (publicação manual)

| Item | Estado |
|------|--------|
| PHPUnit / browser E2E | Não existem / não executados |
| MySQL remoto + `private/clipia.env.php` | Manual no hPanel |
| `.htaccess` na Hostinger | Não testado no servidor |
| Worker smoke HTTPS produção | **Bloqueado** até checklist D.3–D.7 OK + autorização explícita |
| Commit das alterações locais | Opcional; não feito nesta auditoria |

**Assinatura de estado:** validação local **reexecutada com sucesso** (29 + integration + verify-local + build/inspeção ZIP). **Não** equivale a produção validada. **Sem bloqueio técnico local** para iniciar a **preparação** da publicação manual (`PREPARACAO_PUBLICACAO.md`).

---

## 11. Plano de publicação manual Hostinger (preparado; **não executado** nesta sessão)

Documentos alinhados: [`PREPARACAO_PUBLICACAO.md`](PREPARACAO_PUBLICACAO.md), [`CHECKLIST_VALIDACAO_PRODUCAO.md`](CHECKLIST_VALIDACAO_PRODUCAO.md), [`HOSTINGER.md`](HOSTINGER.md).

### 11.1 Pacote público — o que NÃO deve ir para `public_html/clipia/`

Conferido localmente (`inspect-hostinger-package.ps1`, **47 entradas**, proibidos OK):

| Categoria | Status no ZIP | No servidor após upload |
|-----------|---------------|-------------------------|
| `worker_service/` | Ausente | **Não** deve aparecer (§3.2 PREPARACAO) |
| `.env`, segredos | Ausente | **Não** enviar |
| `config/env.production.php`, `env.local.php`, `env.*.example.php` | Ausente | Credenciais só em `private/clipia.env.php` |
| `storage/samples/`, audit, mídia `.mp4`/`.wav`, logs | Ausente | Pastas vazias + `.htaccess` apenas |
| `deploy/*.zip`, scripts de build | Ausente | Manter ZIP só no PC |

**Incluído de propósito:** `pages/worker-api.php` (M2M + HMAC), `includes/audit_demo_importer.php` (exigido por `projects.php`; não é mídia de demo).

**Arquivo no PC para upload:** `deploy/clipia-hostinger-public.zip`  
**Modelo env (só Git, não no ZIP):** `config/env.production.example.php`

### 11.2 Passos manuais em ordem (hPanel / FTP)

| Ordem | Onde | Ação | Arquivos / caminhos |
|-------|------|------|---------------------|
| 0 | PC | Fase 1 já OK — opcional regenerar ZIP antes de subir | `.\deploy\build-hostinger-package.ps1` |
| 1 | hPanel | PHP **8.2+**, `display_errors` Off, upload ≥ 100M, SSL ativo | Domínio / subpasta `clipia` |
| 2 | hPanel → Bancos | Criar banco MySQL + usuário; vincular permissões | Anotar nomes (ex. banco `u586160337_clipaai`) |
| 3 | Gerenciador / FTP | Abrir `public_html/clipia/` (criar pasta se necessário) | |
| 4 | FTP | Enviar `clipia-hostinger-public.zip` e **extrair** em `clipia/` | Raiz: `index.php`, `.htaccess`, `LEIA-ME-HOSTINGER.txt` |
| 5 | Gerenciador | Checklist §3.2 — **nenhum** item proibido na árvore pública | Ver tabela §11.1 |
| 6 | phpMyAdmin | Selecionar banco → Importar | `public_html/clipia/config/schema.sql` |
| 7 | phpMyAdmin | SQL contagem 8 tabelas | Seção C do CHECKLIST |
| 8 | FTP (acima de `public_html` se possível) | Criar `domains/sthevandev.com.br/private/` (**700**) | |
| 9 | FTP | Criar `private/clipia.env.php` (**600**) a partir do modelo Git | **Não** colocar em `public_html/clipia/config/` |
| 10 | Env privado | Preencher chaves: `APP_ENV`, `APP_URL`, `DB_*`, `WORKER_SHARED_SECRET` (≥16), `WORKER_REQUIRE_HTTPS=1`, `ADMIN_OWNER_*` se `users` vazia | Ver HOSTINGER §§ 2–6 |
| 11 | Navegador | Validar site sem 503, login, registro bloqueado | CHECKLIST D.6–D.8 |
| 12 | PC + servidor | Comparar **comprimento** HMAC (não o valor) | D.3.7 |
| 13 | **Só após 11–12 OK + autorização** | Worker: `WORKER_WINDOWS.md`, uma execução `--process-next` | Fase 6 PREPARACAO |

**Ordem recomendada MySQL → env privado:** importar schema antes de esperar login estável; env privado antes de testes de navegador (evita 503).

### 11.3 Verificações **antes** de iniciar o worker

| # | Verificação | Onde |
|---|-------------|------|
| W1 | D.2.3–D.2.7 — PHP publicado, pasta limpa, sem `worker_service/` | Gerenciador |
| W2 | D.3.1–D.3.7 — `private/clipia.env.php` carregável, chaves e comprimento HMAC | FTP + CHECKLIST |
| W3 | D.4.1–D.4.4 — MySQL importado, 8 tabelas, `DB_HOST` correto | phpMyAdmin |
| W4 | D.6–D.7 — HTTPS, **sem 503**, login OK, sessão, registro bloqueado | Navegador |
| W5 | PC: `CLIPIA_BACKEND_API_URL=https://clipia.sthevandev.com.br/pages/worker-api.php`, `CLIPIA_VERIFY_TLS=true`, `CLIPIA_ALLOW_PAID_API=false` | `worker_service/.env` (sem rodar worker ainda) |
| W6 | PC: `.\deploy\verify-local.ps1` → `hmac_secret_length` = comprimento no servidor | Offline |

**Não iniciar** `main.py --process-next`, `--poll` ou fila até W1–W6 registrados OK no CHECKLIST.

### 11.4 Testes de produção **seguros** (executar **você** após upload; não automatizados nesta sessão)

**Fase A — site (sem worker, sem jobs)**

1. GET `https://clipia.sthevandev.com.br/` — sem mensagem “Configuração incompleta”.
2. GET `/login.php` — formulário; sem erro PDO na tela.
3. POST login uma vez com credenciais definidas por você — redirect dashboard (D.7).
4. GET `/register.php` — cadastro bloqueado.
5. GET `/projects.php` autenticado — página carrega.
6. phpMyAdmin: `SELECT COUNT(*) FROM users;` (sem expor hashes).

**Fase B — API worker (sem processar job de vídeo ainda)**

7. POST `https://clipia.sthevandev.com.br/pages/worker-api.php` **sem** cabeçalhos HMAC — esperado **401** (confirma que endpoint responde e rejeita anônimo). *Não* usar corpo com `claim_next_job` em loop.
8. (Opcional, com ferramenta local) POST com HMAC **inválido** — **401**; com timestamp expirado — **401**. *Só após* W5–W6; comparar comportamento com `test_hmac_compat_offline.py` offline.
9. Confirmar HTTPS: worker config com `WORKER_REQUIRE_HTTPS=1`; tentativa HTTP deve falhar (403) se testada **uma vez** de propósito.

**Fase C — smoke job único (somente após Fase A + B + autorização explícita)**

10. No painel: criar projeto + upload **MP4 pequeno** autorizado (seu arquivo de teste).
11. No PC: **uma** execução `python main.py --process-next` (sem `--poll`).
12. Verificar projeto **Concluído**, clipe em `storage/clips/` no servidor via painel (não baixar segredos).
13. **Não** habilitar APIs pagas; manter `CLIPIA_ALLOW_PAID_API=false`.

### 11.5 Divergências / pendências identificadas na revisão dos guias

| Item | Detalhe |
|------|---------|
| Auto Deploy vs ZIP | Repo Git contém `worker_service/` — **ZIP filtrado** continua sendo o método recomendado; Auto Deploy exige exclusões comprovadas (PREPARACAO §2.6, HOSTINGER §1). |
| LEIA-ME no servidor | Cita docs em `deploy/*.md` que **não** vão no ZIP; passos essenciais estão no próprio LEIA-ME + este plano. |
| `503` vs `DB_PASS` | Abort 503 não valida `DB_PASS`; mesmo assim obrigatório para login (CHECKLIST A). |
| Alterações Git locais | Várias mudanças ainda **uncommitted** — publicar ZIP do disco atual; commit opcional antes de sync Git. |
| Produção | **Nenhum** passo §11.2–11.4 foi executado pelo agente nesta preparação — **produção não validada**. |
| Worker `.env` no PC | Pode apontar host produção no diagnóstico; **não** dispare worker até Fase A OK. |

### 11.6 Bloqueio para começar a publicação manual?

**Não** — validação local e pacote (**47** entradas, inspeção OK) permitem **iniciar** a sequência §11.2 no hPanel.  
**Sim** — para declarar produção OK ou ligar worker: depende de §11.3–11.4 preenchidos por você.

---

## 12. Sessão de preparação — auditoria e guia hPanel (2026-10-18 ~20:58 UTC-3)

### 12.1 Arquivos inspecionados

| Arquivo / artefato | Nota |
|--------------------|------|
| `deploy/PREPARACAO_PUBLICACAO.md`, `CHECKLIST_*`, `HOSTINGER.md`, `WORKER_WINDOWS.md` | Revisados; alinhados ao código |
| `deploy/clipia-hostinger-public.zip` | 47 entradas; `inspect-hostinger-package.ps1` OK |
| `config/schema.sql` | 8 tabelas = uso em `repositories.php` |
| `config/env.production.example.php` | Modelo env privado (não vai no ZIP) |
| `LEIA-ME-HOSTINGER.txt` (conteúdo em `build-hostinger-package.ps1`) | Suficiente para resumo; guia completo no Git |
| `includes/audit_demo_importer.php` | Mantido no pacote (require em `projects.php`) |

### 12.2 Alterações realizadas nesta sessão

| Arquivo | Alteração |
|---------|-----------|
| **`deploy/GUIA_PUBLICACAO_MANUAL.md`** | **Criado** — instalação hPanel, env, MySQL, logs, worker, checklist Fases A/B/C |
| `deploy/build-hostinger-package.ps1` | LEIA-ME referencia `GUIA_PUBLICACAO_MANUAL.md` |
| `deploy/PREPARACAO_PUBLICACAO.md`, `HOSTINGER.md`, `README.md` | Links para o guia |
| `deploy/RELATORIO_FINAL_AUDITORIA_LOCAL.md` | Secção 12 (esta) |

### 12.3 Testes executados (resultados reais)

| Comando | Resultado |
|---------|-----------|
| `python -m unittest discover -s worker_service -p "test_*.py" -q` | **29 OK** (~9,4 s) |
| `python test_integration.py` | **exit 0** — [OK] 1–13 + 16 unittest embutidos (~9 s E2E local `127.0.0.1:8099`) |
| `.\deploy\verify-local.ps1` | **exit 0** |
| `.\deploy\build-hostinger-package.ps1` | **exit 0** + inspeção ZIP OK |

### 12.4 Inspeção do pacote

- **47 entradas**; varredura proibidos **OK**
- Sem `worker_service/`, `.env`, env reais/exemplo, samples, audit, mídia
- PHP essencial presente (`worker-api.php`, `schema.sql`, etc.)

### 12.5 Guia de publicação

**Caminho:** [`deploy/GUIA_PUBLICACAO_MANUAL.md`](GUIA_PUBLICACAO_MANUAL.md)

### 12.6 Pendências (dependem de você no hPanel)

- Upload/extração do ZIP em `public_html/clipia/`
- MySQL: import `schema.sql`, 8 tabelas
- Criar `private/clipia.env.php` (700/600)
- Validar site (Fases A/B do guia)
- Worker + 1 job (Fase C) **somente após** A+B

### 12.7 Produção

**Não validada** — nenhum passo hPanel executado pelo agente nesta sessão.

---

## 13. Auditoria HMAC, histórico Git e revalidação (~21:06 UTC-3)

### 13.1 Fallbacks HMAC — revisão e endurecimento

| Componente | Comportamento confirmado |
|------------|-------------------------|
| **`config/app.php`** | Em `APP_ENV=production`, default de `WORKER_SHARED_SECRET` é **string vazia** (não aplica fallback de dev). Em dev, fallback local explícito. |
| **`config/clipia_env_loader.php`** | `clipia_collect_production_config_issues()` exige secret ≥16 chars; **`clipia_is_disallowed_worker_secret_for_production()`** rejeita valores de exemplo/dev conhecidos → UI **503** em layout Hostinger (`clipia_abort_if_production_misconfigured`). |
| **`includes/worker_client.php`** | `verifyIncomingRequest`: secret &lt;16 → **503**; em produção, secret na blocklist → **503**; sem HMAC → **401**; HTTP quando `WORKER_REQUIRE_HTTPS` → **403**. |
| **`pages/worker-api.php`** | Não passa pelo abort 503 da UI, mas a API usa a mesma verificação HMAC (incl. blocklist em produção). |
| **`worker_service/clipia_worker/config.py`** | Fallback dev **somente** se backend é loopback e variável vazia/ausente; backend remoto (HTTPS) com secret vazio → **""** (worker não assina). Secret explícito de dev/exemplo em backend remoto → **""** (fail-closed). |

**Alterações nesta sessão (código + testes):**

- `config/clipia_env_loader.php` — blocklist produção (sem logar valores).
- `includes/worker_client.php` — rejeição 503 na API M2M em produção para secrets de exemplo/dev.
- `worker_service/clipia_worker/config.py` — mesma política para backend remoto.
- `worker_service/test_hmac_compat_offline.py` — +2 testes (remote+dev secret; política PHP offline via `php -r`).

**Limitação:** segredo dev ainda válido em **localhost** (integração local). Em produção, copiar `.env.example` para `private/clipia.env.php` **não** basta mais — gera 503 até secret exclusivo ≥16 fora da blocklist.

### 13.2 Histórico Git / Gitleaks

| Verificação | Resultado |
|-------------|-----------|
| **Gitleaks instalado** | **Não** (`gitleaks` ausente no PATH deste PC). |
| Arquivos reais no histórico (`worker_service/.env`, `env.production.php`, `clipia.env.php`) | **0** commits (por nome de caminho). |
| **`git log -S` heurístico** | `clipia-dev-hmac-…` → commits `73edbbc`, `9177bcc` (**placeholder de dev versionado**, não segredo de produção). `SUBSTITUA_PELA_SENHA_MYSQL` → `c9ac2a6` (**modelo** `env.production.example.php`). `sk-fictitious-env-openai` → `73edbbc`, `9177bcc` (**testes**). |
| Rastreados sensíveis atuais | Apenas `*.example` / `env.production.example.php` (placeholders). |

**Como rodar Gitleaks com segurança (quando você autorizar instalação local):**

1. Baixar release oficial em [gitleaks/gitleaks](https://github.com/gitleaks/gitleaks) (binário no PC, sem upload do repo).
2. Na raiz: `gitleaks detect --source . --verbose --redact` (só stdout local; revisar **caminho + regra + commit**, valores já redigidos).
3. Histórico: `gitleaks detect --source . --log-opts="--all" --redact`.
4. Tratar achados: **real** → rotacionar segredo e remover do histórico se necessário; **placeholder** → documentar; **falso positivo** → allowlist local `.gitleaks.toml` (não commitar segredos reais).

**Nesta sessão:** não foi instalada nenhuma ferramenta externa; nenhum dado enviado a serviços de terceiros.

### 13.3 Testes e pacote (resultados reais)

| Comando | Resultado |
|---------|-----------|
| `python -m unittest discover -s worker_service -p "test_*.py" -q` | **exit 0** — **Ran 31 tests** (~14,6 s) |
| `python test_integration.py` | **exit 0** — [OK] 1–13 + 16 unittest (~16 s) |
| `.\deploy\verify-local.ps1` | **exit 0** — PHP lint OK; HMAC offline **9/9**; security **6/6**; pacote OK |
| `.\deploy\build-hostinger-package.ps1` | **exit 0** + inspeção pós-build |

**ZIP (`deploy/clipia-hostinger-public.zip`):** **47 entradas**, varredura proibidos **OK** (sem `.env`, `worker_service/`, env reais/exemplo, mídia de demo).

**Avisos (não bloqueiam):** `verify_local_config` — host produção no diagnóstico (sem HTTP); `OPENAI_API_KEY` via Registro Windows com APIs pagas **desligadas**; ffprobe ausente (esperado com imageio-ffmpeg).

### 13.4 Pendências

| Item | Estado |
|------|--------|
| Gitleaks scan completo | **Pendente** — ferramenta não instalada; usar procedimento §13.2 quando autorizado |
| Produção Hostinger | **Não validada** — env privado, MySQL, login, HMAC real |
| Secret exclusivo produção | Obrigatório (blocklist rejeita dev/exemplo) |
| Commit das alterações §13.1 | Opcional; não feito nesta auditoria |
| Worker smoke HTTPS | Bloqueado até checklist manual A+B |

---

## 14. Conferência final do bloqueio HMAC (~21:15 UTC-3)

**Status da auditoria de credenciais:** **incompleta** — varredura **Gitleaks** (ou equivalente) do histórico Git **não executada** (`GITLEAKS=absent` no PATH). Heurísticas manuais anteriores (§13.2) **não substituem** scan blob-a-blob.

### 14.1 PHP (offline, sem produção)

| Cenário | Evidência | Resultado |
|---------|-----------|-----------|
| Segredo de desenvolvimento em produção | `clipia_is_disallowed_worker_secret_for_production()` + `verifyIncomingRequest(..., overrideSecret=dev)` com `APP_ENV=production` | **503** (`dev_blocked=1`) |
| Segredo curto (&lt;16) | `verifyIncomingRequest(..., 'short')` | **503** (`short_blocked=1`) |
| Segredo válido (fora da blocklist) sem HTTPS | `verifyIncomingRequest` com `HTTPS=off` | **403** (transporte inseguro; fail-closed antes de aceitar POST) |
| Default em produção sem env | `config/app.php`: default `WORKER_SHARED_SECRET` vazio quando `APP_ENV=production` | Não aplica fallback dev |
| UI Hostinger sem env completo | `clipia_abort_if_production_misconfigured()` | **503** HTML (log genérico, sem segredos) |

### 14.2 Worker Python (offline)

| Cenário | Teste | Resultado |
|---------|-------|-----------|
| Backend remoto HTTPS + secret vazio | `test_empty_dotenv_value_remote_backend_stays_empty` | Secret resolvido **""** |
| Backend remoto + secret dev explícito | `test_dev_secret_on_remote_backend_is_rejected` | **""** (não assina) |
| Backend local loopback + secret vazio | `test_empty_dotenv_value_local_backend_uses_dev_fallback` | Fallback dev (≥16) — **integração local** |

### 14.3 Testes executados nesta conferência

| Comando | Resultado |
|---------|-----------|
| `cd worker_service; python test_hmac_compat_offline.py -v` | **exit 0** — **Ran 10 tests** in ~0,28 s — **OK** |
| Script PHP temporário em `%TEMP%` (verifyIncomingRequest dev/short) | **exit 0** — linhas `dev_blocked=1`, `short_blocked=1`, `fn_dev=1`, `fn_ok=1` |
| `.\deploy\inspect-hostinger-package.ps1` | **exit 0** — **47 entradas**, varredura proibidos **OK** |

**Cobertura automatizada:** `test_hmac_compat_offline.py` inclui blocklist PHP, `verifyIncomingRequest` 503 (dev + curto), resolução remote/local do worker.

### 14.4 Pacote Hostinger

- **Arquivo:** `deploy/clipia-hostinger-public.zip`
- **Entradas:** 47
- **Proibidos:** OK — **nenhum** `.env` real, `env.production.php`, `env.local.php`, `worker_service/`, nem exemplos `env.*.example.php` no ZIP.

### 14.5 Pronto para upload manual?

| Critério | OK? |
|----------|-----|
| Bloqueio HMAC dev/exemplo em produção (PHP + worker remoto) | **Sim** (código + testes acima) |
| ZIP público sem arquivos de ambiente reais | **Sim** |
| Auditoria Git completa (Gitleaks) | **Não** — pendência de segurança **processual** |
| Produção Hostinger validada | **Não** |
| Env privado com segredo **exclusivo** (não dev/exemplo) | **Pendente** (você, no hPanel) |

**Conclusão:** o projeto está **pronto para iniciar o upload manual** do ZIP filtrado e a configuração privada no servidor, desde que você use um `WORKER_SHARED_SECRET` gerado localmente (fora da blocklist). A **auditoria de segurança do repositório não está fechada** até rodar Gitleaks (§13.2). **Produção não validada.**
