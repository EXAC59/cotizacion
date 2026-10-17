<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use Illuminate\Validation\ValidationException;

class QuoteStatusGuard
{
    public function assertPricedLines(array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Agrega al menos una partida antes de terminar o enviar la cotización.']);
        }

        $errors = [];
        foreach ($lines as $index => $line) {
            $price = (float) ($line['salePrice'] ?? 0);
            $amount = (float) ($line['amount'] ?? 0);
            if (! is_finite($price) || ! is_finite($amount) || $price <= 0 || $amount <= 0) {
                $errors["lines.$index.salePrice"] = 'Partida '.($index + 1).' ('.($line['product'] ?? '').'): captura un precio unitario mayor que cero antes de terminar o enviar la cotización.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

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
