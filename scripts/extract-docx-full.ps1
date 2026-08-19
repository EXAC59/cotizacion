param([string]$DocxPath)

Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead($DocxPath)

function Get-XmlText([string]$xmlContent) {
    [xml]$doc = $xmlContent
    $ns = New-Object System.Xml.XmlNamespaceManager($doc.NameTable)
    $ns.AddNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main')
    $paragraphs = @()
    foreach ($p in $doc.SelectNodes('//w:p', $ns)) {
        $parts = $p.SelectNodes('.//w:t', $ns) | ForEach-Object { $_.InnerText }
        if ($parts) { $paragraphs += ($parts -join '') }
    }
    return $paragraphs
}

$allParts = @()
foreach ($name in @('word/document.xml', 'word/header1.xml', 'word/header2.xml', 'word/footer1.xml', 'word/footnotes.xml')) {
    $e = $zip.Entries | Where-Object { $_.FullName -eq $name }
    if ($e) {
        $sr = New-Object System.IO.StreamReader($e.Open())
        $xml = $sr.ReadToEnd()
        $sr.Close()
        $allParts += "=== $name ==="
        $allParts += (Get-XmlText $xml)
    }
}

$zip.Dispose()
$allParts -join "`n"
