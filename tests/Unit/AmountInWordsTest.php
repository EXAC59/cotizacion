<?php

namespace Tests\Unit;

use App\Support\AmountInWords;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AmountInWordsTest extends TestCase
{
    #[Test]
    public function it_converts_total_to_pesos_words(): void
    {
        $this->assertSame(
            'DOCE MIL OCHOCIENTOS NOVENTA PESOS 66/100 M.N.',
            AmountInWords::pesosMx(12890.66),
        );
    }
}
