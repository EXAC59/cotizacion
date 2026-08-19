param(
    [Parameter(Mandatory = $true)]
    [string]$FilePath,

    [switch]$NoOcr,

    [string]$DoclingUrl = "http://127.0.0.1:5001"
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path -LiteralPath $FilePath)) {
    Write-Error "No existe el archivo: $FilePath"
}

$fileName = [System.IO.Path]::GetFileName($FilePath)
$mime = switch ([System.IO.Path]::GetExtension($FilePath).ToLower()) {
    ".pdf"  { "application/pdf" }
    ".xlsx" { "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" }
    ".docx" { "application/vnd.openxmlformats-officedocument.wordprocessingml.document" }
    default { "application/octet-stream" }
}

$doOcr = if ($NoOcr) { "false" } else { "true" }

Write-Host "Docling: $DoclingUrl"
Write-Host "Archivo: $FilePath"
Write-Host "OCR: $doOcr"
Write-Host "Enviando (puede tardar varios minutos la primera vez)..."

curl.exe -sS -X POST "$DoclingUrl/v1/convert/file" `
    -F "files=@$FilePath;type=$mime;filename=$fileName" `
    -F "to_formats=md" `
    -F "do_ocr=$doOcr" `
    -F "force_ocr=false" `
    -F "table_mode=accurate" `
    -F "ocr_lang=es" `
    -F "ocr_lang=en" `
    -F "abort_on_error=false"

Write-Host ""
