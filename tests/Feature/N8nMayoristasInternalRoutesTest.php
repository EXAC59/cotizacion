<?php

namespace Tests\Feature;

use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerCatalogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class N8nMayoristasInternalRoutesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_lists_mayoristas_without_sanctum_via_n8n_route(): void
    {
        app(WholesalerCatalogSync::class)->sync();

        $response = $this->getJson('/api/n8n/mayoristas?active=1');

        $response->assertOk()
            ->assertJsonStructure(['data']);
        $this->assertNotEmpty($response->json('data'));
    }

    #[Test]
    public function it_consults_wholesaler_via_n8n_route(): void
    {
        app(WholesalerCatalogSync::class)->sync();
        $ct = Wholesaler::query()->where('code', 'CT')->firstOrFail();

        $response = $this->postJson("/api/n8n/mayoristas/{$ct->id}/consultar", [
            'partNumber' => 'ACCBLC010',
            'preferredWarehouse' => 'CDMX',
        ]);

        $response->assertOk()
            ->assertJsonPath('wholesalerId', $ct->id)
            ->assertJsonStructure(['offers', 'count']);
    }
}
