<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Services\Quotes\QuoteClosingResultService;
use App\Services\Quotes\QuoteProfitCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuoteClosingResultController extends Controller
{
    public function show(Request $request, string $id, QuoteClosingResultService $closing): JsonResponse
    {
        $quote = Quote::query()->with(['client', 'lines', 'closingResult'])->findOrFail($id);
        abort_unless($quote->sent_at, 422, 'Esta cotización aún no se ha enviado al cliente.');
        $result = $quote->closingResult;
        $showFinancials = in_array($request->user()->role_slug, ['gerente_compras', 'administrador'], true);
        $calculator = app(QuoteProfitCalculator::class);
        $totals = $showFinancials ? $calculator->quoteTotals($quote->lines->map(fn ($line) => [
            'quantity' => (float) $line->quantity, 'cost' => (float) $line->cost, 'amount' => (float) $line->amount,
        ])->all(), (float) $quote->tax_percent) : null;

        return response()->json([
            'id' => $quote->id, 'folio' => $quote->folio, 'clientName' => $quote->client?->company ?? '',
            'canEdit' => $closing->canEdit($quote, $request->user()),
            'quoteVersion' => $quote->updated_at->format('Y-m-d H:i:s.u'),
            'version' => $result?->version ?? 0, 'status' => $result?->status ?? 'enviada',
            'invoice' => $result?->invoice ?? '', 'comments' => $result?->comments ?? '',
            'selectedLines' => $result?->selected_lines ?? [],
            'subtotal' => (float) $quote->subtotal, 'taxAmount' => (float) $quote->tax_amount,
            'taxPercent' => (float) $quote->tax_percent, 'total' => (float) $quote->total,
            ...($showFinancials ? ['financials' => ['profit' => $totals['totalProfit'], 'marginPercent' => $totals['profitPercent']]] : []),
            'lines' => $quote->lines->sortBy('line_order')->values()->map(fn ($line) => [
                'id' => $line->id, 'quantity' => (float) $line->quantity, 'product' => $line->product,
                'partNumber' => $line->part_number, 'salePrice' => (float) $line->sale_price, 'amount' => (float) $line->amount,
                ...($showFinancials ? ['financials' => [
                    'profit' => $calculator->lineProfit((float) $line->quantity, (float) $line->cost, (float) $line->sale_price),
                    'marginPercent' => $calculator->marginFromCostAndSalePrice((float) $line->cost, (float) $line->sale_price),
                ]] : []),
            ])->all(),
        ]);
    }

    public function update(Request $request, string $id, QuoteClosingResultService $closing): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(QuoteClosingResultService::LABELS))],
            'invoice' => ['nullable', 'string', 'max:60'], 'comments' => ['nullable', 'string', 'max:2000'],
            'selectedLineIds' => ['nullable', 'array', 'max:1000'],
            'selectedLineIds.*' => ['required', 'uuid', 'distinct'],
            'version' => ['required', 'integer', 'min:0'], 'quoteVersion' => ['required', 'string', 'max:40'],
            'requestId' => ['required', 'uuid'],
        ]);
        $closing->save($id, $request->user(), $data);

        return $this->show($request, $id, $closing);
    }
}
