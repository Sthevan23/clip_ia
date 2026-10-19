# Verificacoes locais ClipIA - Windows PowerShell 5.1+ (offline, sem producao)
# Uso na raiz do projeto:  .\deploy\verify-local.ps1

$ErrorActionPreference = 'Continue'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$WorkerDir = Join-Path $ProjectRoot 'worker_service'
$OverallExit = 0
$InitialLocation = (Get-Location).Path

function Set-StepFailed {
    if ($OverallExit -eq 0) {
        $script:OverallExit = 1
    }
}

function Write-Step {
    param([string]$Message)
    Write-Host ''
    Write-Host ('==> ' + $Message) -ForegroundColor Cyan
}

function Get-ClipiaPython {
    $venvPy = Join-Path $WorkerDir '.venv\Scripts\python.exe'
    if (Test-Path -LiteralPath $venvPy) {
        return $venvPy
    }
    return 'python'
}

function Write-NativeOutput {
    param([object[]]$Lines)
    foreach ($line in $Lines) {
        if ($line -is [System.Management.Automation.ErrorRecord]) {
            Write-Host $line.ToString()
        }
        else {
            Write-Host $line
        }
    }
}

function Invoke-PhpLint {
    param([string[]]$RelativePaths)
    Write-Step 'Sintaxe PHP (php -l)'
    foreach ($rel in $RelativePaths) {
        $phpFile = Join-Path $ProjectRoot $rel
        if (-not (Test-Path -LiteralPath $phpFile)) {
            Write-Host ('ERRO: arquivo nao encontrado: ' + $phpFile) -ForegroundColor Red
            Set-StepFailed
            continue
        }
        Write-NativeOutput (& php -l $phpFile 2>&1)
        if ($LASTEXITCODE -ne 0) {
            Set-StepFailed
        }
    }
}

function Invoke-HostingerPathSimulation {
    Write-Step 'Simulacao offline do caminho private/clipia.env.php (Hostinger)'
    $tempPhp = Join-Path $env:TEMP ('clipia_path_sim_{0}.php' -f ([guid]::NewGuid().ToString('N')))
    try {
        $phpBody = @'
<?php
$projectRootDir = '/home/u586160337/domains/sthevandev.com.br/public_html/clipia';
$parentOfPublicRoot = dirname($projectRootDir);
$domainRootDir = dirname($parentOfPublicRoot);
echo 'domainRootDir=' . $domainRootDir . PHP_EOL;
echo 'private_env_path=' . $domainRootDir . '/private/clipia.env.php' . PHP_EOL;
'@
        $utf8NoBom = New-Object System.Text.UTF8Encoding $false
        [System.IO.File]::WriteAllText($tempPhp, $phpBody, $utf8NoBom)
        if (-not (Test-Path -LiteralPath $tempPhp)) {
            Write-Host 'AVISO: script temporario nao criado; simulacao de caminho ignorada.' -ForegroundColor Yellow
            return
        }
        Write-NativeOutput (& php $tempPhp 2>&1)
        if ($LASTEXITCODE -ne 0) {
            Set-StepFailed
        }
    }
    catch {
        Write-Host ('ERRO na simulacao de caminho: ' + $_.Exception.Message) -ForegroundColor Red
        Set-StepFailed
    }
    finally {
        if (Test-Path -LiteralPath $tempPhp) {
            Remove-Item -LiteralPath $tempPhp -Force -ErrorAction SilentlyContinue
        }
    }
}

function Invoke-WorkerChecks {
    param([string]$PythonExe)
    Write-Step 'Dependencias Python (import rapido)'
    Push-Location $WorkerDir
    try {
        Write-NativeOutput (& $PythonExe -c "import dotenv, httpx, pydantic; print('python_core_deps_ok=True')" 2>&1)
        if ($LASTEXITCODE -ne 0) {
            Set-StepFailed
        }
        Write-NativeOutput (& $PythonExe -c "import faster_whisper; print('faster_whisper_ok=True')" 2>&1)
        if ($LASTEXITCODE -ne 0) {
            Write-Host 'AVISO: faster_whisper nao importou (venv/pip install -r requirements.txt).' -ForegroundColor Yellow
        }
    }
    finally {
        Pop-Location -ErrorAction SilentlyContinue
    }

    Write-Step 'Worker - verify_local_config.py (offline)'
    Push-Location $WorkerDir
    try {
        Write-NativeOutput (& $PythonExe verify_local_config.py 2>&1)
        if ($LASTEXITCODE -ne 0) {
            Set-StepFailed
        }
    }
    finally {
        Pop-Location -ErrorAction SilentlyContinue
    }

    Write-Step 'Worker - test_hmac_compat_offline.py'
    Push-Location $WorkerDir
    try {
        # unittest no Windows escreve resumo em stderr; cmd /c evita ErrorRecord falso no PS 5.1
        $hmacCmd = 'cd /d "' + $WorkerDir + '" && "' + $PythonExe + '" test_hmac_compat_offline.py 2>&1'
        cmd /c $hmacCmd | ForEach-Object { Write-Host $_ }
        if ($LASTEXITCODE -ne 0) {
            Set-StepFailed
        }
    }
    finally {
        Pop-Location -ErrorAction SilentlyContinue
    }

    Write-Step 'Worker - test_security_offline.py'
    Push-Location $WorkerDir
    try {
        $secCmd = 'cd /d "' + $WorkerDir + '" && "' + $PythonExe + '" test_security_offline.py 2>&1'
        cmd /c $secCmd | ForEach-Object { Write-Host $_ }
        if ($LASTEXITCODE -ne 0) {
            Set-StepFailed
        }
    }
    finally {
        Pop-Location -ErrorAction SilentlyContinue
    }
}

try {
    $PythonExe = Get-ClipiaPython
    Write-Host ('Projeto: ' + $ProjectRoot)
    Write-Host ('Python: ' + $PythonExe)

    Invoke-PhpLint -RelativePaths @(
        'config\app.php',
        'config\clipia_env_loader.php',
        'config\database.php',
        'pages\worker-api.php'
    )

    Invoke-HostingerPathSimulation
    Invoke-WorkerChecks -PythonExe $PythonExe

    Write-Step 'Pacote Hostinger (ZIP filtrado — caminho absoluto deploy/)'
    $packageInspect = Join-Path $PSScriptRoot 'inspect-hostinger-package.ps1'
    if (Test-Path -LiteralPath $packageInspect) {
        & $packageInspect
        if ($LASTEXITCODE -ne 0) {
            Set-StepFailed
        }
    }
    else {
        Write-Host 'ERRO: inspect-hostinger-package.ps1 nao encontrado.' -ForegroundColor Red
        Set-StepFailed
    }

    Write-Step 'Inspecao offline de artefatos tinger* (se existirem)'
    $inspectScript = Join-Path $PSScriptRoot 'inspect-stray-artifacts.ps1'
    if (Test-Path -LiteralPath $inspectScript) {
        & $inspectScript
    }
    else {
        Write-Host 'AVISO: inspect-stray-artifacts.ps1 nao encontrado.' -ForegroundColor Yellow
    }
}
finally {
    if ($InitialLocation) {
        Set-Location -LiteralPath $InitialLocation -ErrorAction SilentlyContinue
    }
}

Write-Host ''
if ($OverallExit -eq 0) {
    Write-Host 'verify-local.ps1 concluido com exit 0. Revise linhas AVISO acima (sem segredos na saida).' -ForegroundColor Green
}
else {
    Write-Host ('verify-local.ps1 concluido com exit ' + $OverallExit + '.') -ForegroundColor Red
}

exit $OverallExit
