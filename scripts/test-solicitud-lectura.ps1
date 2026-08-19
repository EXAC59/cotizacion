param(
    [Parameter(Mandatory = $false)]
    [string]$FilePath = '',

    [ValidateSet('docling', 'n8n')]
    [string]$Via = 'docling',

    [string]$ApiUrl = 'http://cotizacion.test/api/solicitudes/lectura',

    [switch]$FullOutput
)

$ErrorActionPreference = 'Stop'

$ProjectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$EjemploDefault = Join-Path $ProjectRoot 'storage\app\private\lectura\ejemplo-prueba.pdf'

function Show-Usage {
    Write-Host ''
    Write-Host 'Uso:' -ForegroundColor Yellow
    Write-Host '  .\scripts\test-solicitud-lectura.ps1 -FilePath "C:\Users\TU_USUARIO\Downloads\solicitud.pdf"'
    Write-Host ''
    Write-Host 'Prueba automatica (recomendado):' -ForegroundColor Cyan
    Write-Host '  .\scripts\preparar-prueba-lectura.cmd'
    Write-Host '  (o doble clic en scripts\preparar-prueba-lectura.cmd)'
    Write-Host ''
}

function Test-Preflight {
    param([string]$HealthUrl = 'http://cotizacion.test/api/health')

    try {
        $r = Invoke-WebRequest -Uri $HealthUrl -UseBasicParsing -TimeoutSec 8
        if ($r.StatusCode -ne 200) {
            throw "HTTP $($r.StatusCode)"
        }
        $json = $r.Content | ConvertFrom-Json
        if (-not $json.services.docling.ok) {
            Write-Host 'AVISO: Docling no responde OK en /api/health' -ForegroundColor Yellow
            Write-Host '  Ejecuta: .\scripts\lectura-up.cmd' -ForegroundColor Yellow
            Write-Host '  Luego: php artisan docling:ping' -ForegroundColor Yellow
        }
    } catch {
        Write-Host ''
        Write-Host 'ERROR: No se puede abrir la API Laravel.' -ForegroundColor Red
        Write-Host "  URL: $HealthUrl"
        Write-Host "  Detalle: $($_.Exception.Message)"
        Write-Host ''
        Write-Host 'Comprueba:' -ForegroundColor Yellow
        Write-Host '  1. Laragon -> Start All'
        Write-Host '  2. Abre en el navegador: http://cotizacion.test/api/health'
        Write-Host '  3. Estas en la carpeta del proyecto (cd c:\laragon\www\cotizacion)'
        Write-Host ''
        exit 1
    }
}

if ($FilePath -eq '') {
    if (Test-Path -LiteralPath $EjemploDefault) {
        $FilePath = $EjemploDefault
        Write-Host "Usando PDF de prueba: $FilePath" -ForegroundColor Cyan
    } else {
        Write-Host 'ERROR: Indica -FilePath con tu PDF/Excel/Word.' -ForegroundColor Red
        Show-Usage
        exit 1
    }
}

$FilePath = $FilePath.Trim().Trim('"')
if ($FilePath -match 'tu-archivo|ruta\\a\\tu-archivo|ruta\\a\\ruta|TU_USUARIO|MI-ARCHIVO') {
    Write-Host ''
    Write-Host 'ERROR: Esa ruta es solo un EJEMPLO del manual, no un archivo real.' -ForegroundColor Red
    Show-Usage
    exit 1
}

if (-not [IO.Path]::IsPathRooted($FilePath)) {
    $FilePath = Join-Path $ProjectRoot $FilePath
}

if (-not (Test-Path -LiteralPath $FilePath)) {
    Write-Host "ERROR: No existe el archivo:" -ForegroundColor Red
    Write-Host "  $FilePath"
    Write-Host ''
    Write-Host 'En el Explorador: clic derecho en el archivo -> Copiar como ruta de acceso.'
    exit 1
}

if (-not (Get-Command curl.exe -ErrorAction SilentlyContinue)) {
    Write-Host 'ERROR: curl.exe no esta en el PATH (Windows 10+ lo trae por defecto).' -ForegroundColor Red
    exit 1
}

Set-Location $ProjectRoot
Test-Preflight

$clientId = ''
try {
    $clientsRes = Invoke-RestMethod -Uri 'http://cotizacion.test/api/clientes' -Method Get -TimeoutSec 10
    $firstClient = @($clientsRes.data)[0]
    if ($firstClient -and $firstClient.id) {
        $clientId = [string]$firstClient.id
        Write-Host "Cliente: $($firstClient.company) ($clientId)" -ForegroundColor DarkGray
    }
} catch {
    Write-Host 'AVISO: No se pudo obtener clientes de la API.' -ForegroundColor Yellow
}

if (-not $clientId) {
    Write-Host 'ERROR: Crea al menos un cliente en Clientes antes de probar lectura.' -ForegroundColor Red
    exit 1
}

Write-Host ''
Write-Host "POST $ApiUrl"
Write-Host "Via: $Via"
Write-Host "Archivo: $FilePath"
Write-Host 'Espera 30-120 s (Docling convierte el archivo)...' -ForegroundColor DarkGray
Write-Host ''

$mime = switch ([IO.Path]::GetExtension($FilePath).ToLower()) {
    '.pdf'  { 'application/pdf' }
    '.xlsx' { 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }
    '.xls'  { 'application/vnd.ms-excel' }
    '.docx' { 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' }
    '.doc'  { 'application/msword' }
    default { 'application/octet-stream' }
}

$tmpOut = Join-Path $env:TEMP "cotizacion-lectura-response.txt"
$tmpMeta = Join-Path $env:TEMP "cotizacion-lectura-meta.txt"

try {
    $curlArgs = @(
        '-sS', '-w', "`nHTTP_CODE:%{http_code}`n",
        '-o', $tmpOut,
        '-X', 'POST', $ApiUrl,
        '-F', "archivo=@$FilePath;type=$mime",
        '-F', "via=$Via",
        '-F', "client_id=$clientId"
    )
    & curl.exe @curlArgs 2>&1 | Tee-Object -FilePath $tmpMeta | Out-Null

    $meta = Get-Content $tmpMeta -Raw -ErrorAction SilentlyContinue
    $httpCode = 0
    if ($meta -match 'HTTP_CODE:(\d+)') {
        $httpCode = [int]$Matches[1]
    }

    $body = Get-Content $tmpOut -Raw -ErrorAction SilentlyContinue
    if (-not $body) {
        Write-Host 'ERROR: Respuesta vacia de la API.' -ForegroundColor Red
        exit 1
    }

    if ($body -match '<!DOCTYPE html>' -or $body -match 'Internal Server Error') {
        Write-Host "HTTP $httpCode - ERROR Laravel (HTML, no JSON)." -ForegroundColor Red
        Write-Host 'Revisa: storage\logs\laravel.log' -ForegroundColor Yellow
        exit 1
    }

    if ($httpCode -ge 400) {
        Write-Host "HTTP $httpCode" -ForegroundColor Red
        try {
            $err = $body | ConvertFrom-Json
            if ($err.message) { Write-Host $err.message -ForegroundColor Yellow }
            if ($err.webhook_url) { Write-Host "Webhook: $($err.webhook_url)" }
            if ($httpCode -eq 503 -or ($err.message -match 'webhook|Active|activa')) {
                Write-Host ''
                Write-Host 'Solucion: en n8n activa el workflow (interruptor verde) y Save.' -ForegroundColor Cyan
                Write-Host '  http://localhost:5678 -> Lectura cotizacion -> Active'
            }
        } catch {
            Write-Host ($body.Substring(0, [Math]::Min(800, $body.Length)))
        }
        exit 1
    }

    try {
        $data = $body | ConvertFrom-Json
    } catch {
        Write-Host "HTTP $httpCode - respuesta no es JSON valido." -ForegroundColor Red
        Write-Host ($body.Substring(0, [Math]::Min(500, $body.Length)))
        exit 1
    }

    if ($httpCode -eq 202) {
        Write-Host "HTTP $httpCode - Enviado a n8n (procesamiento en segundo plano)" -ForegroundColor Green
        Write-Host "Revisa en n8n: menu Executions. Log Laravel: Lectura recibida desde n8n"
        Write-Host $body
        exit 0
    }

    Write-Host "HTTP $httpCode - OK" -ForegroundColor Green
    Write-Host "Mensaje: $($data.message)"
    Write-Host "Modo:    $($data.modo)"
    Write-Host "Archivo: $($data.archivo)"
    if ($null -ne $data.do_ocr) { Write-Host "OCR:     $($data.do_ocr)" }

    $lineas = @($data.lineas)
    Write-Host "Lineas detectadas: $($lineas.Count)"
    if ($lineas.Count -gt 0) {
        $max = [Math]::Min(8, $lineas.Count)
        for ($i = 0; $i -lt $max; $i++) {
            $ln = $lineas[$i]
            $prod = [string]$ln.product
            if ($prod.Length -gt 70) { $prod = $prod.Substring(0, 70) + '...' }
            $sku = [string]$ln.partNumber
            if ($sku.Length -gt 40) { $sku = $sku.Substring(0, 40) + '...' }
            Write-Host ("  - qty={0} | {1} | sku={2}" -f $ln.quantity, $prod, $sku)
        }
        if ($lineas.Count -gt $max) {
            Write-Host "  ... y $($lineas.Count - $max) mas"
        }
    }

    $md = [string]$data.markdown
    if ($md.Length -gt 0) {
        $preview = $md.Substring(0, [Math]::Min(400, $md.Length)) -replace "`r`n", ' '
        Write-Host ''
        Write-Host "Markdown (primeros 400 caracteres):"
        Write-Host $preview
        if ($md.Length -gt 400) {
            Write-Host "... ($($md.Length) caracteres en total; usa -FullOutput para ver todo)"
        }
    }

    if ($FullOutput) {
        Write-Host ''
        Write-Host '--- JSON completo ---'
        Write-Host $body
    } else {
        $fullJson = Join-Path $env:TEMP 'cotizacion-lectura-response.json'
        Set-Content -Path $fullJson -Value $body -Encoding UTF8
        Write-Host ''
        Write-Host "JSON completo guardado en: $fullJson"
    }
} catch {
    Write-Host ''
    Write-Host 'ERROR de red o curl.' -ForegroundColor Red
    Write-Host $_.Exception.Message
    Write-Host ''
    Write-Host 'Comprueba Laragon Start All y Docling en http://localhost:5001/ui'
    exit 1
}

Write-Host ''
