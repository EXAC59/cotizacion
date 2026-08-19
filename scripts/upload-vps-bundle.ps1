# Sube UN solo archivo .tar.gz al VPS (1 password en scp + 1 en ssh).
# Uso: .\scripts\upload-vps-bundle.ps1
#      .\scripts\upload-vps-bundle.ps1 -VpsHost root@2.25.78.222 -Port 22

param(
    [string]$VpsHost = "root@2.25.78.222",
    [string]$RemotePath = "/var/www/cotizacion",
    [int]$Port = 22
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root

$bundleItems = @(
    "docker-compose.yml",
    "docker-compose.staging.yml",
    "docker/php/Dockerfile",
    "docker/php/php.ini",
    "docker/entrypoint.sh",
    "docker/nginx/default.conf",
    "docker/nginx/staging-host.conf.example",
    "docker/postgres/init.sql",
    "frontend/Dockerfile",
    "frontend/.dockerignore",
    "frontend/docker/nginx/frontend.conf",
    "Makefile",
    ".env.staging",
    "docs/n8n/lectura-cotizacion.workflow.json",
    "docs/n8n/comparador-precios.workflow.json",
    "scripts/deploy-staging.sh",
    "scripts/vps-fix-deploy.sh",
    "scripts/vps-phase2-integrations-https.sh",
    "scripts/upload-vps-bundle.ps1",
    "scripts/vps-ssh-check.ps1"
)

$missing = $bundleItems | Where-Object { -not (Test-Path (Join-Path $Root $_)) }
if ($missing) {
    Write-Warning "Faltan archivos locales: $($missing -join ', ')"
}

$bundle = Join-Path $env:TEMP "cotizacion-vps-deploy.tar.gz"
if (Test-Path $bundle) { Remove-Item $bundle -Force }

Write-Host ">> Creando paquete ($($bundleItems.Count) archivos)..." -ForegroundColor Cyan
$tarArgs = @("-czf", $bundle) + $bundleItems
& tar @tarArgs
if ($LASTEXITCODE -ne 0) {
    Write-Error "tar fallo al crear el paquete"
}

$sizeMb = [math]::Round((Get-Item $bundle).Length / 1MB, 2)
Write-Host "   Paquete: $bundle ($sizeMb MB)"
Write-Host ""
Write-Host ">> Subiendo al VPS (pedira password root UNA vez)..." -ForegroundColor Yellow
Write-Host "   Si no aparece el prompt, abre PowerShell NORMAL (no minimizado) y vuelve a ejecutar."
Write-Host ""

$remoteBundle = "/tmp/cotizacion-vps-deploy.tar.gz"
scp -P $Port -o StrictHostKeyChecking=accept-new $bundle "${VpsHost}:${remoteBundle}"
if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "[FALLO] scp no pudo subir el archivo." -ForegroundColor Red
    Write-Host "Ejecuta primero: .\scripts\vps-ssh-check.ps1"
    Write-Host "O prueba login manual: ssh -p $Port $VpsHost"
    exit 1
}

Write-Host ""
Write-Host ">> Extrayendo en el servidor (password otra vez si no hay llave)..." -ForegroundColor Yellow
$remoteCmd = "mkdir -p ${RemotePath} && cd ${RemotePath} && tar -xzf ${remoteBundle} && rm -f ${remoteBundle} && echo EXTRACCION_OK"
ssh -p $Port -o StrictHostKeyChecking=accept-new $VpsHost $remoteCmd
if ($LASTEXITCODE -ne 0) {
    Write-Host "[FALLO] No se pudo extraer en el VPS." -ForegroundColor Red
    exit 1
}

Remove-Item $bundle -Force -ErrorAction SilentlyContinue

Write-Host ""
Write-Host "[OK] Archivos en ${RemotePath}" -ForegroundColor Green
Write-Host ""
Write-Host "Siguiente en el VPS:" -ForegroundColor Cyan
Write-Host "  ssh -p $Port $VpsHost"
Write-Host "  cd $RemotePath"
Write-Host "  cp .env.staging .env && nano .env"
Write-Host "  DOMAIN=exacto.mx CERTBOT_EMAIL=tu@exacto.mx bash scripts/vps-phase2-integrations-https.sh"
