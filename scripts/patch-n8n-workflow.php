<?php

$exportPath = $argv[1] ?? '';
$sourcePath = $argv[2] ?? '';
$outPath = $argv[3] ?? '';

if ($exportPath === '' || $sourcePath === '' || $outPath === '') {
    fwrite(STDERR, "Usage: php patch-n8n-workflow.php <export.json> <source.workflow.json> <out.json>\n");
    exit(1);
}

$exported = json_decode(file_get_contents($exportPath), true);
$source = json_decode(file_get_contents($sourcePath), true);

if (! is_array($exported) || ! isset($exported[0]['id'])) {
    fwrite(STDERR, "Invalid export format\n");
    exit(1);
}

$workflow = $exported[0];
$workflow['nodes'] = $source['nodes'];
$workflow['connections'] = $source['connections'];
$workflow['name'] = $source['name'] ?? $workflow['name'];
$workflow['active'] = true;

file_put_contents($outPath, json_encode([$workflow], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Wrote {$outPath}\n";
