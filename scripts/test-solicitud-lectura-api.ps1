# Prueba POST /api/solicitudes/lectura con fixtures PDF, Excel y Word.
# Generar fixtures antes: php scripts/generate-solicitud-fixtures.php

$base = "http://cotizacion.test/api/solicitudes/lectura"
$fixtures = Join-Path $PSScriptRoot "..\storage\fixtures\solicitudes"

foreach ($ext in @("xlsx", "docx", "pdf")) {
    $file = Join-Path $fixtures "solicitud-prueba.$ext"
    Write-Host "`n=== $ext ===" -ForegroundColor Cyan

    $json = curl.exe -s -X POST $base -H "Accept: application/json" `
        -F "archivo=@$file" -F "via=docling"

    try {
        $resp = $json | ConvertFrom-Json
        if ($resp.lineas_count) {
            Write-Host "OK request_id=$($resp.request_id) lineas=$($resp.lineas_count)"
            $resp.lineas | ForEach-Object {
                Write-Host "  $($_.quantity) x $($_.product) | $($_.partNumber) | $($_.brand)"
            }
        } else {
            Write-Host "ERROR: $($resp.message)" -ForegroundColor Red
        }
    } catch {
        Write-Host "ERROR parseando JSON: $json" -ForegroundColor Red
    }
}
