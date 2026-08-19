# Diagnostico de conexion SSH al VPS Hostinger
param(
    [string]$VpsHost = "root@2.25.78.222",
    [int]$Port = 22
)

Write-Host "=== Diagnostico SSH VPS ===" -ForegroundColor Cyan
Write-Host "Host: $VpsHost  Puerto: $Port"
Write-Host ""

$tcp = Test-NetConnection -ComputerName ($VpsHost -replace '^.*@','') -Port $Port -WarningAction SilentlyContinue
if ($tcp.TcpTestSucceeded) {
    Write-Host "[OK] Puerto $Port abierto" -ForegroundColor Green
} else {
    Write-Host "[FALLO] No hay conexion al puerto $Port" -ForegroundColor Red
    Write-Host "       Revisa firewall Hostinger y que la VPS este encendida."
}

Write-Host ""
Write-Host "Probando SSH (modo batch, sin password)..." -ForegroundColor Gray
$out = ssh -p $Port -o BatchMode=yes -o ConnectTimeout=12 -o StrictHostKeyChecking=accept-new $VpsHost "echo CONECTADO" 2>&1
if ($LASTEXITCODE -eq 0) {
    Write-Host "[OK] SSH con llave/config existente" -ForegroundColor Green
    Write-Host $out
    exit 0
}

Write-Host "[INFO] Sin llave SSH valida (normal si solo usas password):" -ForegroundColor Yellow
Write-Host $out
Write-Host ""
Write-Host "Que revisar en Hostinger (hPanel):" -ForegroundColor Cyan
Write-Host "  1. VPS -> Overview -> SSH access (usuario y password root)"
Write-Host "  2. Security -> Firewall: permitir TCP 22"
Write-Host "  3. Si falla password: VPS -> SSH keys -> pegar tu clave publica"
Write-Host ""
Write-Host "Probar login manual (te pedira password UNA vez):" -ForegroundColor Cyan
Write-Host "  ssh -p $Port $VpsHost"
Write-Host ""
Write-Host "Subir archivos (un solo paquete, menos fallos):" -ForegroundColor Cyan
Write-Host "  .\scripts\upload-vps-bundle.ps1"
