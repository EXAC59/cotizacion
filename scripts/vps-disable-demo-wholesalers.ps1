# Desactiva mayoristas demo en VPS (solo CT).
# Uso: .\scripts\vps-disable-demo-wholesalers.ps1

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$deployCfg = Join-Path $Root 'scripts\.vps-deploy.local'
if (-not (Test-Path $deployCfg)) { throw 'Falta scripts/.vps-deploy.local' }

$cfg = @{ Host = 'root@2.25.78.222'; HostKey = ''; Password = ''; RemotePath = '/var/www/cotizacion' }
Get-Content $deployCfg | ForEach-Object {
    if ($_ -match '^VPS_HOST=(.+)$') { $cfg.Host = $Matches[1].Trim() }
    if ($_ -match '^VPS_HOSTKEY=(.+)$') { $cfg.HostKey = $Matches[1].Trim() }
    if ($_ -match '^VPS_PASSWORD=(.+)$') { $cfg.Password = $Matches[1].Trim() }
    if ($_ -match '^VPS_REMOTE_PATH=(.+)$') { $cfg.RemotePath = $Matches[1].Trim() }
}

$plink = Join-Path $env:TEMP 'plink.exe'
$pscp = Join-Path $env:TEMP 'pscp.exe'
$localSh = Join-Path $Root 'scripts\vps-disable-demo-wholesalers.sh'
$remoteSh = '/tmp/vps-disable-demo-wholesalers.sh'

$plinkArgs = @('-batch')
if ($cfg.HostKey) { $plinkArgs += @('-hostkey', $cfg.HostKey) }
$plinkArgs += @('-pw', $cfg.Password)

& $pscp @plinkArgs $localSh "$($cfg.Host):$remoteSh"
if ($LASTEXITCODE -ne 0) { throw 'pscp fallo' }
& $plink @plinkArgs $cfg.Host "sed -i 's/\r`$//' $remoteSh && bash $remoteSh"
if ($LASTEXITCODE -ne 0) { throw 'script remoto fallo' }
Write-Host '[OK] Demo/Ingram/Exel desactivados en VPS (solo CT)' -ForegroundColor Green
