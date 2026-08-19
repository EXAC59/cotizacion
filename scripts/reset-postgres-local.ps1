# Restablece la contraseña de PostgreSQL local y crea la BD cotizacion.
# Requiere ejecutar como Administrador (clic derecho -> Ejecutar como administrador).
# Uso: powershell -ExecutionPolicy Bypass -File .\scripts\reset-postgres-local.ps1

$ErrorActionPreference = "Stop"

$pgData = "C:\Program Files\PostgreSQL\18\data"
$pgHba = Join-Path $pgData "pg_hba.conf"
$pgBin = "C:\Program Files\PostgreSQL\18\bin"
$service = "postgresql-x64-18"
$newPassword = "dulce123"

if (-not (Test-Path $pgHba)) {
    Write-Host "[ERROR] No se encontro PostgreSQL 18 en $pgData" -ForegroundColor Red
    exit 1
}

$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) {
    Write-Host "[ERROR] Ejecuta este script como Administrador." -ForegroundColor Red
    exit 1
}

Write-Host "=== PostgreSQL: restablecer password y crear BD ===" -ForegroundColor Cyan

$backup = "$pgHba.bak-cotizacion"
if (-not (Test-Path $backup)) {
    Copy-Item $pgHba $backup
}

$content = Get-Content $pgHba -Raw
$trust = $content -replace 'host\s+all\s+all\s+127\.0\.0\.1/32\s+scram-sha-256', 'host all all 127.0.0.1/32 trust'
Set-Content -Path $pgHba -Value $trust -NoNewline

& "$pgBin\pg_ctl.exe" reload -D $pgData | Out-Null
Start-Sleep -Seconds 2

$env:PGPASSWORD = ""
& "$pgBin\psql.exe" -U postgres -h 127.0.0.1 -d postgres -v ON_ERROR_STOP=1 -c "ALTER USER postgres WITH PASSWORD '$newPassword';"
& "$pgBin\psql.exe" -U postgres -h 127.0.0.1 -d postgres -v ON_ERROR_STOP=1 -tc "SELECT 1 FROM pg_database WHERE datname = 'cotizacion'" | ForEach-Object {
    if ($_.Trim() -ne "1") {
        & "$pgBin\psql.exe" -U postgres -h 127.0.0.1 -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE cotizacion;"
    }
}

Copy-Item $backup $pgHba -Force
& "$pgBin\pg_ctl.exe" reload -D $pgData | Out-Null

Write-Host "[OK] Usuario postgres -> password: $newPassword" -ForegroundColor Green
Write-Host "[OK] Base de datos: cotizacion" -ForegroundColor Green
