<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->formatFolios(4);
    }

    public function down(): void
    {
        $this->formatFolios(6);
    }

    private function formatFolios(int $digits): void
    {
        DB::transaction(function () use ($digits) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE quote_requests IN ACCESS EXCLUSIVE MODE');
            }

            $updates = [];
            $seen = [];
            foreach (DB::table('quote_requests')->select('id', 'folio')->where('folio', 'like', 'SC-%')->get() as $request) {
                if (! preg_match('/^SC-(\d+)$/', $request->folio, $matches)) {
                    continue;
                }
                // Solo cambia el relleno: conserva número, UUID y relaciones.
                $sequence = ltrim($matches[1], '0');
                $folio = 'SC-'.str_pad($sequence === '' ? '0' : $sequence, $digits, '0', STR_PAD_LEFT);
                if (isset($seen[$folio])) {
                    throw new RuntimeException('Hay consecutivos SC duplicados; no se modificaron los folios.');
                }
                $seen[$folio] = true;
                if ($folio !== $request->folio) {
                    $updates[$request->id] = $folio;
                }
            }

            foreach ($updates as $id => $folio) {
                DB::table('quote_requests')->where('id', $id)->update(['folio' => $folio]);
            }
        });
    }
};
