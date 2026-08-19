<?php

namespace Tests\Unit;

use App\Services\Quotes\QuoteProfitCalculator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QuoteProfitCalculatorPayloadTest extends TestCase
{
    #[Test]
    public function recalc_quote_payload_preserves_line_fields(): void
    {
        $calculator = app(QuoteProfitCalculator::class);

        $result = $calculator->recalcQuotePayload([
            'globalMarginPercent' => 30,
            'taxPercent' => 16,
            'lines' => [
                [
                    'quantity' => 2,
                    'product' => 'Switch Cisco',
                    'partNumber' => 'C9200L-24T-4G-E',
                    'cost' => 1200,
                    'marginPercent' => 30,
                    'offers' => [['wholesalerId' => '00000000-0000-4000-8000-000000000001']],
                ],
            ],
        ]);

        $this->assertCount(1, $result['lines']);
        $this->assertSame('Switch Cisco', $result['lines'][0]['product']);
        $this->assertNotEmpty($result['lines'][0]['offers']);
    }
}
