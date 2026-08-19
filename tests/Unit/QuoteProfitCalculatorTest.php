<?php

namespace Tests\Unit;

use App\Services\Quotes\QuoteProfitCalculator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QuoteProfitCalculatorTest extends TestCase
{
    private QuoteProfitCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = app(QuoteProfitCalculator::class);
    }

    #[Test]
    public function it_calculates_sale_price_from_cost_and_margin(): void
    {
        $this->assertSame(1300.0, $this->calculator->salePriceFromCost(1000, 30));
    }

    #[Test]
    public function it_calculates_margin_from_cost_and_sale_price(): void
    {
        $this->assertSame(30.0, $this->calculator->marginFromCostAndSalePrice(1000, 1300));
    }

    #[Test]
    public function it_recalculates_line_with_default_margin(): void
    {
        $line = $this->calculator->recalcLine([
            'quantity' => 2,
            'cost' => 1000,
            'marginPercent' => 30,
        ]);

        $this->assertSame(1300.0, $line['salePrice']);
        $this->assertSame(2600.0, $line['amount']);
        $this->assertSame(600.0, $line['lineProfit']);
    }

    #[Test]
    public function it_recalculates_line_from_manual_sale_price(): void
    {
        $line = $this->calculator->recalcLineFromSalePrice([
            'quantity' => 1,
            'cost' => 1000,
            'marginPercent' => 30,
        ], 1500);

        $this->assertSame(50.0, $line['marginPercent']);
        $this->assertSame(1500.0, $line['salePrice']);
        $this->assertFalse($line['usesGlobalMargin']);
    }

    #[Test]
    public function it_applies_global_margin_only_to_global_lines(): void
    {
        $lines = $this->calculator->applyGlobalMargin([
            ['quantity' => 1, 'cost' => 1000, 'marginPercent' => 30, 'usesGlobalMargin' => true],
            ['quantity' => 1, 'cost' => 1000, 'marginPercent' => 45, 'usesGlobalMargin' => false],
        ], 35, true);

        $this->assertSame(35.0, $lines[0]['marginPercent']);
        $this->assertSame(45.0, $lines[1]['marginPercent']);
    }

    #[Test]
    public function it_preserves_manual_sale_price_when_not_using_global_margin(): void
    {
        $payload = $this->calculator->recalcQuotePayload([
            'globalMarginPercent' => 30,
            'taxPercent' => 16,
            'lines' => [
                [
                    'quantity' => 1,
                    'cost' => 1000,
                    'marginPercent' => 30,
                    'salePrice' => 1400,
                    'usesGlobalMargin' => false,
                ],
            ],
        ]);

        $this->assertSame(1400.0, $payload['lines'][0]['salePrice']);
        $this->assertSame(40.0, $payload['lines'][0]['marginPercent']);
    }

    #[Test]
    public function it_calculates_quote_totals_with_profit(): void
    {
        $totals = $this->calculator->quoteTotals([
            ['quantity' => 2, 'cost' => 1000, 'amount' => 2600],
        ], 16);

        $this->assertSame(2600.0, $totals['subtotal']);
        $this->assertSame(2000.0, $totals['totalCost']);
        $this->assertSame(600.0, $totals['totalProfit']);
        $this->assertSame(30.0, $totals['profitPercent']);
        $this->assertSame(416.0, $totals['tax']);
        $this->assertSame(3016.0, $totals['total']);
    }
}
