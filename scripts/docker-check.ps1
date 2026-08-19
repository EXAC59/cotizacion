# Comprueba que docker funciona en ESTA ventana de PowerShell.
# Uso: .\scripts\docker-check.ps1

$ErrorActionPreference = "Continue"

Write-Host "=== Diagnostico Docker ===" -ForegroundColor Cyan
Write-Host ""

$cmd = Get-Command docker -ErrorAction SilentlyContinue
if ($cmd) {
    Write-Host "[OK] comando 'docker' en PATH -> $($cmd.Source)" -ForegroundColor Green
} else {
    Write-Host "[FALTA] 'docker' no esta en PATH de esta ventana" -ForegroundColor Yellow
    $full = "C:\Program Files\Docker\Docker\resources\bin\docker.exe"
    if (Test-Path $full) {
        Write-Host "       Pero existe: $full" -ForegroundColor Gray
        Write-Host "       Solucion: cierra PowerShell, abre uno NUEVO, o usa:" -ForegroundColor Cyan
        Write-Host '       & "C:\Program Files\Docker\Docker\resources\bin\docker.exe" version'
    } else {
        Write-Host "       docker.exe no encontrado. Instala Docker Desktop." -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "PATH con Docker:" -ForegroundColor Gray
($env:Path -split ';' | Where-Object { $_ -match 'Docker' }) | ForEach-Object { Write-Host "  $_" }

Write-Host ""
if ($cmd) {
    docker version
    Write-Host ""
    docker info 2>&1 | Select-Object -First 8
} elseif (Test-Path "C:\Program Files\Docker\Docker\resources\bin\docker.exe") {
    & "C:\Program Files\Docker\Docker\resources\bin\docker.exe" version
}

Write-Host ""
Write-Host "Para levantar lectura:" -ForegroundColor Cyan
Write-Host "  .\scripts\lectura-up.ps1"
Write-Host "  o doble clic en scripts\lectura-up.cmd"
Write-Host ""
