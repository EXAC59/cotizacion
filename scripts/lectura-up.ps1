# Levanta Docling + n8n (encuentra docker.exe aunque no este en PATH).
# Uso:
#   .\scripts\lectura-up.ps1
#   powershell -ExecutionPolicy Bypass -File .\scripts\lectura-up.ps1

$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $ProjectRoot

function Get-DockerExe {
    $cmd = Get-Command docker -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }

    $candidates = @(
        "$env:ProgramFiles\Docker\Docker\resources\bin\docker.exe",
        "${env:ProgramFiles(x86)}\Docker\Docker\resources\bin\docker.exe"
    )
    foreach ($p in $candidates) {
        if (Test-Path -LiteralPath $p) { return $p }
    }
    return $null
}

$docker = Get-DockerExe
if (-not $docker) {
    Write-Host ""
    Write-Host "ERROR: No se encontro docker.exe" -ForegroundColor Red
    Write-Host "Instala Docker Desktop y reinicia PowerShell." -ForegroundColor Yellow
    Write-Host ""
    exit 1
}

Write-Host "Docker: $docker" -ForegroundColor Gray

Write-Host "Comprobando motor Docker..." -ForegroundColor Gray
& $docker info *> $null
if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "ERROR: Docker instalado pero el motor no responde." -ForegroundColor Red
    Write-Host "Abre la app 'Docker Desktop' y espera 'Engine running'." -ForegroundColor Yellow
    Write-Host ""
    exit 1
}

Write-Host "Descargando imagenes (primera vez tarda 10-30 min)..." -ForegroundColor Cyan
& $docker compose -f docker-compose.lectura.yml up -d
if ($LASTEXITCODE -ne 0) {
    Write-Host "Fallo docker compose." -ForegroundColor Red
    exit $LASTEXITCODE
}

Write-Host ""
Write-Host "Listo:" -ForegroundColor Green
Write-Host "  Docling UI  -> http://localhost:5001/ui"
Write-Host "  n8n         -> http://localhost:5678"
Write-Host ""
Write-Host "  php artisan config:clear"
Write-Host "  php artisan docling:ping"
Write-Host ""
