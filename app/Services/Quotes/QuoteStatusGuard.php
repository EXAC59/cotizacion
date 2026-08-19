<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use Illuminate\Validation\ValidationException;

class QuoteStatusGuard
{
    /**
     * Valida que el estatus solicitado sea conocido.
     * Se permite avanzar o regresar en el flujo comercial (p. ej. Enviada → En elaboración).
     */
    public function assertForwardOnly(?Quote $existing, string $requestedStatus): void
    {
        $statuses = config('quotes.statuses', []);
        $legacyMap = config('quotes.legacy_status_map', []);
        $normalized = $legacyMap[$requestedStatus] ?? $requestedStatus;

        if ($statuses !== [] && ! in_array($normalized, $statuses, true)) {
            throw ValidationException::withMessages([
                'status' => ['El estatus de cotización no es válido.'],
            ]);
        }

        // $existing se conserva en la firma por compatibilidad con callers existentes.
        unset($existing);
    }
}
