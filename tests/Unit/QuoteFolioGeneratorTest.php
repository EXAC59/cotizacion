<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Quote;
use App\Models\User;
use App\Services\Quotes\QuoteFolioGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QuoteFolioGeneratorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_generates_sequential_folios_per_user_code(): void
    {
        $generator = app(QuoteFolioGenerator::class);
        $sales = User::factory()->create([
            'name' => 'Luis Ramírez',
            'folio_code' => 'LUIS',
        ]);
        $this->actingAs($sales);

        Quote::query()->create([
            'folio' => 'COT-LUIS-0003',
            'client_id' => Client::query()->create([
                'company' => 'Folio Test SA',
                'rfc' => 'FOL010101ABC',
            ])->id,
            'created_by' => $sales->id,
            'status' => 'en_elaboracion',
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        $this->assertSame('COT-LUIS-0004', $generator->generate());
    }

    #[Test]
    public function it_falls_back_to_legacy_year_format_when_user_code_is_missing(): void
    {
        $generator = app(QuoteFolioGenerator::class);
        $year = (int) now()->format('Y');

        $this->actingAs(User::factory()->create([
            'name' => '---',
            'folio_code' => null,
        ]));

        Quote::query()->create([
            'folio' => "COT-{$year}-0007",
            'client_id' => Client::query()->create([
                'company' => 'Legacy SA',
                'rfc' => 'LEG010101ABC',
            ])->id,
            'status' => 'en_elaboracion',
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        $this->assertSame("COT-{$year}-0008", $generator->generate());
    }
}
