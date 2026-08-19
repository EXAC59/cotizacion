<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$path = $argv[1];
$docling = app(App\Services\DoclingClient::class);
$parser = app(App\Services\LecturaLineParser::class);
$validator = app(App\Services\SolicitudLineasValidator::class);

$markdown = App\Services\DoclingMarkdownExtractor::extract(
    $docling->convertFileToMarkdown($path, false)
);

echo "FILE: $path\n\n=== MARKDOWN ===\n$markdown\n\n";
print_r($parser->detectColumnMap($markdown));
$lines = $parser->fromMarkdown($markdown);
echo "\nLINES: ".count($lines)."\n";
try {
    $validator->validate($markdown, 'excel', $lines);
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAIL: ".$e->getMessage()."\n";
}
