# Aplica credenciales CT desde scripts/.wholesaler-ct.local al .env local o VPS.
# Uso local:  .\scripts\apply-ct-credentials.ps1
# Uso VPS:    .\scripts\apply-ct-credentials.ps1 -TargetVps

param(
    [switch]$TargetVps
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$localFile = Join-Path $Root 'scripts\.wholesaler-ct.local'

if (-not (Test-Path $localFile)) {
    Write-Host '[ERROR] Crea scripts/.wholesaler-ct.local desde scripts/.wholesaler-ct.local.example' -ForegroundColor Red
    exit 1
}

$vars = @{}
Get-Content $localFile | ForEach-Object {
    $line = $_.Trim()
    if ($line -eq '' -or $line.StartsWith('#')) { return }
    if ($line -match '^([A-Z0-9_]+)=(.*)$') {
        $vars[$Matches[1]] = $Matches[2].Trim()
    }
}

if (-not $vars['WHOLESALER_CT_API_KEY'] -and -not ($vars['WHOLESALER_CT_EMAIL'] -and $vars['WHOLESALER_CT_CLIENTE'] -and $vars['WHOLESALER_CT_RFC'])) {
    Write-Host '[ERROR] Completa WHOLESALER_CT_API_KEY o email+cliente+rfc en .wholesaler-ct.local' -ForegroundColor Red
    exit 1
}

function Set-EnvLine {
    param([string]$Path, [string]$Key, [string]$Value)
    $content = Get-Content $Path -Raw
    $pattern = "(?m)^$Key=.*$"
    $line = "$Key=$Value"
    if ($content -match $pattern) {
        $content = [regex]::Replace($content, $pattern, $line)
    } else {
        if (-not $content.EndsWith("`n")) { $content += "`n" }
        $content += "$line`n"
    }
    Set-Content -Path $Path -Value $content.TrimEnd() + "`n" -NoNewline
}

$defaults = @{
    WHOLESALER_DEMO_OFFERS = 'false'
    WHOLESALER_COMPARE_CODES = 'CT'
    WHOLESALER_CT_BASE_URL = 'http://connect.ctonline.mx:3001'
    WHOLESALER_CT_LOOKUP_PATH = '/existencia/promociones/{part_number}'
    WHOLESALER_CT_SOURCE_IP = ''
    WHOLESALER_CT_TIMEOUT = '20'
    WHOLESALER_CT_FTP_PATH = '/catalogo_xml/productos.json'
    WHOLESALER_CT_CATALOG_TTL = '15'
}

if ($TargetVps) {
    $deployCfg = Join-Path $Root 'scripts\.vps-deploy.local'
    if (-not (Test-Path $deployCfg)) {
        throw 'Falta scripts/.vps-deploy.local'
    }
    $cfg = @{ Host = 'root@2.25.78.222'; HostKey = ''; Password = ''; RemotePath = '/var/www/cotizacion' }
    Get-Content $deployCfg | ForEach-Object {
        if ($_ -match '^VPS_HOST=(.+)$') { $cfg.Host = $Matches[1].Trim() }
        if ($_ -match '^VPS_HOSTKEY=(.+)$') { $cfg.HostKey = $Matches[1].Trim() }
        if ($_ -match '^VPS_PASSWORD=(.+)$') { $cfg.Password = $Matches[1].Trim() }
        if ($_ -match '^VPS_REMOTE_PATH=(.+)$') { $cfg.RemotePath = $Matches[1].Trim() }
    }
    $plink = Join-Path $env:TEMP 'plink.exe'
    $remote = @"
cd $($cfg.RemotePath)
cp .env .env.bak-ct-`$(date +%Y%m%d-%H%M)
"@
    foreach ($k in $defaults.Keys) { $remote += "sed -i 's|^$k=.*|$k=$($defaults[$k])|' .env || echo '$k=$($defaults[$k])' >> .env`n" }
    foreach ($k in $vars.Keys) {
        $v = $vars[$k] -replace "'", "'\\''"
        $remote += "grep -q '^$k=' .env && sed -i 's|^$k=.*|$k=$v|' .env || echo '$k=$v' >> .env`n"
    }
    $remote += @"
docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d --no-deps --force-recreate api queue
sleep 12
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan config:clear
docker compose -f docker-compose.yml -f docker-compose.staging.yml exec -T api php artisan wholesalers:test-ct ACCBLC010
"@
    $remote = $remote -replace "`r`n", "`n" -replace "`r", "`n"
    $plinkArgs = @('-batch')
    if ($cfg.HostKey) { $plinkArgs += @('-hostkey', $cfg.HostKey) }
    $plinkArgs += @('-pw', $cfg.Password)
    $scriptFile = Join-Path $env:TEMP 'apply-ct-remote.sh'
    [System.IO.File]::WriteAllText($scriptFile, $remote)
    $pscp = Join-Path $env:TEMP 'pscp.exe'
    & $pscp @plinkArgs $scriptFile "$($cfg.Host):/tmp/apply-ct-remote.sh"
    if ($LASTEXITCODE -ne 0) { throw 'pscp fallo al subir script CT' }
    & $plink @plinkArgs $cfg.Host "sed -i 's/\r`$//' /tmp/apply-ct-remote.sh && bash /tmp/apply-ct-remote.sh"
    Write-Host '[OK] Credenciales CT aplicadas en VPS' -ForegroundColor Green
    exit 0
}

$envPath = Join-Path $Root '.env'
if (-not (Test-Path $envPath)) { $envPath = Join-Path $Root '.env.staging' }

foreach ($k in $defaults.Keys) { Set-EnvLine $envPath $k $defaults[$k] }
foreach ($k in $vars.Keys) { if ($vars[$k]) { Set-EnvLine $envPath $k $vars[$k] } }

Write-Host "[OK] Credenciales CT aplicadas en $envPath" -ForegroundColor Green
