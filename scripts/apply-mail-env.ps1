# Aplica variables MAIL_* de scripts/.mail.local al .env del VPS y reinicia queue/api.
# Requiere scripts/.vps-deploy.local y scripts/.mail.local

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$mailFile = Join-Path $PSScriptRoot '.mail.local'
$deployFile = Join-Path $PSScriptRoot '.vps-deploy.local'

if (-not (Test-Path $mailFile)) {
  Write-Error "Falta $mailFile — copia .mail.local.example y completa SMTP."
}
if (-not (Test-Path $deployFile)) {
  Write-Error "Falta $deployFile"
}

$mailLines = Get-Content $mailFile | Where-Object { $_ -match '^\s*MAIL_' }
if ($mailLines.Count -lt 3) {
  Write-Error 'scripts/.mail.local no tiene variables MAIL_* suficientes.'
}

$tmp = Join-Path $env:TEMP 'cotizacion-mail-patch.env'
$mailLines | Set-Content -Path $tmp -Encoding utf8

Write-Host 'Subiendo patch MAIL_* y aplicando en VPS...'
& (Join-Path $PSScriptRoot 'sync-to-vps.ps1')
Write-Host @"

Luego en SSH:
  grep ^MAIL_ .env
  # fusionar a mano o:
  # for each KEY=VAL in patch: sed -i ...
  docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan mail:verify

"@
Write-Host "Patch local: $tmp"
Write-Host 'Completa MAIL_* en el VPS (.env) con los valores de .mail.local y reinicia api+queue.'
