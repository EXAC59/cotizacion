# Genera llave SSH para el VPS y muestra como registrarla en Hostinger.
param(
    [string]$KeyPath = "$env:USERPROFILE\.ssh\id_ed25519_exacto_vps"
)

$sshDir = Split-Path $KeyPath
if (-not (Test-Path $sshDir)) {
    New-Item -ItemType Directory -Path $sshDir -Force | Out-Null
}

if (-not (Test-Path $KeyPath)) {
    Write-Host ">> Generando llave en $KeyPath ..." -ForegroundColor Cyan
    ssh-keygen -t ed25519 -f $KeyPath -N '""' -C "cotizacion-vps-exacto.mx"
} else {
    Write-Host ">> Ya existe: $KeyPath" -ForegroundColor Yellow
}

$pub = Get-Content "$KeyPath.pub" -Raw
Write-Host ""
Write-Host "=== Copia esta clave PUBLICA en Hostinger ===" -ForegroundColor Green
Write-Host "hPanel -> VPS -> Settings -> SSH keys -> Add SSH key"
Write-Host ""
Write-Host $pub.Trim()
Write-Host ""
Write-Host "=== Probar conexion ===" -ForegroundColor Cyan
Write-Host "ssh -i `"$KeyPath`" root@2.25.78.222"
Write-Host ""
Write-Host "=== Subir archivos sin pedir password cada vez ===" -ForegroundColor Cyan
Write-Host ".\scripts\upload-vps-bundle.ps1 -VpsHost root@2.25.78.222"
