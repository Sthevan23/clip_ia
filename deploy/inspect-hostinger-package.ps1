# Inspeciona pacote publico Hostinger (ZIP ou pasta espelho).
# Uso:  .\deploy\inspect-hostinger-package.ps1
#       .\deploy\inspect-hostinger-package.ps1 -DirectoryPath .\deploy\hostinger-public

param(
    [switch]$Build,
    [string]$DirectoryPath = ''
)

$ErrorActionPreference = 'Stop'
$DeployDir = $PSScriptRoot
$ProjectRoot = Split-Path -Parent $DeployDir
$ZipPath = Join-Path $DeployDir 'clipia-hostinger-public.zip'
$BuildScript = Join-Path $DeployDir 'build-hostinger-package.ps1'
$ExpectedEntryCount = 48
$ExitCode = 0

function Write-InspectError {
    param([string]$Message)
    Write-Host ('ERRO: ' + $Message) -ForegroundColor Red
    $script:ExitCode = 1
}

function Get-ZipEntryUtf8Text {
    param(
        [System.IO.Compression.ZipArchive]$Archive,
        [string[]]$CandidatePaths
    )
    foreach ($candidate in $CandidatePaths) {
        $normalized = $candidate.Replace('\', '/').TrimStart('/')
        $entry = $Archive.GetEntry($normalized)
        if ($null -eq $entry) {
            $entry = $Archive.Entries |
                Where-Object { $_.FullName.Replace('/', '\') -ieq $candidate.Replace('/', '\') } |
                Select-Object -First 1
        }
        if ($null -eq $entry) {
            continue
        }
        $stream = $null
        try {
            $stream = $entry.Open()
            $reader = [System.IO.StreamReader]::new($stream, [System.Text.Encoding]::UTF8, $true)
            try {
                return $reader.ReadToEnd()
            }
            finally {
                $reader.Dispose()
            }
        }
        finally {
            if ($null -ne $stream) {
                $stream.Dispose()
            }
        }
    }
    return $null
}

function Test-PublicTextContains {
    param(
        [string]$Label,
        [string]$Text,
        [string[]]$Needles
    )
    if ($null -eq $Text -or $Text.Length -eq 0) {
        Write-InspectError ($Label + ': conteudo ausente ou ilegivel no pacote')
        return $false
    }
    foreach ($needle in $Needles) {
        if ($Text.IndexOf($needle, [StringComparison]::Ordinal) -lt 0) {
            Write-InspectError ($Label + ': marcador ausente no pacote: ' + $needle)
            return $false
        }
    }
    return $true
}

function Get-PublicEntryUtf8Text {
    param(
        [string]$RootDir,
        [string[]]$CandidatePaths
    )
    foreach ($candidate in $CandidatePaths) {
        $full = Join-Path $RootDir ($candidate.Replace('/', '\'))
        if (Test-Path -LiteralPath $full) {
            return [System.IO.File]::ReadAllText($full, [System.Text.Encoding]::UTF8)
        }
    }
    return $null
}

$InspectDirectory = $DirectoryPath -ne ''
if ($InspectDirectory) {
    $DirectoryPath = (Resolve-Path -LiteralPath $DirectoryPath).Path
}

Write-Host ('Projeto: ' + $ProjectRoot)
if ($InspectDirectory) {
    Write-Host ('Pasta alvo: ' + $DirectoryPath)
}
else {
    Write-Host ('ZIP alvo: ' + $ZipPath)
}

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

if ($InspectDirectory) {
    if (-not (Test-Path -LiteralPath $DirectoryPath)) {
        Write-InspectError ('Pasta publica nao encontrada: ' + $DirectoryPath)
        exit 1
    }
}
elseif (-not (Test-Path -LiteralPath $ZipPath)) {
    Write-InspectError ('Arquivo ZIP nao encontrado. Caminho esperado: ' + $ZipPath)
    Write-Host 'Dica: execute na raiz do repo: .\deploy\build-hostinger-package.ps1' -ForegroundColor Yellow
    Write-Host 'Nao use caminho relativo deploy\... com cwd em worker_service\.' -ForegroundColor Yellow
    exit 1
}

$zip = $null
if (-not $InspectDirectory) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    try {
        $zip = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
    }
    catch {
        Write-InspectError ('Falha ao abrir ZIP: ' + $_.Exception.Message)
        exit 1
    }
}

try {
    if ($InspectDirectory) {
        $rootLen = $DirectoryPath.Length
        $entries = @(Get-ChildItem -LiteralPath $DirectoryPath -Recurse -File | ForEach-Object {
            $_.FullName.Substring($rootLen).TrimStart('\', '/')
        } | Sort-Object)
    }
    else {
        $entries = @($zip.Entries | ForEach-Object { $_.FullName.Replace('/', '\') } | Sort-Object)
    }
    $count = $entries.Count
    Write-Host ('Arquivos no pacote: ' + $count)

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
            Write-InspectError ('Arquivo obrigatorio ausente no pacote: ' + $req)
        }
    }

    Write-Host '==> Conteudo critico (login/bootstrap) no pacote' -ForegroundColor Cyan
    if ($InspectDirectory) {
        $loginText = Get-PublicEntryUtf8Text -RootDir $DirectoryPath -CandidatePaths @('login.php')
        $repoText = Get-PublicEntryUtf8Text -RootDir $DirectoryPath -CandidatePaths @(
            'includes\repositories.php',
            'includes/repositories.php'
        )
    }
    else {
        $loginText = Get-ZipEntryUtf8Text -Archive $zip -CandidatePaths @('login.php', 'Login.php')
        $repoText = Get-ZipEntryUtf8Text -Archive $zip -CandidatePaths @(
            'includes/repositories.php',
            'includes\repositories.php'
        )
    }

    $loginBootstrapOk = Test-PublicTextContains -Label 'login.php' -Text $loginText -Needles @(
        'bootstrapOwnerFromFirstLoginIfAllowed',
        "defined('CLIPIA_BOOTSTRAP_OWNER_ON_FIRST_LOGIN')"
    )
    $repoBootstrapOk = Test-PublicTextContains -Label 'includes/repositories.php' -Text $repoText -Needles @(
        'bootstrapOwnerFromFirstLoginIfAllowed',
        'insertOwnerUserIfTableEmpty'
    )

    $zipLoginHasBootstrapFlag = if ($loginBootstrapOk) { 'True' } else { 'False' }
    $zipRepoHasBootstrap = if ($repoBootstrapOk) { 'True' } else { 'False' }
    Write-Host ('zip_login_has_bootstrap_flag=' + $zipLoginHasBootstrapFlag)
    Write-Host ('zip_repositories_has_bootstrap=' + $zipRepoHasBootstrap)

    if (-not $loginBootstrapOk -or -not $repoBootstrapOk) {
        $script:ExitCode = 1
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
