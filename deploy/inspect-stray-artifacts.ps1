# Inspecao offline de artefatos acidentais na raiz do projeto (PowerShell 5.1+).
# Nao executa o conteudo dos arquivos; nao imprime segredos.
# Uso: .\deploy\inspect-stray-artifacts.ps1

$ErrorActionPreference = 'Continue'
$ProjectRoot = Split-Path -Parent $PSScriptRoot

function Inspect-StrayFile {
    param([System.IO.FileInfo]$FileInfo)

    Write-Host ''
    Write-Host ('==> ' + $FileInfo.Name) -ForegroundColor Cyan
    Write-Host ('full_name_literal=' + $FileInfo.FullName)
    Write-Host ('length_bytes=' + $FileInfo.Length)
    Write-Host ('last_write_utc=' + $FileInfo.LastWriteTimeUtc.ToString('o'))
    Write-Host ('name_utf8_hex=' + [BitConverter]::ToString([Text.Encoding]::UTF8.GetBytes($FileInfo.Name)))

    $stream = $null
    try {
        $stream = [System.IO.File]::OpenRead($FileInfo.FullName)
        $buf = New-Object byte[] 512
        $read = $stream.Read($buf, 0, $buf.Length)
        if ($read -le 0) {
            Write-Host 'content_class=empty'
            return
        }
        $hexLines = @()
        for ($i = 0; $i -lt $read; $i += 16) {
            $take = [Math]::Min(16, $read - $i)
            $chunk = $buf[$i..($i + $take - 1)]
            $hexLines += (($chunk | ForEach-Object { '{0:X2}' -f $_ }) -join ' ')
        }
        Write-Host ('first_bytes_hex (max 512)=' + ($hexLines -join ' | '))

        $sampleLen = [Math]::Min(120, $read)
        $sampleText = [Text.Encoding]::UTF8.GetString($buf, 0, $sampleLen)
        $sampleText = $sampleText -replace '[\r\n\t]+', ' '
        $normalized = $sampleText -replace '.\x08', ''
        if ($sampleText.Length -gt 80) {
            $sampleText = $sampleText.Substring(0, 80) + '...'
        }
        Write-Host ('text_sample_sanitized=' + $sampleText)

        if ($normalized -match 'SUMMARY OF LESS|LESS COMMANDS|Display this help') {
            Write-Host 'probable_purpose=accidental_less_pager_help_redirect'
        }
        elseif ($sampleText -match '^\s*$') {
            Write-Host 'probable_purpose=empty_or_whitespace'
        }
        else {
            Write-Host 'probable_purpose=unknown_review_manually'
        }
        Write-Host 'action_recommended=safe_to_delete_manually_if_confirmed_not_needed'
    }
    catch {
        Write-Host ('inspect_error=' + $_.Exception.Message)
    }
    finally {
        if ($null -ne $stream) {
            $stream.Close()
        }
    }
}

Write-Host ('project_root=' + $ProjectRoot)
$matches = Get-ChildItem -LiteralPath $ProjectRoot -File | Where-Object { $_.Name -like 'tinger*' }
if (-not $matches) {
    Write-Host 'no_files_matching_tinger*'
    exit 0
}
foreach ($f in $matches) {
    Inspect-StrayFile -FileInfo $f
}
exit 0
