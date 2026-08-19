# Configuracion automatica tras formatear PC (Laragon + Docker + n8n + Docling)
# Uso (PowerShell en la carpeta del proyecto):
#   powershell -ExecutionPolicy Bypass -File .\scripts\setup-post-format.ps1

$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $ProjectRoot

function Write-Step([string]$msg) {
    Write-Host ""
    Write-Host "=== $msg ===" -ForegroundColor Cyan
}

function Get-LaragonPhp {
    $phpDir = "C:\laragon\bin\php"
    if (-not (Test-Path $phpDir)) { return $null }
    $exe = Get-ChildItem $phpDir -Recurse -Filter "php.exe" -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($exe) { return $exe.FullName }
    return $null
}

function Get-DockerExe {
    $cmd = Get-Command docker -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $full = "C:\Program Files\Docker\Docker\resources\bin\docker.exe"
    if (Test-Path $full) { return $full }
    return $null
}

function Reset-PostgresLocalIfNeeded {
    $script = Join-Path $ProjectRoot "scripts\reset-postgres-local.ps1"
    if (-not (Test-Path $script)) { return }
    $php = Get-LaragonPhp
    if (-not $php) { return }
    & $php -r "try { new PDO('pgsql:host=127.0.0.1;port=5432;dbname=cotizacion', getenv('DB_USER') ?: 'postgres', getenv('DB_PASS') ?: 'dulce123'); exit(0); } catch (Throwable $e) { exit(1); }" 2>$null
    if ($LASTEXITCODE -eq 0) { return }
    Write-Host "[AVISO] PostgreSQL: password distinto al .env. Ejecuta como Admin:" -ForegroundColor Yellow
    Write-Host "        powershell -ExecutionPolicy Bypass -File .\scripts\reset-postgres-local.ps1" -ForegroundColor White
}

function Ensure-HostsEntry {
    $hostsPath = "$env:SystemRoot\System32\drivers\etc\hosts"
    $entry = "127.0.0.1 cotizacion.test"
    $content = Get-Content $hostsPath -Raw -ErrorAction SilentlyContinue
    if ($content -match "cotizacion\.test") {
        Write-Host "[OK] hosts ya tiene cotizacion.test" -ForegroundColor Green
        return
    }
    try {
        Add-Content -Path $hostsPath -Value "`n$entry" -ErrorAction Stop
        Write-Host "[OK] Agregado a hosts: $entry" -ForegroundColor Green
    } catch {
        Write-Host "[AVISO] No se pudo editar hosts (requiere Admin)." -ForegroundColor Yellow
        Write-Host "        Abre Bloc de notas como Administrador y agrega:" -ForegroundColor Yellow
        Write-Host "        $entry" -ForegroundColor White
        Write-Host "        Archivo: $hostsPath" -ForegroundColor Gray
    }
}

Write-Host ""
Write-Host "Cotizacion — setup post-formato" -ForegroundColor Green
Write-Host "Proyecto: $ProjectRoot" -ForegroundColor Gray

# 1. hosts
Write-Step "1/6 — Virtual host cotizacion.test"
Ensure-HostsEntry

# 2. Laravel
Write-Step "2/6 — Laravel (config + migraciones)"
$php = Get-LaragonPhp
if (-not $php) {
    Write-Host "[ERROR] No se encontro PHP en C:\laragon\bin\php" -ForegroundColor Red
    Write-Host "        Abre Laragon y asegurate de tener PHP 8.3 instalado." -ForegroundColor Yellow
    exit 1
}
Write-Host "PHP: $php" -ForegroundColor Gray

& $php artisan config:clear --no-interaction
& $php artisan migrate --force --no-interaction
if ($LASTEXITCODE -ne 0) {
    Write-Host "[ERROR] migrate fallo. Revisa DB_* en .env" -ForegroundColor Red
    exit 1
}

$domainExists = & $php artisan db:init-domain 2>&1
if ($LASTEXITCODE -ne 0 -and ($domainExists -notmatch "ya existen")) {
    Write-Host "[AVISO] db:init-domain: $domainExists" -ForegroundColor Yellow
} else {
    Write-Host "[OK] Base de datos lista" -ForegroundColor Green
}

# 3. Frontend build (SPA en /spa/)
Write-Step "3/6 — Frontend (npm run build)"
if (Test-Path ".\frontend\package.json") {
    Push-Location frontend
    if (-not (Test-Path "node_modules")) {
        npm install --no-fund --no-audit
    }
    npm run build
    if ($LASTEXITCODE -ne 0) {
        Write-Host "[AVISO] build del frontend fallo" -ForegroundColor Yellow
    } else {
        Write-Host "[OK] Frontend compilado en public/spa/" -ForegroundColor Green
    }
    Pop-Location
}

# 4. Docker lectura (Docling + n8n)
Write-Step "4/6 — Docker (Docling + n8n)"
$docker = Get-DockerExe
if (-not $docker) {
    Write-Host "[AVISO] Docker no instalado. Instala Docker Desktop." -ForegroundColor Yellow
} else {
    & $docker info *> $null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "[AVISO] Abre Docker Desktop y vuelve a ejecutar este script." -ForegroundColor Yellow
    } else {
        Write-Host "Descargando/levantando contenedores (primera vez tarda varios minutos)..." -ForegroundColor Gray
        & $docker compose -f docker-compose.lectura.yml up -d
        if ($LASTEXITCODE -eq 0) {
            Write-Host "[OK] Docling + n8n en Docker" -ForegroundColor Green
        } else {
            Write-Host "[AVISO] docker compose fallo" -ForegroundColor Yellow
        }
    }
}

# 5. Verificaciones
Write-Step "5/6 — Verificaciones"
& $php artisan docling:ping 2>&1
& $php artisan n8n:verify-lectura 2>&1

# 6. Resumen
Write-Step "6/6 — Listo"
Write-Host "URLs:" -ForegroundColor Green
Write-Host "  App (Laragon)  -> http://cotizacion.test/spa/"
Write-Host "  API health     -> http://cotizacion.test/api/health"
Write-Host "  Docling UI     -> http://localhost:5001/ui"
Write-Host "  n8n            -> http://localhost:5678"
Write-Host ""
Write-Host "n8n — importa el workflow:" -ForegroundColor Yellow
Write-Host "  docs/n8n/lectura-cotizacion.workflow.json"
Write-Host ""
Write-Host "Login demo: admin@cotizacion.test / admin123" -ForegroundColor Gray
Write-Host ""
