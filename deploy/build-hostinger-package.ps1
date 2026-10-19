# Gera artefato publico Hostinger (ZIP e/ou pasta espelho do ZIP aprovado).
# Uso padrao:  .\deploy\build-hostinger-package.ps1
# So pasta:    .\deploy\build-hostinger-package.ps1 -Output Directory
# ZIP + pasta: .\deploy\build-hostinger-package.ps1 -Output Both
param(
    [ValidateSet('Zip', 'Directory', 'Both')]
    [string]$Output = 'Zip'
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$Staging = Join-Path $PSScriptRoot 'staging-hostinger'
$ZipPath = Join-Path $PSScriptRoot 'clipia-hostinger-public.zip'
$PublicDir = Join-Path $PSScriptRoot 'hostinger-public'
$WriteZip = ($Output -eq 'Zip') -or ($Output -eq 'Both')
$WriteDir = ($Output -eq 'Directory') -or ($Output -eq 'Both')

if (Test-Path $Staging) {
    Remove-Item $Staging -Recurse -Force
}
New-Item -ItemType Directory -Path $Staging | Out-Null

function Copy-TreeFiltered {
    param(
        [string]$SourceRel,
        [string[]]$ExcludeNames = @()
    )
    $src = Join-Path $ProjectRoot $SourceRel
    if (-not (Test-Path $src)) { return }
    $dest = Join-Path $Staging $SourceRel
    New-Item -ItemType Directory -Path (Split-Path $dest -Parent) -Force | Out-Null
    Get-ChildItem -Path $src -Recurse -File | ForEach-Object {
        $rel = $_.FullName.Substring($src.Length).TrimStart('\', '/')
        $skip = $false
        foreach ($part in $ExcludeNames) {
            if ($rel -like "*$part*") { $skip = $true; break }
        }
        if ($skip) { return }
        $target = Join-Path $dest $rel
        $dir = Split-Path $target -Parent
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        Copy-Item $_.FullName -Destination $target -Force
    }
}

# PHP na raiz
Get-ChildItem -Path $ProjectRoot -Filter '*.php' -File | ForEach-Object {
    Copy-Item $_.FullName -Destination (Join-Path $Staging $_.Name) -Force
}

Copy-Item (Join-Path $ProjectRoot '.htaccess') -Destination (Join-Path $Staging '.htaccess') -Force

foreach ($dir in @('assets', 'includes', 'pages')) {
    Copy-TreeFiltered $dir
}

# config: sem credenciais locais nem modelos de env (modelo fica no Git — ver LEIA-ME)
Copy-TreeFiltered 'config' -ExcludeNames @(
    'env.local.php',
    'env.local.example.php',
    'env.production.php',
    'env.example.php',
    'env.production.example.php',
    '.sqlite',
    '.db'
)

# storage: só estrutura protegida (sem samples, audit, clipes reais)
$storageDirs = @(
    @{ Rel = 'storage'; Files = @('.htaccess') },
    @{ Rel = 'storage/uploads'; Files = @('.gitkeep', '.htaccess') },
    @{ Rel = 'storage/clips'; Files = @('.gitkeep', '.htaccess') },
    @{ Rel = 'storage/logs'; Files = @('.gitkeep') }
)
foreach ($block in $storageDirs) {
    $destDir = Join-Path $Staging $block.Rel
    New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    foreach ($name in $block.Files) {
        $srcFile = Join-Path $ProjectRoot ($block.Rel + '/' + $name)
        if (Test-Path $srcFile) {
            Copy-Item $srcFile -Destination (Join-Path $destDir $name) -Force
        } elseif ($name -eq '.gitkeep') {
            New-Item -ItemType File -Path (Join-Path $destDir $name) -Force | Out-Null
        }
    }
}

# Legado uploads/videos (vazio)
$legacyVideos = Join-Path $Staging 'uploads/videos'
New-Item -ItemType Directory -Path $legacyVideos -Force | Out-Null
$legacyKeep = Join-Path $ProjectRoot 'uploads/videos/.gitkeep'
if (Test-Path $legacyKeep) {
    Copy-Item $legacyKeep -Destination (Join-Path $legacyVideos '.gitkeep') -Force
} else {
    New-Item -ItemType File -Path (Join-Path $legacyVideos '.gitkeep') -Force | Out-Null
}

# LEIA-ME autocontido (sem depender de deploy/*.md no servidor)
@"
ClipIA — pacote Hostinger (FTP / upload manual)
===============================================

PUBLICACAO — LEIA PRIMEIRO
--------------------------
- Este ZIP contem SOMENTE PHP, assets e pastas vazias de storage para public_html/clipia/.
- NAO inclui: worker_service/, .env, segredos, videos, logs nem modelos env.example.
- Auto Deploy da branch main (raiz do repo) publica worker_service/, deploy/, samples, etc.
  Use branch hostinger-public (somente esta arvore) — ver deploy/HOSTINGER_AUTO_DEPLOY.md.

1) Extrair arquivos
   Extraia todo o conteudo em:
   public_html/clipia/

2) MySQL (phpMyAdmin no hPanel)
   - Crie banco e usuario MySQL e vincule permissoes.
   - Importe o arquivo deste pacote: config/schema.sql
   - Devem existir 8 tabelas (users, projects, source_videos, clip_candidates,
     processing_jobs, usage_logs, monthly_budgets, budget_reservations).

3) Configuracao privada (NUNCA dentro de public_html)
   Caminho absoluto no servidor:
   /home/u586160337/domains/sthevandev.com.br/private/clipia.env.php

   Permissoes recomendadas: pasta private ~700; arquivo clipia.env.php ~600.

   Como montar clipia.env.php:
   - No seu PC, clone/abra o repositorio Git do ClipIA.
   - Use como modelo config/env.production.example.php (NAO copie esse arquivo para public_html).
   - Crie clipia.env.php com <?php return [ ... ]; e preencha no minimo:
     APP_ENV=production, APP_URL=https://clipia.sthevandev.com.br,
     DB_DRIVER=mysql, DB_HOST (geralmente 127.0.0.1 na Hostinger), DB_PORT, DB_NAME,
     DB_USER, DB_PASS, WORKER_SHARED_SECRET (minimo 16 caracteres),
     WORKER_REQUIRE_HTTPS=true.
   - Se users estiver vazia: ADMIN_OWNER_EMAIL + ADMIN_OWNER_PASSWORD_HASH (recomendado),
     OU temporariamente CLIPIA_BOOTSTRAP_OWNER_ON_FIRST_LOGIN=1 para criar admin no 1o login
     (depois volte para 0). Bootstrap padrao no codigo: desligado.

4) PHP no hPanel
   - Versao PHP 8.2 ou superior.
   - display_errors = Off.
   - upload_max_filesize e post_max_size >= 100M (recomendado 128M).

5) Worker Python (seu PC Windows — FORA da Hostinger)
   - Instale worker_service/ apenas no computador local, nunca em public_html.
   - CLIPIA_WORKER_SHARED_SECRET no .env local deve ser o MESMO valor de
     WORKER_SHARED_SECRET em private/clipia.env.php (compare apenas no servidor/PC,
     nunca envie segredos por e-mail ou chat).
   - Nao rode processamento de jobs ate o site carregar sem erro 503 e login OK.

6) Verificacao rapida no navegador
   - https://clipia.sthevandev.com.br/ nao deve mostrar "Configuracao do servidor incompleta".
   - /login.php deve exibir o formulario (sem erro de banco na tela).

Documentacao no PC (repositorio Git, nao vai no ZIP):
  deploy/INICIO_RAPIDO_HOSTINGER.md  (minimo manual — comece aqui)
  deploy/GUIA_PUBLICACAO_MANUAL.md   (passo a passo completo)
  deploy/PREPARACAO_PUBLICACAO.md
  deploy/CHECKLIST_VALIDACAO_PRODUCAO.md
  deploy/HOSTINGER.md
  deploy/WORKER_WINDOWS.md

Apos extrair: confira arquivos proibidos (GUIA part. 2.3 ou PREPARACAO secao 3.2).
"@ | Set-Content -Path (Join-Path $Staging 'LEIA-ME-HOSTINGER.txt') -Encoding UTF8

if ($WriteDir) {
    if (Test-Path $PublicDir) {
        Remove-Item $PublicDir -Recurse -Force
    }
    Copy-Item -Path $Staging -Destination $PublicDir -Recurse -Force
}

if ($WriteZip) {
    if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }
    Compress-Archive -Path (Join-Path $Staging '*') -DestinationPath $ZipPath -CompressionLevel Optimal
}

Remove-Item $Staging -Recurse -Force

Write-Host ""
if ($WriteZip) {
    Write-Host "OK ZIP: $ZipPath"
}
if ($WriteDir) {
    Write-Host "OK pasta publica: $PublicDir"
}
Write-Host "Destino servidor: public_html/clipia/"
Write-Host "MySQL: importe config/schema.sql pelo phpMyAdmin."

$inspectScript = Join-Path $PSScriptRoot 'inspect-hostinger-package.ps1'
if (Test-Path -LiteralPath $inspectScript) {
    Write-Host ""
    if ($WriteZip) {
        Write-Host "==> Validando composicao do ZIP..." -ForegroundColor Cyan
        & $inspectScript
        if ($LASTEXITCODE -ne 0) {
            Write-Host "ERRO: inspecao do pacote falhou (exit $LASTEXITCODE)." -ForegroundColor Red
            exit $LASTEXITCODE
        }
    }
    if ($WriteDir) {
        Write-Host "==> Validando pasta publica (espelho do ZIP)..." -ForegroundColor Cyan
        & $inspectScript -DirectoryPath $PublicDir
        if ($LASTEXITCODE -ne 0) {
            Write-Host "ERRO: inspecao da pasta publica falhou (exit $LASTEXITCODE)." -ForegroundColor Red
            exit $LASTEXITCODE
        }
    }
}
else {
    Write-Host "AVISO: inspect-hostinger-package.ps1 ausente; artefato nao foi validado automaticamente." -ForegroundColor Yellow
}
