# Convierte Excel .xls a .xlsx (Docling no acepta .xls).
param(
    [Parameter(Mandatory = $true)]
    [string]$InputPath,
    [string]$OutputPath = ""
)

function Resolve-FullPath([string]$Path) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) {
        $Path = Join-Path (Get-Location).Path $Path
    }
    return [System.IO.Path]::GetFullPath($Path)
}

$InputPath = Resolve-FullPath $InputPath

if (-not (Test-Path -LiteralPath $InputPath)) {
    Write-Error "No existe: $InputPath"
    exit 1
}

if ($OutputPath -eq "") {
    $OutputPath = [System.IO.Path]::ChangeExtension($InputPath, ".xlsx")
} else {
    $OutputPath = Resolve-FullPath $OutputPath
}

$excel = New-Object -ComObject Excel.Application
$excel.Visible = $false
$excel.DisplayAlerts = $false

try {
    $wb = $excel.Workbooks.Open($InputPath)
    $wb.SaveAs($OutputPath, 51)
    $wb.Close($false)
    Write-Host "OK: $OutputPath"
} catch {
    Write-Error $_.Exception.Message
    exit 1
} finally {
    $excel.Quit()
    [System.Runtime.InteropServices.Marshal]::ReleaseComObject($excel) | Out-Null
}
