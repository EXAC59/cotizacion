<?php

namespace Tests\Unit;

use App\Support\MexicanRfc;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MexicanRfcTest extends TestCase
{
    #[Test]
    public function it_normalizes_rfc_for_matching(): void
    {
        $this->assertSame('ACM010101ABC', MexicanRfc::normalize('acm-010101-abc'));
    }

    #[Test]
    public function it_validates_mexican_rfc_format(): void
    {
        $this->assertTrue(MexicanRfc::isValid('ACM010101ABC'));
        $this->assertTrue(MexicanRfc::isValid(''));
        $this->assertFalse(MexicanRfc::isValid('MALRFC'));
    }
}
