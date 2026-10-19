# Gera deploy/hostinger-public/ (espelho do ZIP) + FILELIST e instrucoes para branch hostinger-public.
# Nao executa git add/commit/push. Nao faz deploy.
# Uso: na raiz do projeto:  .\deploy\prepare-hostinger-git-branch.ps1

$ErrorActionPreference = 'Stop'
$DeployDir = $PSScriptRoot
$ProjectRoot = Split-Path -Parent $DeployDir
$PublicDir = Join-Path $DeployDir 'hostinger-public'
$FileListPath = Join-Path $DeployDir 'hostinger-public-FILELIST.txt'
$BuildScript = Join-Path $DeployDir 'build-hostinger-package.ps1'

Write-Host ('Projeto: ' + $ProjectRoot) -ForegroundColor Cyan
Write-Host '==> Gerando pasta publica (identica ao ZIP aprovado)...' -ForegroundColor Cyan

& $BuildScript -Output Directory
if ($LASTEXITCODE -ne 0) {
    Write-Host 'ERRO: build-hostinger-package.ps1 falhou.' -ForegroundColor Red
    exit $LASTEXITCODE
}

if (-not (Test-Path -LiteralPath $PublicDir)) {
    Write-Host ('ERRO: pasta nao criada: ' + $PublicDir) -ForegroundColor Red
    exit 1
}

$rootLen = $PublicDir.Length
$relativeFiles = @(Get-ChildItem -LiteralPath $PublicDir -Recurse -File | ForEach-Object {
    $_.FullName.Substring($rootLen).TrimStart('\', '/').Replace('\', '/')
} | Sort-Object)

$relativeFiles | Set-Content -Path $FileListPath -Encoding UTF8

Write-Host ''
Write-Host ('Arquivos publicos: ' + $relativeFiles.Count)
Write-Host ('Lista gravada: ' + $FileListPath)
Write-Host ''
Write-Host '==> Proximos passos (EXECUTE SOMENTE APOS AUTORIZACAO)' -ForegroundColor Yellow
Write-Host @"

1) Commit em main (codigo fonte + deploy/*), SEM segredos — ver mensagem sugerida no relatorio do agente.

2) Publicar branch hostinger-public (arvore = somente deploy/hostinger-public/):

   cd "$ProjectRoot"
   git fetch origin

   # Opcao A — worktree (recomendado; nao mexe na working tree de main):
   git worktree add ../clip_ia-hostinger-public hostinger-public 2>`$null
   if (-not `$?) { git branch hostinger-public origin/hostinger-public 2>`$null; git worktree add ../clip_ia-hostinger-public hostinger-public }

   Remove-Item -Recurse -Force ../clip_ia-hostinger-public/* 
   Copy-Item -Path "$PublicDir\*" -Destination ../clip_ia-hostinger-public -Recurse -Force
   cd ../clip_ia-hostinger-public
   git add -A
   git status
   git commit -m "Hostinger public: sync com pacote filtrado (login/bootstrap)."
   git push origin hostinger-public
   cd "$ProjectRoot"
   git worktree remove ../clip_ia-hostinger-public

   # Opcao B — se branch ainda nao existir no remoto, crie orphan localmente
   # (consulte deploy/HOSTINGER_AUTO_DEPLOY.md).

3) hPanel -> Git -> branch hostinger-public -> Deploy directory public_html/clipia

4) Apos deploy: confirmar ausencia de worker_service/ e login/bootstrap OK.

"@

exit 0
