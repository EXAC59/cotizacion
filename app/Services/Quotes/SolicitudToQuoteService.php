<?php

namespace App\Services\Quotes;

use App\Models\AppSetting;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Services\Rbac\RbacService;
use App\Services\Sales\SalesNotificationService;
use App\Services\SolicitudLecturaService;
use Illuminate\Support\Facades\DB;
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
        private readonly SalesNotificationService $notifications,
    ) {}

    /**
     * @return array{quote: Quote|null, created: bool, skippedReason: string|null, comprasNotificationCount: int}
     */
    public function ensureQuoteForRequest(QuoteRequest $request, ?User $actor = null): array
    {
        $request->loadMissing(['lines', 'client']);

        $existing = Quote::query()
            ->where('request_id', $request->id)
            ->orderByDesc('created_at')
            ->first();

        if ($existing !== null) {
            $this->notifications->notifyComprasNewRequest($existing, $actor);

            $notificationCount = $existing->salesNotifications()
                ->where('audience', SalesNotificationService::AUDIENCE_COMPRAS)
                ->count();

            return [
                'quote' => $existing->loadMissing(['client', 'lines']),
                'created' => false,
                'skippedReason' => null,
                'comprasNotificationCount' => $notificationCount,
            ];
        }

        if ($request->client_id === null) {
            return [
                'quote' => null,
                'created' => false,
                'skippedReason' => 'missing_client',
                'comprasNotificationCount' => 0,
            ];
        }

        if ($request->lines->isEmpty()) {
            return [
                'quote' => null,
                'created' => false,
                'skippedReason' => 'missing_lines',
                'comprasNotificationCount' => 0,
            ];
        }

        if ($actor !== null) {
            $actor->loadMissing('role');
            if (! $this->rbac->userCan($actor, 'cotizaciones', 'create')) {
                return [
                    'quote' => null,
                    'created' => false,
                    'skippedReason' => 'no_permission',
                    'comprasNotificationCount' => 0,
                ];
            }
        }

        $margin = (float) AppSetting::current()->default_margin_percent;
        $tax = (float) AppSetting::current()->tax_percent;
        $lines = $this->buildPersistenceLines($request, $margin);

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

        $this->notifications->notifyComprasNewRequest($quote, $actor);

        $notificationCount = $quote->salesNotifications()
            ->where('audience', SalesNotificationService::AUDIENCE_COMPRAS)
            ->count();

        return [
            'quote' => $quote,
            'created' => true,
            'skippedReason' => null,
            'comprasNotificationCount' => $notificationCount,
        ];
    }

    /**
     * Guarda solicitud y cotización bajo la misma transacción, conservando los
     * bloqueos que impiden competir con la toma de atención de Compras.
     *
     * @return array{quote: Quote|null, created: bool, skippedReason: string|null, comprasNotificationCount: int}
     */
    public function replaceRequestLinesAndSync(string $requestId, array $lines, ?User $actor = null): array
    {
        return DB::transaction(function () use ($requestId, $lines, $actor) {
            $request = app(SolicitudLecturaService::class)->reemplazarLineas($requestId, $lines);

            return $this->syncQuoteFromRequest($request->fresh(['lines', 'client']), $actor);
        });
    }

    /**
     * Asegura cotización y sincroniza partidas desde la solicitud
     * para una solicitud inicial no tomada. La entrada de edición usa
     * replaceRequestLinesAndSync para comprobar su estado y conservar los bloqueos.
     *
     * @return array{quote: Quote|null, created: bool, skippedReason: string|null, comprasNotificationCount: int}
     */
    public function syncQuoteFromRequest(QuoteRequest $request, ?User $actor = null): array
    {
        $ensured = $this->ensureQuoteForRequest($request, $actor);
        $quote = $ensured['quote'];
        if ($quote === null || $ensured['created']) {
            return $ensured;
        }

        $request->loadMissing(['lines', 'client']);
        $margin = (float) ($quote->global_margin_percent ?? AppSetting::current()->default_margin_percent);
        $taxPercent = (float) ($quote->tax_percent ?? AppSetting::current()->tax_percent);
        $lines = $this->buildPersistenceLines($request, $margin);

        DB::transaction(function () use ($quote, $lines, $taxPercent) {
            QuoteLine::query()->where('quote_id', $quote->id)->delete();

            $subtotal = 0.0;
            foreach ($lines as $index => $linePayload) {
                $amount = (float) ($linePayload['amount'] ?? 0);
                $subtotal += $amount;
                QuoteLine::query()->create([
                    'id' => (string) Str::uuid(),
                    'quote_id' => $quote->id,
                    'line_order' => (int) ($linePayload['lineOrder'] ?? $index),
                    'quantity' => (float) ($linePayload['quantity'] ?? 1),
                    'product' => (string) ($linePayload['product'] ?? ''),
                    'part_number' => (string) ($linePayload['partNumber'] ?? ''),
                    'cost' => (float) ($linePayload['cost'] ?? 0),
                    'margin_percent' => (float) ($linePayload['marginPercent'] ?? 30),
                    'sale_price' => (float) ($linePayload['salePrice'] ?? 0),
                    'amount' => $amount,
                    'warehouse' => (string) ($linePayload['warehouse'] ?? ''),
                    'selected_wholesaler_id' => $linePayload['selectedWholesalerId'] ?? null,
                ]);
            }

            $taxAmount = round($subtotal * ($taxPercent / 100), 2);
            $quote->update([
                'subtotal' => round($subtotal, 2),
                'tax_amount' => $taxAmount,
                'total' => round($subtotal + $taxAmount, 2),
            ]);
        });

        return [
            'quote' => $quote->fresh(['client', 'lines']),
            'created' => false,
            'skippedReason' => null,
            'comprasNotificationCount' => $ensured['comprasNotificationCount'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPersistenceLines(QuoteRequest $request, float $margin): array
    {
        return $request->lines->values()->map(function ($line, int $index) use ($margin) {
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
    }
}
