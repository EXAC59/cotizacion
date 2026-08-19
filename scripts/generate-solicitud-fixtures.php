<?php

/**
 * Genera PDF, Excel y Word de prueba para validar Docling + parser.
 * Uso: php scripts/generate-solicitud-fixtures.php
 */

$dir = dirname(__DIR__).'/storage/fixtures/solicitudes';
if (! is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$rows = [
    ['qty' => 5, 'product' => 'Switch Cisco C9200L-24T-4G-E', 'sku' => 'C9200L-24T-4G-E', 'brand' => 'Cisco'],
    ['qty' => 10, 'product' => 'Memoria Kingston 16GB DDR4', 'sku' => 'KVR16N11S8/16', 'brand' => 'Kingston'],
    ['qty' => 3, 'product' => 'Access Point Ubiquiti U6+', 'sku' => 'U6-PLUS', 'brand' => 'Ubiquiti'],
];

$xlsxPath = "{$dir}/solicitud-prueba.xlsx";
$docxPath = "{$dir}/solicitud-prueba.docx";
$pdfPath = "{$dir}/solicitud-prueba.pdf";

writeXlsx($xlsxPath, $rows);
writeDocx($docxPath, $rows);
writePdf($pdfPath, $rows);

echo "Fixtures generados en {$dir}\n";
foreach ([$xlsxPath, $docxPath, $pdfPath] as $path) {
    echo ' - '.basename($path).' ('.filesize($path)." bytes)\n";
}

/**
 * @param  list<array{qty:int,product:string,sku:string,brand:string}>  $rows
 */
function writeXlsx(string $path, array $rows): void
{
    $shared = ['Cantidad', 'Producto', 'No. Parte', 'Marca'];
    foreach ($rows as $row) {
        $shared[] = (string) $row['qty'];
        $shared[] = $row['product'];
        $shared[] = $row['sku'];
        $shared[] = $row['brand'];
    }

    $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($shared).'" uniqueCount="'.count($shared).'">';
    foreach ($shared as $i => $text) {
        $sharedXml .= '<si><t>'.xml($text).'</t></si>';
    }
    $sharedXml .= '</sst>';

    $sheetRows = '';
    $sheetRows .= rowXml(0, [0, 1, 2, 3]);
    foreach ($rows as $i => $row) {
        $base = 4 + ($i * 4);
        $sheetRows .= rowXml($i + 1, [$base, $base + 1, $base + 2, $base + 3]);
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetData>'.$sheetRows.'</sheetData></worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets><sheet name="Solicitud" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $relsWorkbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        .'</Relationships>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        .'</Types>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>';

    $files = [
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbookXml,
        'xl/_rels/workbook.xml.rels' => $relsWorkbook,
        'xl/worksheets/sheet1.xml' => $sheetXml,
        'xl/sharedStrings.xml' => $sharedXml,
        'xl/styles.xml' => $stylesXml,
    ];

    writeZip($path, $files);
}

function rowXml(int $rowIndex, array $stringIndexes): string
{
    $cells = '';
    foreach ($stringIndexes as $col => $si) {
        $colLetter = chr(ord('A') + $col);
        $cells .= '<c r="'.$colLetter.($rowIndex + 1).'" t="s"><v>'.$si.'</v></c>';
    }

    return '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
}

/**
 * @param  list<array{qty:int,product:string,sku:string,brand:string}>  $rows
 */
function writeDocx(string $path, array $rows): void
{
    $body = '';
    $body .= para('Solicitud de cotización — prueba');
    $body .= para('');
    foreach ($rows as $row) {
        $body .= para("{$row['qty']} {$row['product']} ({$row['sku']}) — {$row['brand']}");
    }

    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        .'<w:body>'.$body.'<w:sectPr/></w:body></w:document>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        .'</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        .'</Relationships>';

    writeZip($path, [
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rels,
        'word/document.xml' => $documentXml,
    ]);
}

function para(string $text): string
{
    return '<w:p><w:r><w:t>'.xml($text).'</w:t></w:r></w:p>';
}

/**
 * @param  list<array{qty:int,product:string,sku:string,brand:string}>  $rows
 */
function writePdf(string $path, array $rows): void
{
    $lines = ['Solicitud de cotizacion - prueba', ''];
    foreach ($rows as $row) {
        $lines[] = "{$row['qty']} {$row['product']} {$row['sku']} {$row['brand']}";
    }

    $y = 750;
    $stream = '';
    foreach ($lines as $line) {
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
        $stream .= "BT /F1 11 Tf 50 {$y} Td ({$safe}) Tj ET\n";
        $y -= 18;
    }
    $len = strlen($stream);

    $objects = [];
    $objects[] = "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n";
    $objects[] = "2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n";
    $objects[] = "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>endobj\n";
    $objects[] = "4 0 obj<< /Length {$len} >>stream\n{$stream}\nendstream endobj\n";
    $objects[] = "5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj\n";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= $obj;
    }

    $xrefPos = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 {$count}\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

    file_put_contents($path, $pdf);
}

/**
 * @param  array<string, string>  $files
 */
function writeZip(string $path, array $files): void
{
    $zip = new ZipArchive;
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("No se pudo crear {$path}");
    }
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
}

function xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
