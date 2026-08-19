<?php

namespace Tests\Unit;

use App\Services\Wholesalers\SkuLookupPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SkuLookupPolicyTest extends TestCase
{
    #[Test]
    public function it_combines_catalog_and_raw_candidates_case_insensitively(): void
    {
        $this->assertSame(
            ['CN-1616', 'T664320'],
            SkuLookupPolicy::candidates(' t664320 ', ['cn-1616', 'CN1616']),
        );
    }

    #[Test]
    public function it_only_accepts_a_response_matching_the_queried_candidate(): void
    {
        $this->assertTrue(SkuLookupPolicy::responseMatchesCandidate('CN-1616', ['CN1616', 'T664320']));
        $this->assertTrue(SkuLookupPolicy::responseMatchesCandidate('t664320', ['CN-1616', 'T664320']));
        $this->assertFalse(SkuLookupPolicy::responseMatchesCandidate('CZ103AL', ['CZ104AL', 'Otro']));
    }
}
