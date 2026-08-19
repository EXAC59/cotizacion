param([string]$DocxPath)

Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead($DocxPath)
$entry = $zip.Entries | Where-Object { $_.FullName -eq 'word/document.xml' }
$sr = New-Object System.IO.StreamReader($entry.Open())
$xml = $sr.ReadToEnd()
$sr.Close()
$zip.Dispose()

[xml]$doc = $xml
$ns = New-Object System.Xml.XmlNamespaceManager($doc.NameTable)
$ns.AddNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main')

$nodes = $doc.SelectNodes('//w:t', $ns)
$text = ($nodes | ForEach-Object { $_.InnerText }) -join ''
$text = $text -replace '(\r?\n)+', "`n"

# Also extract paragraphs with line breaks from w:p
$paragraphs = @()
foreach ($p in $doc.SelectNodes('//w:p', $ns)) {
    $parts = $p.SelectNodes('.//w:t', $ns) | ForEach-Object { $_.InnerText }
    if ($parts) {
        $paragraphs += ($parts -join '')
    }
}

$out = ($paragraphs -join "`n")
$out | Out-File -FilePath (Join-Path $PSScriptRoot 'docx_content.txt') -Encoding utf8
Write-Output $out
