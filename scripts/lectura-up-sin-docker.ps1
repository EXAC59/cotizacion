# Docling + n8n SIN Docker (Laragon / Windows local).
# Uso: abre DOS ventanas de PowerShell y ejecuta en cada una:
#   Ventana 1: .\scripts\start-docling.ps1
#   Ventana 2: .\scripts\start-n8n.ps1
#
# Requisitos:
#   - Node.js (ya lo tienes si corre npm)
#   - Python 3.10+ con: pip install "docling-serve[ui]"

$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

Write-Host ""
Write-Host "=== Lectura sin Docker ===" -ForegroundColor Cyan
Write-Host ""

$node = Get-Command node -ErrorAction SilentlyContinue
if (-not $node) {
    Write-Host "Falta Node.js. Instala desde https://nodejs.org/" -ForegroundColor Red
    exit 1
}

$python = $null
foreach ($cmd in @("python", "py", "python3")) {
    $found = Get-Command $cmd -ErrorAction SilentlyContinue
    if ($found) {
        try {
            $ver = & $cmd -c "import sys; print(f'{sys.version_info.major}.{sys.version_info.minor}')" 2>$null
            if ($ver -and ([version]$ver -ge [version]"3.10")) {
                $python = $cmd
                break
            }
        } catch { }
    }
}

if (-not $python) {
    Write-Host "Falta Python 3.10+ (Docling)." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "1. Instala Python: https://www.python.org/downloads/"
    Write-Host "   Marca 'Add python.exe to PATH' en el instalador."
    Write-Host "2. En PowerShell nuevo:"
    Write-Host "   pip install `"docling-serve[ui]`""
    Write-Host "3. Ventana 1: .\scripts\start-docling.ps1"
    Write-Host "4. Ventana 2: .\scripts\start-n8n.ps1"
    Write-Host ""
    Write-Host "n8n si puedes iniciarlo ya (solo Node):" -ForegroundColor Green
    Write-Host "   .\scripts\start-n8n.ps1"
    Write-Host ""
    exit 1
}

Write-Host "Python: $python | Node: $(node -v)" -ForegroundColor Gray
Write-Host ""
Write-Host "Abre DOS terminales en: $ProjectRoot" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Terminal 1:  .\scripts\start-docling.ps1"
Write-Host "  Terminal 2:  .\scripts\start-n8n.ps1"
Write-Host ""
Write-Host "URLs:" -ForegroundColor Green
Write-Host "  http://localhost:5001/ui   (Docling)"
Write-Host "  http://localhost:5678      (n8n)"
Write-Host ""
