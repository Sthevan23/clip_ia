# Gera deploy/clipia-hostinger-public.zip — conteúdo para public_html na Hostinger
$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$Staging = Join-Path $PSScriptRoot 'staging-hostinger'
$ZipPath = Join-Path $PSScriptRoot 'clipia-hostinger-public.zip'

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

# config: sem credenciais locais
Copy-TreeFiltered 'config' -ExcludeNames @('env.local.php', 'env.production.php', '.sqlite', '.db')

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

# LEIA-ME dentro do pacote
@"
ClipIA — pacote Hostinger (public_html)
========================================
1. Extraia tudo dentro de public_html/
2. Importe config/schema.sql no MySQL (phpMyAdmin)
3. Crie ~/clipia_secrets/env.production.php (veja deploy/HOSTINGER.md no Git)
4. Worker Python roda FORA da Hostinger (pasta worker_service/)
"@ | Set-Content -Path (Join-Path $Staging 'LEIA-ME-HOSTINGER.txt') -Encoding UTF8

if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }
Compress-Archive -Path (Join-Path $Staging '*') -DestinationPath $ZipPath -CompressionLevel Optimal

Remove-Item $Staging -Recurse -Force

Write-Host ""
Write-Host "OK: $ZipPath"
Write-Host "Suba o conteudo extraido para public_html/ na Hostinger."
Write-Host "MySQL: importe config/schema.sql pelo phpMyAdmin."
