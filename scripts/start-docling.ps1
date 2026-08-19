# Docling Serve local sin Docker (puerto 5001).
# Requiere: pip install "docling-serve[ui]"
# Uso: .\scripts\start-docling.ps1

$ErrorActionPreference = "Stop"

function Find-Python310 {
    foreach ($cmd in @("python", "py", "python3")) {
        $found = Get-Command $cmd -ErrorAction SilentlyContinue
        if (-not $found) { continue }
        try {
            $ver = & $cmd -c "import sys; print(f'{sys.version_info.major}.{sys.version_info.minor}')" 2>$null
            if ($ver -and ([version]$ver -ge [version]"3.10")) {
                return $cmd
            }
        } catch { }
    }
    return $null
}

$python = Find-Python310
if (-not $python) {
    Write-Host "ERROR: Necesitas Python 3.10+ en PATH." -ForegroundColor Red
    Write-Host "Instala desde https://www.python.org/downloads/ (marca Add to PATH)"
    Write-Host "Luego: pip install `"docling-serve[ui]`""
    exit 1
}

Write-Host "Docling -> http://localhost:5001/ui" -ForegroundColor Green
Write-Host "Python: $python" -ForegroundColor Gray
Write-Host "Primera conversion puede tardar (descarga modelos)." -ForegroundColor Gray
Write-Host "Ctrl+C para detener." -ForegroundColor Gray
Write-Host ""

& $python -m docling_serve run --host 127.0.0.1 --port 5001 2>$null
if ($LASTEXITCODE -ne 0) {
    $cli = Get-Command docling-serve -ErrorAction SilentlyContinue
    if ($cli) {
        docling-serve run --host 127.0.0.1 --port 5001
    } else {
        Write-Host "ERROR: pip install `"docling-serve[ui]`"" -ForegroundColor Red
        exit 1
    }
}
