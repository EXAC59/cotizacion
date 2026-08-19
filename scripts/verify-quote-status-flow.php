<?php

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Client;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Services\Quotes\QuoteLockService;
use App\Services\Quotes\QuotePersistenceService;
use App\Services\Quotes\QuoteStatusHistoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$user = User::query()->where('email', 'admin@cotizacion.test')->first()
    ?? User::query()->where('email', 'admin@exacto.mx')->first()
    ?? User::query()->orderBy('id')->first();

if (! $user) {
    fwrite(STDERR, "NO_USER\n");
    exit(1);
}

Auth::login($user);
echo 'user='.$user->email.PHP_EOL;

$client = Client::query()->first() ?? Client::query()->create([
    'company' => 'Test Status SA',
    'rfc' => 'TST010101AAA',
]);

$request = QuoteRequest::query()->create([
    'client_id' => $client->id,
    'source' => 'pdf',
    'status' => 'procesada',
    'file_name' => 'status-check.pdf',
]);

$persistence = app(QuotePersistenceService::class);
$history = app(QuoteStatusHistoryService::class);
$lock = app(QuoteLockService::class);

$line = [
    'quantity' => 1,
    'product' => 'Item status test',
    'partNumber' => 'SKU-STATUS-1',
    'cost' => 100,
    'marginPercent' => 30,
];

$fail = 0;

echo "=== 1) Create from request (default solicitud_cotizaciones) ===".PHP_EOL;
try {
    $q1 = $persistence->save([
        'folio' => 'COT-ST-'.time(),
        'clientId' => $client->id,
        'requestId' => $request->id,
        'lines' => [$line],
    ]);
    echo 'OK status='.$q1->status.PHP_EOL;
    $tl = $history->timelineForQuote($q1);
    echo 'history_count='.count($tl).PHP_EOL;
    echo 'history='.json_encode($tl, JSON_UNESCAPED_UNICODE).PHP_EOL;
    if ($q1->status !== 'solicitud_cotizaciones') {
        echo "UNEXPECTED status\n";
        $fail++;
    }
} catch (Throwable $e) {
    echo 'FAIL1 '.$e->getMessage().PHP_EOL;
    exit(1);
}

echo "=== 2) Create from request as en_elaboracion (dual history) ===".PHP_EOL;
try {
    $q2 = $persistence->save([
        'folio' => 'COT-ST2-'.time(),
        'clientId' => $client->id,
        'requestId' => $request->id,
        'status' => 'en_elaboracion',
        'lines' => [$line],
    ]);
    echo 'OK status='.$q2->status.PHP_EOL;
    $tos = array_column($history->timelineForQuote($q2), 'toStatus');
    echo 'history_tos='.json_encode($tos).PHP_EOL;
    echo 'has_solicitud='.(in_array('solicitud_cotizaciones', $tos, true) ? 'yes' : 'no').PHP_EOL;
    echo 'has_elaboracion='.(in_array('en_elaboracion', $tos, true) ? 'yes' : 'no').PHP_EOL;
    if (! in_array('solicitud_cotizaciones', $tos, true) || ! in_array('en_elaboracion', $tos, true)) {
        $fail++;
    }
} catch (Throwable $e) {
    echo 'FAIL2 '.$e->getMessage().PHP_EOL;
    exit(1);
}

echo "=== 3) Lock/API transition solicitud -> elaboracion ===".PHP_EOL;
try {
    $from = $q1->status;
    if ($from === 'solicitud_cotizaciones') {
        $lock->acquire($q1);
        $q1->update(['status' => 'en_elaboracion']);
        $history->record($q1->fresh(), $from, 'en_elaboracion');
    }
    echo 'OK status='.$q1->fresh()->status.PHP_EOL;
    echo 'history_count='.count($history->timelineForQuote($q1->fresh())).PHP_EOL;
} catch (Throwable $e) {
    echo 'FAIL3 '.$e->getMessage().PHP_EOL;
    $fail++;
}

echo "=== 4) Save elaboracion -> pendiente_envio ===".PHP_EOL;
try {
    $lock->acquire($q2);
    $q2 = $persistence->save([
        'id' => $q2->id,
        'folio' => $q2->folio,
        'clientId' => $client->id,
        'requestId' => $request->id,
        'status' => 'pendiente_envio',
        'lines' => [$line],
    ]);
    echo 'OK status='.$q2->status.PHP_EOL;
    $tl = $history->timelineForQuote($q2);
    echo 'history_count='.count($tl).PHP_EOL;
    $last = $tl[count($tl) - 1] ?? null;
    echo 'last='.json_encode($last, JSON_UNESCAPED_UNICODE).PHP_EOL;
    if (($last['toStatus'] ?? null) !== 'pendiente_envio') {
        $fail++;
    }
} catch (Throwable $e) {
    echo 'FAIL4 '.$e->getMessage().PHP_EOL;
    exit(1);
}

echo "=== 5) enviada -> modificacion history ===".PHP_EOL;
try {
    $q2->update(['status' => 'enviada', 'sent_at' => now()]);
    $history->record($q2->fresh(), 'pendiente_envio', 'enviada');
    $from = 'enviada';
    $q2->update(['status' => 'modificacion']);
    $history->record($q2->fresh(), $from, 'modificacion');
    echo 'OK status='.$q2->fresh()->status.PHP_EOL;
    $tos = array_column($history->timelineForQuote($q2->fresh()), 'toStatus');
    echo 'has_modificacion='.(in_array('modificacion', $tos, true) ? 'yes' : 'no').PHP_EOL;
} catch (Throwable $e) {
    echo 'FAIL5 '.$e->getMessage().PHP_EOL;
    exit(1);
}

$cols = DB::select(
    "SELECT character_maximum_length AS n FROM information_schema.columns WHERE table_name = 'quote_status_events' AND column_name = 'to_status'"
);
echo 'status_code_len='.strlen('solicitud_cotizaciones').' col_max='.($cols[0]->n ?? '?').PHP_EOL;

$log = storage_path('logs/laravel.log');
if (is_file($log)) {
    $tail = array_slice(file($log) ?: [], -300);
    $hits = array_values(array_filter(
        $tail,
        static fn (string $l): bool => str_contains($l, 'right truncated')
            || str_contains($l, 'QuoteStatusHistory')
            || (str_contains($l, 'ERROR') && str_contains($l, 'cotizaciones')),
    ));
    echo 'recent_status_errors='.count($hits).PHP_EOL;
}

echo $fail === 0 ? "ALL_OK\n" : "FAILED_{$fail}\n";
exit($fail === 0 ? 0 : 1);
