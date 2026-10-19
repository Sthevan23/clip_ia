# Revisao de seguranca — ClipIA (analise estatica local)

**Escopo:** codigo no repositorio; **nao** inclui pentest de producao.

## Autenticacao e sessao

| Area | Implementacao | Observacao |
|------|-------------|------------|
| Login | `password_verify()` em `login.php` | Mensagem generica em falha (anti-enumeracao) |
| CSRF | `verify_csrf()` no POST de login | — |
| Sessao | Cookies HttpOnly, Secure em HTTPS, SameSite Lax (`config/app.php`) | — |
| Admin inicial | `ensureOwnerAccountIfEmpty()` so se `users` vazia | Nao sobrescreve contas existentes |
| Cadastro publico | Desabilitado (`register.php` / `PUBLIC_REGISTRATION_ENABLED`) | — |

## Worker API (PHP ↔ Python)

| Area | Implementacao | Teste offline |
|------|-------------|---------------|
| HMAC | `hash_hmac('sha256', ts + '.' + body, secret)` | `test_hmac_compat_offline.py` (+ PHP CLI) |
| Anti-replay | Janela 300 s (`worker_client.php`) | — |
| HTTPS producao | `WORKER_REQUIRE_HTTPS` | `test_security_offline.py` |
| Segredo curto | Rejeicao se &lt; 16 chars | `test_hmac_compat_offline.py` |

## Upload e armazenamento (PHP)

| Area | Implementacao |
|------|-------------|
| MIME real | `finfo` no conteudo, whitelist MP4/MOV/WebM |
| Extensao | Deve bater com MIME |
| Nome final | Gerado pelo servidor (`u{id}_data_random.ext`), nao usa nome original no disco |
| Permissao arquivo | `0640` em uploads/clips |
| Diretorios | `storage/uploads`, `storage/clips` com `.htaccess` |
| Cotas | Por usuario e global (`VideoUploadManager::enforceStorageQuotas`) |
| Disco livre | `checkFreeDiskSpace` antes de gravar |

## Worker Python — fontes de midia

| Regra | Onde |
|-------|------|
| Sem download de URL arbitraria | `validate_authorized_local_media_file` |
| Extensoes permitidas | `.mp4`, `.mov`, `.webm`, audio permitido para transcricao |
| API paga | Bloqueada se `CLIPIA_ALLOW_PAID_API=false` |

## Logs e segredos

| Area | Implementacao |
|------|-------------|
| PHP producao | Erros em arquivo, nao na tela |
| DB erro publico | Mensagem generica (`Database::getPublicErrorMessage`) |
| Python | `redact_sensitive_text`, filtros de log |
| Verify local | Nao imprime segredos; so comprimento HMAC e flags |

## Pendencias (fora do codigo)

- Validar permissoes reais no servidor Hostinger.
- Confirmar SSL e headers de proxy (`X-Forwarded-Proto`).
- Teste de login manual (`CHECKLIST_VALIDACAO_PRODUCAO.md` D.7).
