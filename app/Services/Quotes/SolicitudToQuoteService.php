<?php

namespace App\Services\Quotes;

use App\Models\AppSetting;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Services\Rbac\RbacService;
use Illuminate\Support\Str;

/**
 * Crea (idempotente) una cotización de Compras a partir de una solicitud con líneas.
 */
class SolicitudToQuoteService
{
    public function __construct(
        private readonly QuotePersistenceService $persistence,
        private readonly QuoteProfitCalculator $calculator,
        private readonly RbacService $rbac,
    ) {}

    /**
     * @return array{quote: Quote|null, created: bool, skippedReason: string|null}
     */
    public function ensureQuoteForRequest(QuoteRequest $request, ?User $actor = null): array
    {
        $request->loadMissing(['lines', 'client']);

        $existing = Quote::query()
            ->where('request_id', $request->id)
            ->orderByDesc('created_at')
            ->first();

        if ($existing !== null) {
            return [
                'quote' => $existing->loadMissing(['client', 'lines']),
                'created' => false,
                'skippedReason' => null,
            ];
        }

        if ($request->client_id === null) {
            return [
                'quote' => null,
                'created' => false,
                'skippedReason' => 'missing_client',
            ];
        }

        if ($request->lines->isEmpty()) {
            return [
                'quote' => null,
                'created' => false,
                'skippedReason' => 'missing_lines',
            ];
        }

        if ($actor !== null) {
            $actor->loadMissing('role');
            if (! $this->rbac->userCan($actor, 'cotizaciones', 'create')) {
                return [
                    'quote' => null,
                    'created' => false,
                    'skippedReason' => 'no_permission',
                ];
            }
        }

        $margin = (float) AppSetting::current()->default_margin_percent;
        $tax = (float) AppSetting::current()->tax_percent;

        $lines = $request->lines->values()->map(function ($line, int $index) use ($margin) {
            $cost = (float) ($line->reference_cost ?? 0);
            $quantity = (float) $line->quantity;
            $salePrice = $this->calculator->salePriceFromCost($cost, $margin);
            $amount = round($quantity * $salePrice, 2);

            return [
                'id' => (string) Str::uuid(),
                'lineOrder' => $index,
                'quantity' => $quantity,
                'product' => (string) $line->product,
                'partNumber' => (string) ($line->part_number ?? ''),
                'cost' => $cost,
                'marginPercent' => $margin,
                'salePrice' => $salePrice,
                'amount' => $amount,
                'warehouse' => (string) ($line->warehouse ?: 'CDMX'),
                'usesGlobalMargin' => true,
                'selectedWholesalerId' => $line->selected_wholesaler_id,
                'offers' => [],
            ];
        })->all();

        $quote = $this->persistence->save([
            'clientId' => $request->client_id,
            'requestId' => $request->id,
            'status' => (string) config('quotes.from_request_status', 'solicitud_cotizaciones'),
            'validityDays' => 15,
            'globalMarginPercent' => $margin,
            'taxPercent' => $tax,
            'customerObservations' => '',
            'notes' => '',
            'lines' => $lines,
        ]);

        return [
            'quote' => $quote,
            'created' => true,
            'skippedReason' => null,
        ];
    }
}
