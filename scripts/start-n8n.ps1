# n8n local sin Docker (puerto 5678).
# Uso: .\scripts\start-n8n.ps1

$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $ProjectRoot

$dataDir = Join-Path $ProjectRoot ".n8n-data"
New-Item -ItemType Directory -Force -Path $dataDir | Out-Null

$env:N8N_HOST = "localhost"
$env:N8N_PORT = "5678"
$env:N8N_PROTOCOL = "http"
$env:WEBHOOK_URL = "http://localhost:5678"
$env:GENERIC_TIMEZONE = "America/Mexico_City"
$env:N8N_USER_FOLDER = $dataDir

Write-Host "n8n -> http://localhost:5678" -ForegroundColor Green
Write-Host "Datos en: $dataDir" -ForegroundColor Gray
Write-Host "Primera vez: crea usuario en el navegador." -ForegroundColor Gray
Write-Host "Ctrl+C para detener." -ForegroundColor Gray
Write-Host ""

npx --yes n8n start
