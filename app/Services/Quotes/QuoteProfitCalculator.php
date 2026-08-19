<?php

namespace App\Services\Quotes;

use App\Models\AppSetting;

class QuoteProfitCalculator
{
    public function defaultMargin(): float
    {
        return AppSetting::current()->default_margin_percent;
    }

    public function defaultTax(): float
    {
        return AppSetting::current()->tax_percent;
    }

    public function salePriceFromCost(float $cost, float $marginPercent): float
    {
        if ($cost <= 0) {
            return 0.0;
        }

        return $this->roundPrice($cost * (1 + $marginPercent / 100));
    }

    public function marginFromCostAndSalePrice(float $cost, float $salePrice): float
    {
        if ($cost <= 0 || $salePrice <= 0) {
            return 0.0;
        }

        return $this->roundMargin((($salePrice - $cost) / $cost) * 100);
    }

    public function lineAmount(float $quantity, float $salePrice): float
    {
        return $this->roundPrice($quantity * $salePrice);
    }

    public function lineProfit(float $quantity, float $cost, float $salePrice): float
    {
        return $this->roundPrice($quantity * ($salePrice - $cost));
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function recalcLine(array $line, ?float $globalMargin = null): array
    {
        $margin = (float) ($line['marginPercent'] ?? $globalMargin ?? $this->defaultMargin());
        $cost = (float) ($line['cost'] ?? 0);
        $quantity = (float) ($line['quantity'] ?? 1);
        $salePrice = $this->salePriceFromCost($cost, $margin);

        return [
            ...$line,
            'marginPercent' => $margin,
            'salePrice' => $salePrice,
            'amount' => $this->lineAmount($quantity, $salePrice),
            'lineProfit' => $this->lineProfit($quantity, $cost, $salePrice),
        ];
    }

    /**
     * Recalcula partida fijando precio de venta (ajusta margen %).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function recalcLineFromSalePrice(array $line, float $salePrice): array
    {
        $cost = (float) ($line['cost'] ?? 0);
        $quantity = (float) ($line['quantity'] ?? 1);
        $salePrice = max(0, $salePrice);
        $margin = $this->marginFromCostAndSalePrice($cost, $salePrice);

        return [
            ...$line,
            'marginPercent' => $margin,
            'salePrice' => $this->roundPrice($salePrice),
            'amount' => $this->lineAmount($quantity, $salePrice),
            'lineProfit' => $this->lineProfit($quantity, $cost, $salePrice),
            'usesGlobalMargin' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public function applyGlobalMargin(array $lines, float $globalMargin, bool $onlyGlobalLines = false): array
    {
        return array_map(function (array $line) use ($globalMargin, $onlyGlobalLines) {
            if ($onlyGlobalLines && ! ($line['usesGlobalMargin'] ?? true)) {
                return $this->recalcLine($line);
            }

            return $this->recalcLine([
                ...$line,
                'marginPercent' => $globalMargin,
                'usesGlobalMargin' => true,
            ], $globalMargin);
        }, $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, float|int>
     */
    public function quoteTotals(array $lines, float $taxPercent): array
    {
        $subtotal = 0.0;
        $totalCost = 0.0;

        foreach ($lines as $line) {
            $subtotal += (float) ($line['amount'] ?? 0);
            $totalCost += (float) ($line['quantity'] ?? 0) * (float) ($line['cost'] ?? 0);
        }

        $totalProfit = $subtotal - $totalCost;
        $tax = $this->roundPrice($subtotal * ($taxPercent / 100));
        $total = $this->roundPrice($subtotal + $tax);
        $profitPercent = $totalCost > 0 ? $this->roundMargin(($totalProfit / $totalCost) * 100) : 0.0;

        return [
            'subtotal' => $this->roundPrice($subtotal),
            'tax' => $tax,
            'total' => $total,
            'totalCost' => $this->roundPrice($totalCost),
            'totalProfit' => $this->roundPrice($totalProfit),
            'profitPercent' => $profitPercent,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recalcQuotePayload(array $payload): array
    {
        $globalMargin = (float) ($payload['globalMarginPercent'] ?? $this->defaultMargin());
        $taxPercent = (float) ($payload['taxPercent'] ?? $this->defaultTax());
        $rawLines = $payload['lines'] ?? [];

        $lines = array_map(function (array $line) use ($globalMargin) {
            if (($line['usesGlobalMargin'] ?? true) === false) {
                return $this->recalcLineWithOptionalManualPrice($line);
            }

            return $this->recalcLine($line, $globalMargin);
        }, $rawLines);

        $totals = $this->quoteTotals($lines, $taxPercent);

        return [
            ...$payload,
            'globalMarginPercent' => $globalMargin,
            'taxPercent' => $taxPercent,
            'lines' => array_values($lines),
            'totals' => $totals,
            'subtotal' => $totals['subtotal'],
            'taxAmount' => $totals['tax'],
            'total' => $totals['total'],
        ];
    }

    /**
     * Partida con margen propio: respeta precio de venta manual si difiere del derivado por margen.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function recalcLineWithOptionalManualPrice(array $line): array
    {
        $cost = (float) ($line['cost'] ?? 0);
        $margin = (float) ($line['marginPercent'] ?? $this->defaultMargin());
        $salePrice = (float) ($line['salePrice'] ?? 0);
        $derived = $this->salePriceFromCost($cost, $margin);

        if ($salePrice > 0 && abs($salePrice - $derived) > 0.0001) {
            return $this->recalcLineFromSalePrice($line, $salePrice);
        }

        return $this->recalcLine($line);
    }

    private function roundPrice(float $value): float
    {
        return round($value, (int) config('quote_pricing.price_decimals', 4));
    }

    private function roundMargin(float $value): float
    {
        return round($value, (int) config('quote_pricing.margin_decimals', 2));
    }
}
