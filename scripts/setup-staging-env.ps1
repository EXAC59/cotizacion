# Genera .env.staging desde la plantilla con dominio editable (Windows / Laragon)
param(
    [string]$Domain = "cotizacion.tudominio.com",
    [switch]$UseHttp
)

$root = Split-Path -Parent $PSScriptRoot
$template = Join-Path $root ".env.staging.example"
$target = Join-Path $root ".env.staging"

if (-not (Test-Path $template)) {
    Write-Error "No se encontró .env.staging.example"
    exit 1
}

$content = Get-Content $template -Raw
$content = $content -replace 'STAGING_DOMAIN=cotizacion\.tudominio\.com', "STAGING_DOMAIN=$Domain"

if ($UseHttp) {
    $content = $content -replace 'APP_URL=https://\$\{STAGING_DOMAIN\}', 'APP_URL=http://${STAGING_DOMAIN}'
    $content = $content -replace 'FRONTEND_URL=https://\$\{STAGING_DOMAIN\}', 'FRONTEND_URL=http://${STAGING_DOMAIN}'
    $content = $content -replace 'SESSION_SECURE_COOKIE=true', 'SESSION_SECURE_COOKIE=false'
    $content = $content -replace 'SESSION_ENCRYPT=true', 'SESSION_ENCRYPT=false'
}

Set-Content -Path $target -Value $content -NoNewline
Write-Host "Generado: $target"
Write-Host "Dominio: $Domain"
Write-Host "Siguiente: copia a .env en el servidor o usa: cp .env.staging .env"
