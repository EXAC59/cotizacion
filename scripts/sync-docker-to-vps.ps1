# Sincroniza archivos Docker/staging al VPS (desde Windows con OpenSSH/scp).
# Uso: .\scripts\sync-docker-to-vps.ps1
#      .\scripts\sync-docker-to-vps.ps1 -VpsHost root@2.25.78.222 -RemotePath /var/www/cotizacion

param(
    [string]$VpsHost = "root@2.25.78.222",
    [string]$RemotePath = "/var/www/cotizacion"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

$files = @(
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
    "scripts/vps-phase2-integrations-https.sh"
)

Write-Host ">> Sincronizando $($files.Count) archivos a ${VpsHost}:${RemotePath}" -ForegroundColor Cyan
Write-Host "   (Recomendado si falla: .\scripts\upload-vps-bundle.ps1 — un solo archivo)" -ForegroundColor Gray
Write-Host ""

# Probar que SSH responde antes de 18 intentos de password
ssh -o BatchMode=yes -o ConnectTimeout=12 -o StrictHostKeyChecking=accept-new $VpsHost "echo ok" 2>$null
if ($LASTEXITCODE -ne 0) {
    Write-Host "[AVISO] Sin llave SSH. Te pedira password en CADA archivo (~$($files.Count) veces)." -ForegroundColor Yellow
    Write-Host "        Mejor usa: .\scripts\upload-vps-bundle.ps1" -ForegroundColor Yellow
    Write-Host "        O configura llave: .\scripts\setup-ssh-key-vps.ps1" -ForegroundColor Yellow
    Write-Host ""
    $cont = Read-Host "Continuar con sync archivo por archivo? (s/N)"
    if ($cont -notmatch '^[sS]') {
        Write-Host "Cancelado. Ejecuta: .\scripts\upload-vps-bundle.ps1"
        exit 0
    }
}
$dirs = $files | ForEach-Object { ($_ -replace '\\', '/') -replace '/[^/]+$', '' } | Where-Object { $_ -ne '' } | Sort-Object -Unique
foreach ($d in $dirs) {
    ssh -o StrictHostKeyChecking=accept-new $VpsHost "mkdir -p ${RemotePath}/${d}"
}

foreach ($rel in $files) {
    $local = Join-Path $Root $rel
    if (-not (Test-Path $local)) {
        Write-Warning "No existe: $rel"
        continue
    }
    scp -o StrictHostKeyChecking=accept-new $local "${VpsHost}:${RemotePath}/$($rel -replace '\\', '/')"
    Write-Host "   OK $rel"
}

Write-Host ""
Write-Host ">> En el VPS ejecutá:" -ForegroundColor Green
Write-Host "   cd $RemotePath"
Write-Host "   dos2unix scripts/*.sh docker/entrypoint.sh 2>/dev/null || true"
Write-Host "   DOMAIN=exacto.mx CERTBOT_EMAIL=tu@exacto.mx bash scripts/vps-phase2-integrations-https.sh"
