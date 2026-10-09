<?php

namespace App\Services;

use App\Models\QuoteRequest;
use Illuminate\Support\Facades\DB;

class SolicitudFolioGenerator
{
    public function generate(): string
    {
        // Serializa la lectura del consecutivo hasta que la solicitud se inserta
        // dentro de la misma transacción (PostgreSQL).
        if (DB::transactionLevel() > 0 && DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(?)', [7319042601]);
        }

        $prefix = 'SC';
        $pad = max(4, (int) config('solicitudes.folio_sequence_pad', 4));
        $maxSeq = $this->maxSequenceForPrefix($prefix);

        for ($offset = 1; $offset <= 50; $offset++) {
            $folio = sprintf('%s-%0'.$pad.'d', $prefix, $maxSeq + $offset);
            if (! QuoteRequest::query()->where('folio', '=', $folio, 'and')->exists()) {
                return $folio;
            }
        }

        throw new \RuntimeException('No se pudo generar un folio único de solicitud.');
    }

    private function maxSequenceForPrefix(string $folioPrefix): int
    {
        $needle = "{$folioPrefix}-";
        $max = 0;

        QuoteRequest::query()
            ->where('folio', 'like', $needle.'%', 'and')
            ->pluck('folio')
            ->each(function (string $folio) use (&$max) {
                if (preg_match('/-(\d+)$/', $folio, $matches)) {
                    $max = max($max, (int) $matches[1]);
                }
            });

        return $max;
    }

}
