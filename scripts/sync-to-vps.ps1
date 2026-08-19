# Sincroniza cambios locales al VPS Hostinger (exacto.mx) y aplica reinicios necesarios.
# Uso: .\scripts\sync-to-vps.ps1
#      .\scripts\sync-to-vps.ps1 -SkipFrontendBuild
#
# Credenciales: scripts/.vps-deploy.local (ver .vps-deploy.local.example)

param(
    [switch]$SkipFrontendBuild,
    [switch]$SkipApiRestart,
    [string]$VpsHost = '',
    [string]$RemotePath = ''
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root

function Read-DeployConfig {
    $cfg = @{
        Host = 'root@2.25.78.222'
        HostKey = 'SHA256:qZNmXyZ4qusFC91S6wEX34gSNZquy8DdBUhwVF8qDtM'
        Password = ''
        RemotePath = '/var/www/cotizacion'
        SshKey = ''
    }

    $localFile = Join-Path $Root 'scripts\.vps-deploy.local'
    if (Test-Path $localFile) {
        Get-Content $localFile | ForEach-Object {
            $line = $_.Trim()
            if ($line -eq '' -or $line.StartsWith('#')) { return }
            if ($line -match '^VPS_HOST=(.+)$') { $cfg.Host = $Matches[1].Trim() }
            if ($line -match '^VPS_HOSTKEY=(.+)$') { $cfg.HostKey = $Matches[1].Trim() }
            if ($line -match '^VPS_PASSWORD=(.+)$') { $cfg.Password = $Matches[1].Trim() }
            if ($line -match '^VPS_REMOTE_PATH=(.+)$') { $cfg.RemotePath = $Matches[1].Trim() }
            if ($line -match '^VPS_SSH_KEY=(.+)$') { $cfg.SshKey = [Environment]::ExpandEnvironmentVariables($Matches[1].Trim()) }
        }
    }

    if ($VpsHost) { $cfg.Host = $VpsHost }
    if ($RemotePath) { $cfg.RemotePath = $RemotePath }

    return $cfg
}

function Ensure-PlinkTools {
    $plink = Join-Path $env:TEMP 'plink.exe'
    $pscp = Join-Path $env:TEMP 'pscp.exe'
    $base = 'https://the.earth.li/~sgtatham/putty/latest/w64'

    if (-not (Test-Path $plink)) {
        Invoke-WebRequest -Uri "$base/plink.exe" -OutFile $plink -UseBasicParsing
    }
    if (-not (Test-Path $pscp)) {
        Invoke-WebRequest -Uri "$base/pscp.exe" -OutFile $pscp -UseBasicParsing
    }

    return @{ Plink = $plink; Pscp = $pscp }
}

function Get-ChangedPaths {
    if (-not (Test-Path (Join-Path $Root '.git'))) {
        return @()
    }

    $output = git -C $Root status --porcelain 2>$null
    if (-not $output) { return @() }

    $paths = @()
    foreach ($line in $output) {
        if ($line.Length -lt 4) { continue }
        $path = $line.Substring(3).Trim()
        if ($path -match ' -> ') {
            $path = ($path -split ' -> ')[-1].Trim()
        }
        $paths += $path -replace '\\', '/'
    }

    return $paths | Select-Object -Unique
}

function Test-NeedsFrontendBuild([string[]]$paths) {
    if ($paths.Count -eq 0) { return $true }
    return $paths | Where-Object { $_ -like 'frontend/*' -or $_ -like 'frontend\*' } | Select-Object -First 1
}

function Test-NeedsApiRestart([string[]]$paths) {
    if ($paths.Count -eq 0) { return $true }
    $patterns = @('app/', 'app\', 'routes/', 'routes\', 'config/', 'config\', 'database/', 'database\', 'bootstrap/', 'bootstrap\', 'resources/', 'resources\', 'public/spa/', 'public\spa\')
    foreach ($p in $paths) {
        foreach ($pat in $patterns) {
            if ($p -like "$pat*") { return $true }
        }
    }
    return $false
}

$cfg = Read-DeployConfig
$tools = Ensure-PlinkTools
$bundle = Join-Path $env:TEMP 'cotizacion-sync-vps.tar.gz'
$manifest = Join-Path $env:TEMP 'cotizacion-sync-manifest.txt'
if (Test-Path $bundle) { Remove-Item $bundle -Force }
if (Test-Path $manifest) { Remove-Item $manifest -Force }

$changed = @(Get-ChangedPaths)
$needsFrontend = (-not $SkipFrontendBuild) -and (Test-NeedsFrontendBuild $changed)
$needsApi = (-not $SkipApiRestart) -and (Test-NeedsApiRestart $changed)

@(
    "needs_frontend=$([int]$needsFrontend)"
    "needs_api=$([int]$needsApi)"
    'changed_files:'
) + $changed | Set-Content -Path $manifest -Encoding ascii

Write-Host ">> Empaquetando proyecto (excluye vendor, node_modules)..." -ForegroundColor Cyan

$tarArgs = @(
    '-czf', $bundle,
    '--exclude=./vendor',
    '--exclude=./node_modules',
    '--exclude=./frontend/node_modules',
    '--exclude=./.env',
    '--exclude=./.env.*',
    '--exclude=./storage/logs',
    '--exclude=./storage/framework/cache',
    '--exclude=./storage/framework/sessions',
    '--exclude=./storage/framework/views',
    '--exclude=./public/hot',
    '--exclude=./scripts/.vps-deploy.local',
    '.'
)

& tar @tarArgs
if ($LASTEXITCODE -ne 0) {
    throw 'tar fallo al crear el paquete de sincronizacion'
}

$sizeMb = [math]::Round((Get-Item $bundle).Length / 1MB, 2)
Write-Host "   Paquete: $sizeMb MB | frontend build: $needsFrontend | api restart: $needsApi"

$remoteBundle = '/tmp/cotizacion-sync-vps.tar.gz'
$remoteManifest = '/tmp/cotizacion-sync-manifest.txt'

$plinkArgs = @('-batch')
if ($cfg.HostKey) { $plinkArgs += @('-hostkey', $cfg.HostKey) }
if ($cfg.SshKey -and (Test-Path $cfg.SshKey)) {
    $plinkArgs += @('-i', $cfg.SshKey)
} elseif ($cfg.Password) {
    $plinkArgs += @('-pw', $cfg.Password)
} else {
    throw 'Falta VPS_PASSWORD o VPS_SSH_KEY en scripts/.vps-deploy.local'
}

$pscpArgs = $plinkArgs.Clone()

$sshTarget = $cfg.Host

Write-Host ">> Subiendo al VPS $sshTarget..." -ForegroundColor Cyan
& $tools.Pscp @pscpArgs $bundle "${sshTarget}:${remoteBundle}"
if ($LASTEXITCODE -ne 0) { throw 'pscp fallo al subir el paquete' }
& $tools.Pscp @pscpArgs $manifest "${sshTarget}:${remoteManifest}"
if ($LASTEXITCODE -ne 0) { throw 'pscp fallo al subir el manifiesto' }

$remotePath = $cfg.RemotePath
$remoteScript = @"
set -e
cd $remotePath
tar -xzf $remoteBundle --warning=no-timestamp 2>/dev/null || tar -xzf $remoteBundle
rm -f $remoteBundle
needs_frontend=0
needs_api=0
if [ -f $remoteManifest ]; then
  needs_frontend=`$(grep -E '^needs_frontend=1' $remoteManifest | wc -l)
  needs_api=`$(grep -E '^needs_api=1' $remoteManifest | wc -l)
  rm -f $remoteManifest
fi
DC='docker compose -f docker-compose.yml -f docker-compose.staging.yml'
if [ "`$needs_api" -gt 0 ]; then
  echo '>> Reiniciando API/colas...'
  `$DC restart api queue scheduler || `$DC restart api
  # Refresca DNS de upstream FastCGI tras recreate/restart de api
  `$DC restart nginx || true
fi
if [ "`$needs_frontend" -gt 0 ]; then
  echo '>> Build frontend (sin cache Docker)...'
  `$DC build --no-cache frontend
  `$DC up -d --force-recreate frontend
fi
echo SYNC_OK
"@ -replace "`r", ''

Write-Host ">> Aplicando en servidor..." -ForegroundColor Cyan
$prevEap = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$result = @($remoteScript | & $tools.Plink @plinkArgs $sshTarget 'bash -s' 2>&1 | ForEach-Object { "$_" })
$ErrorActionPreference = $prevEap
Write-Host ($result -join "`n")
if (($result -join "`n") -notmatch 'SYNC_OK') {
    throw 'La sincronizacion en el VPS no termino correctamente'
}

Remove-Item $bundle -Force -ErrorAction SilentlyContinue
Remove-Item $manifest -Force -ErrorAction SilentlyContinue

Write-Host ""
Write-Host "[OK] VPS actualizado: https://cotizaciones.exacto.mx/spa/ (Ctrl+F5)" -ForegroundColor Green
