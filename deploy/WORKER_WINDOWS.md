# ClipIA — Worker Python no Windows (local)

O worker **nao** roda na Hostinger. Ele roda no seu PC e comunica com `pages/worker-api.php` via **HTTPS + HMAC**.

## Requisitos

| Item | Detalhe |
|------|---------|
| SO | Windows 10/11 |
| Python | 3.10+ (3.11 recomendado) |
| PHP | No PATH (testes HMAC offline e geracao de hash/HMAC) |
| FFmpeg | Pasta `bin` no PATH (`ffmpeg` + `ffprobe`) ou `CLIPIA_FFMPEG_BINARY` no `.env` |
| Rede | HTTPS para producao; **nao** e necessario para `verify-local.ps1` |

## Instalacao (uma vez)

```powershell
Set-Location C:\Users\Administrador\OneDrive\Desktop\projeto_clips\worker_service
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
Copy-Item .env.example .env
```

Edite **`.env`** (gitignored) — veja `worker_service/.env.example`:

- `CLIPIA_BACKEND_API_URL=https://clipia.sthevandev.com.br/pages/worker-api.php`
- `CLIPIA_WORKER_SHARED_SECRET=` **mesmo valor** que `WORKER_SHARED_SECRET` em `private/clipia.env.php` na Hostinger
- `CLIPIA_VERIFY_TLS=true`
- `CLIPIA_AI_PROVIDER=local_heuristic`
- `CLIPIA_ALLOW_PAID_API=false` (**obrigatorio** para modo gratuito)

**Nao** commite `.env`. **Nao** deixe `CLIPIA_WORKER_SHARED_SECRET=` vazio com backend HTTPS remoto.

## Verificacao offline (sem fila, sem producao)

Na **raiz** do projeto:

```powershell
Set-Location C:\Users\Administrador\OneDrive\Desktop\projeto_clips
.\deploy\verify-local.ps1
```

Confirme: `hmac_ready=True`, `allow_paid_api=False`, testes HMAC e seguranca OK.

## Processamento (somente apos Hostinger validada)

Quando o painel PHP estiver OK e voce **autorizar**:

```powershell
Set-Location worker_service
python main.py --process-next
```

Modo continuo (poll) — use apenas quando estiver pronto: `python main.py --poll 15`.

## Hostinger (PHP + MySQL)

- Guia deploy: [`HOSTINGER.md`](HOSTINGER.md)
- Checklist manual: [`CHECKLIST_VALIDACAO_PRODUCAO.md`](CHECKLIST_VALIDACAO_PRODUCAO.md)

## Seguranca

- Segredos so em `.env` local e `private/clipia.env.php` no servidor.
- Logs do worker usam `SensitiveDataLogFilter` (ver `clipia_worker/security.py`).
- URLs arbitrarias de video sao **bloqueadas** no Python; upload autorizado e via PHP.
