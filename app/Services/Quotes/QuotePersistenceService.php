<?php

namespace App\Services\Quotes;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteLineOffer;
use App\Models\Wholesaler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuotePersistenceService
{
    public function __construct(
        private readonly QuoteProfitCalculator $calculator,
        private readonly QuoteStatusGuard $statusGuard,
        private readonly QuoteLockService $quoteLockService,
        private readonly QuoteFolioGenerator $folioGenerator,
        private readonly QuoteStatusHistoryService $statusHistory,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function save(array $payload): Quote
    {
        $clientId = $payload['clientId'] ?? null;
        if (! $clientId || ! Client::query()->whereKey($clientId)->exists()) {
            throw ValidationException::withMessages([
                'clientId' => ['Cliente no encontrado.'],
            ]);
        }

        $payload = $this->calculator->recalcQuotePayload($payload);

        return DB::transaction(function () use ($payload, $clientId) {
            $quoteId = $this->resolveQuoteId($payload);
            /** @var Quote|null $existingQuote */
            $existingQuote = Quote::find($quoteId, ['*']);
            $lines = $payload['lines'] ?? [];

            $requestedStatus = $payload['status'] ?? null;
            if ($requestedStatus === null || $requestedStatus === '') {
                $hasRequest = $this->nullableUuid($payload['requestId'] ?? null) !== null
                    || ($existingQuote?->request_id !== null);
                if ($existingQuote === null && $hasRequest) {
                    $requestedStatus = (string) config('quotes.from_request_status', 'solicitud_cotizaciones');
                } else {
                    $requestedStatus = (string) config('quotes.default_status', 'en_elaboracion');
                }
            }
            $legacyMap = config('quotes.legacy_status_map', []);
            $requestedStatus = $legacyMap[$requestedStatus] ?? $requestedStatus;
            $previousStatus = $existingQuote?->status;

            // Guardar cambios sobre una cotización ya enviada/aceptada/facturada
            // → pasa a "modificacion" (no al abrir, sino al persistir edición).
            $postSent = config('quotes.modificacion_from_statuses', ['enviada', 'aceptada', 'facturada']);
            if (
                $existingQuote !== null
                && in_array((string) $previousStatus, $postSent, true)
                && in_array($requestedStatus, ['en_elaboracion', 'pendiente_envio', 'modificacion', (string) $previousStatus], true)
                && $requestedStatus !== 'enviada'
            ) {
                $requestedStatus = 'modificacion';
            }

            $this->statusGuard->assertForwardOnly($existingQuote, $requestedStatus);

            if ($existingQuote !== null) {
                $this->quoteLockService->assertHeldByCurrentUser($existingQuote);
            }

            $subtotal = (float) ($payload['subtotal'] ?? 0);
            $taxAmount = (float) ($payload['taxAmount'] ?? 0);
            $total = (float) ($payload['total'] ?? 0);
            $taxPercent = (float) ($payload['taxPercent'] ?? 16);

            $createdBy = $existingQuote?->created_by ?? $this->resolveCreatedBy($payload);
            $folio = $existingQuote?->folio ?? $this->folioGenerator->generate();

            $quote = Quote::query()->updateOrCreate(
                ['id' => $quoteId],
                [
                    'folio' => $folio,
                    'client_id' => $clientId,
                    'request_id' => $this->nullableUuid($payload['requestId'] ?? null),
                    'created_by' => $createdBy,
                    'status' => $requestedStatus,
                    'validity_days' => (int) ($payload['validityDays'] ?? 15),
                    'global_margin_percent' => (float) ($payload['globalMarginPercent'] ?? 30),
                    'tax_percent' => $taxPercent,
                    // `notes` es legado interno; no permitir que un guardado
                    // nuevo lo borre ni que termine expuesto al cliente.
                    'notes' => $existingQuote?->notes ?? ($payload['notes'] ?? ''),
                    'customer_observations' => trim((string) ($payload['customerObservations'] ?? '')),
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total' => $total,
                    'sent_at' => $payload['sentAt'] ?? null,
                    'invoice_number' => trim((string) ($payload['invoiceNumber'] ?? '')) ?: null,
                ],
            );

            $keptLineIds = [];

            foreach ($lines as $index => $linePayload) {
                $lineKey = isset($linePayload['id']) && Str::isUuid($linePayload['id'])
                    ? ['id' => $linePayload['id']]
                    : ['id' => (string) Str::uuid()];

                $selectedWholesalerId = $this->nullableWholesalerId($linePayload['selectedWholesalerId'] ?? null);

                $line = QuoteLine::query()->updateOrCreate(
                    $lineKey,
                    [
                        'quote_id' => $quote->id,
                        'line_order' => (int) ($linePayload['lineOrder'] ?? $index),
                        'quantity' => (float) ($linePayload['quantity'] ?? 1),
                        'product' => $linePayload['product'] ?? '',
                        'part_number' => $linePayload['partNumber'] ?? '',
                        'cost' => (float) ($linePayload['cost'] ?? 0),
                        'margin_percent' => (float) ($linePayload['marginPercent'] ?? 30),
                        'sale_price' => (float) ($linePayload['salePrice'] ?? 0),
                        'amount' => (float) ($linePayload['amount'] ?? 0),
                        'warehouse' => $linePayload['warehouse'] ?? '',
                        'selected_wholesaler_id' => $selectedWholesalerId,
                    ],
                );

                $keptLineIds[] = $line->id;

                $this->syncLineOffers($line, $linePayload['offers'] ?? [], $selectedWholesalerId);
            }

            QuoteLine::query()
                ->where('quote_id', '=', $quote->id, 'and')
                ->whereNotIn('id', $keptLineIds)
                ->delete();

            // Crear cotización vinculada NO implica envío al cliente ni cambia
            // el workflow_status de la solicitud (concepto retirado del producto).

            if ($existingQuote === null) {
                $fromRequest = $this->nullableUuid($payload['requestId'] ?? null) !== null;
                if ($fromRequest && $requestedStatus !== 'solicitud_cotizaciones') {
                    $this->statusHistory->record($quote, null, 'solicitud_cotizaciones');
                    $this->statusHistory->record($quote, 'solicitud_cotizaciones', $requestedStatus);
                } else {
                    $this->statusHistory->record($quote, null, $requestedStatus);
                }
            } elseif ($previousStatus !== $requestedStatus) {
                $this->statusHistory->record($quote, $previousStatus, $requestedStatus);
            }

            return $quote->fresh(['lines.offers.wholesaler', 'client']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     */
    private function syncLineOffers(QuoteLine $line, array $offers, ?string $selectedWholesalerId): void
    {
        if ($offers === []) {
            QuoteLineOffer::query()->where('quote_line_id', '=', $line->id, 'and')->delete();

            return;
        }

        $keptIds = [];

        foreach ($offers as $offerPayload) {
            $wholesalerId = $this->nullableWholesalerId($offerPayload['wholesalerId'] ?? null);
            if (! $wholesalerId) {
                continue;
            }

            $isSelected = (bool) ($offerPayload['isSelected'] ?? false)
                || ($selectedWholesalerId !== null && $wholesalerId === $selectedWholesalerId);

            $record = QuoteLineOffer::query()->updateOrCreate(
                [
                    'quote_line_id' => $line->id,
                    'wholesaler_id' => $wholesalerId,
                ],
                [
                    'cost' => (float) ($offerPayload['cost'] ?? 0),
                    'stock' => (int) ($offerPayload['stock'] ?? 0),
                    'warehouse' => $offerPayload['warehouse'] ?? '',
                    'lead_days' => (int) ($offerPayload['leadDays'] ?? 0),
                    'is_selected' => $isSelected,
                ],
            );

            $keptIds[] = $record->id;
        }

        if ($keptIds !== []) {
            QuoteLineOffer::query()
                ->where('quote_line_id', '=', $line->id, 'and')
                ->whereNotIn('id', $keptIds)
                ->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveQuoteId(array $payload): string
    {
        $id = $payload['id'] ?? null;
        if ($id !== null && Str::isUuid($id)) {
            return $id;
        }

        $folio = isset($payload['folio']) ? trim((string) $payload['folio']) : '';
        if ($folio !== '') {
            $existing = Quote::query()->where('folio', '=', $folio, 'and')->first();
            if ($existing !== null) {
                return $existing->id;
            }
        }

        return (string) Str::uuid();
    }

    private function resolveUuid(?string $id): string
    {
        if ($id !== null && Str::isUuid($id)) {
            return $id;
        }

        return (string) Str::uuid();
    }

    private function nullableUuid(?string $id): ?string
    {
        if ($id !== null && Str::isUuid($id)) {
            return $id;
        }

        return null;
    }

    private function nullableWholesalerId(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (! Str::isUuid($id)) {
            return null;
        }

        return Wholesaler::query()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCreatedBy(array $payload): ?int
    {
        $user = Auth::user();

        return $user?->id;
    }
}
