# Inspeciona deploy/clipia-hostinger-public.zip (caminho absoluto a partir de deploy/).
# Uso: na raiz do projeto:  .\deploy\inspect-hostinger-package.ps1
#      opcional:            .\deploy\inspect-hostinger-package.ps1 -Build

param(
    [switch]$Build
)

$ErrorActionPreference = 'Stop'
$DeployDir = $PSScriptRoot
$ProjectRoot = Split-Path -Parent $DeployDir
$ZipPath = Join-Path $DeployDir 'clipia-hostinger-public.zip'
$BuildScript = Join-Path $DeployDir 'build-hostinger-package.ps1'
$ExpectedEntryCount = 47
$ExitCode = 0

function Write-InspectError {
    param([string]$Message)
    Write-Host ('ERRO: ' + $Message) -ForegroundColor Red
    $script:ExitCode = 1
}

Write-Host ('Projeto: ' + $ProjectRoot)
Write-Host ('ZIP alvo: ' + $ZipPath)

if ($Build) {
    if (-not (Test-Path -LiteralPath $BuildScript)) {
        Write-InspectError ('Script de build nao encontrado: ' + $BuildScript)
        exit $ExitCode
    }
    Write-Host '==> Regenerando pacote (build-hostinger-package.ps1)...' -ForegroundColor Cyan
    & $BuildScript
    if ($LASTEXITCODE -ne 0) {
        Write-InspectError 'build-hostinger-package.ps1 falhou.'
        exit 1
    }
}

if (-not (Test-Path -LiteralPath $ZipPath)) {
    Write-InspectError ('Arquivo ZIP nao encontrado. Caminho esperado: ' + $ZipPath)
    Write-Host 'Dica: execute na raiz do repo: .\deploy\build-hostinger-package.ps1' -ForegroundColor Yellow
    Write-Host 'Nao use caminho relativo deploy\... com cwd em worker_service\.' -ForegroundColor Yellow
    exit 1
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = $null
try {
    $zip = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
}
catch {
    Write-InspectError ('Falha ao abrir ZIP: ' + $_.Exception.Message)
    exit 1
}

try {
    $entries = @($zip.Entries | ForEach-Object { $_.FullName.Replace('/', '\') } | Sort-Object)
    $count = $entries.Count
    Write-Host ('Entradas no ZIP: ' + $count)

    if ($count -ne $ExpectedEntryCount) {
        Write-Host ('AVISO: esperado ' + $ExpectedEntryCount + ' entradas; encontrado ' + $count + '.') -ForegroundColor Yellow
    }

    $forbiddenPatterns = @(
        @{ Label = 'worker_service'; Regex = '(?i)\\worker_service\\|^worker_service\\' },
        @{ Label = 'dotenv'; Regex = '(?i)\\\.env$|^\.env$|\\\.env\.' },
        @{ Label = 'env.production.php'; Regex = '(?i)\\env\.production\.php$' },
        @{ Label = 'env.local.php'; Regex = '(?i)\\env\.local\.php$' },
        @{ Label = 'env.example.php'; Regex = '(?i)\\env\.(example|production\.example)\.php$' },
        @{ Label = 'storage/samples'; Regex = '(?i)\\storage\\samples\\' },
        @{ Label = 'storage/audit data'; Regex = '(?i)\\storage\\audit_' },
        @{ Label = 'import_audit_demo'; Regex = '(?i)import_audit_demo' },
        @{ Label = 'media mp4/wav'; Regex = '(?i)\.(mp4|wav)$' },
        @{ Label = 'log files'; Regex = '(?i)\\.log$' },
        @{ Label = 'deploy folder'; Regex = '(?i)^deploy\\' }
    )

    $violations = @()
    foreach ($name in $entries) {
        foreach ($pat in $forbiddenPatterns) {
            if ($name -match $pat.Regex) {
                $violations += ($pat.Label + ': ' + $name)
            }
        }
    }

    if ($violations.Count -gt 0) {
        Write-InspectError ('Itens proibidos no pacote (' + $violations.Count + '):')
        $violations | ForEach-Object { Write-Host ('  - ' + $_) -ForegroundColor Red }
    }
    else {
        Write-Host 'Varredura proibidos: OK' -ForegroundColor Green
    }

    $required = @(
        'pages\worker-api.php',
        'config\schema.sql',
        'config\clipia_env_loader.php',
        'LEIA-ME-HOSTINGER.txt',
        '.htaccess'
    )
    foreach ($req in $required) {
        $found = $entries | Where-Object { $_ -ieq $req }
        if (-not $found) {
            Write-InspectError ('Arquivo obrigatorio ausente no ZIP: ' + $req)
        }
    }

    if ($ExitCode -eq 0) {
        Write-Host 'Inspecao do pacote Hostinger: OK' -ForegroundColor Green
    }
}
finally {
    if ($null -ne $zip) {
        $zip.Dispose()
    }
}

exit $ExitCode
