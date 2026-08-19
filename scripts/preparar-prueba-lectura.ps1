# Descarga un PDF pequeño y ejecuta la prueba del paso 2.
# Uso recomendado: doble clic en preparar-prueba-lectura.cmd
# (el .cmd evita bloqueos de PowerShell)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $ProjectRoot

$ejemplo = Join-Path $ProjectRoot 'storage\app\private\lectura\ejemplo-prueba.pdf'
$dir = Split-Path $ejemplo -Parent
New-Item -ItemType Directory -Force -Path $dir | Out-Null

if (-not (Test-Path $ejemplo)) {
    Write-Host 'Descargando PDF de ejemplo (arxiv, ~1 MB)...' -ForegroundColor Cyan
    Invoke-WebRequest -Uri 'https://arxiv.org/pdf/2206.01062' -OutFile $ejemplo -UseBasicParsing
}

Write-Host "Archivo listo: $ejemplo" -ForegroundColor Green
Write-Host ''
Write-Host 'Ejecutando prueba Docling directo...' -ForegroundColor Cyan
& (Join-Path $ProjectRoot 'scripts\test-solicitud-lectura.ps1') -FilePath $ejemplo -Via docling
