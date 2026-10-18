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

Crie **fora** de `public_html`:

`/home/uSEU_ID/clipia_secrets/env.production.php`

Use o modelo em **`deploy/clipia_secrets/env.production.example.php`** (copie, renomeie, preencha MySQL + `WORKER_SHARED_SECRET` + hash da senha do admin).

Permissões: pasta `700`, arquivo `600`.

## 5. Worker Python (não vai na Hostinger)

No seu PC/VPS, mantenha **`worker_service/`** com `.env` apontando para:

`https://seudominio.com.br/pages/worker-api.php`

Mesmo `WORKER_SHARED_SECRET` do PHP.

## 6. Checklist rápido

| Onde | O quê |
|------|--------|
| `public_html/` | Conteúdo do ZIP |
| MySQL | `schema.sql` importado |
| `~/clipia_secrets/` | `env.production.php` |
| PC/VPS | `worker_service/` + FFmpeg + Whisper |
