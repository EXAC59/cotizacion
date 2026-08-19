<?php

namespace Tests\Unit;

use App\Services\Quotes\QuoteSearchScope;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QuoteSearchScopeTest extends TestCase
{
    #[Test]
    public function it_parses_folio_search_parts(): void
    {
        $this->assertSame(
            ['text' => 'demo', 'number' => 1],
            QuoteSearchScope::parseFolioParts('demo-001'),
        );

        $this->assertSame(
            ['text' => 'demo', 'number' => 1],
            QuoteSearchScope::parseFolioParts('COT-DEMO-0001'),
        );

        $this->assertNull(QuoteSearchScope::parseFolioParts('ACME'));
    }

    #[Test]
    public function it_builds_padded_suffixes(): void
    {
        $this->assertSame(['1', '01', '001', '0001'], QuoteSearchScope::paddedSuffixes(1, 4));
    }
}
