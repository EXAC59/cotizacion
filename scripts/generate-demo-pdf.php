<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = $argv[1] ?? '019ebda7-d11c-719f-8a85-87019ab98e3f';
$out = __DIR__.'/../public/cotizacion-demo-exacto.pdf';

try {
    $pdf = app(App\Services\Quotes\QuotePdfService::class)->render($id);
    file_put_contents($out, $pdf->output());
    echo 'OK: '.$out.' ('.filesize($out).' bytes)'.PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.$e->getMessage().PHP_EOL;
    exit(1);
}
