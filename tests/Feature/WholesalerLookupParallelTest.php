<?php

namespace Tests\Feature;

use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerCatalogSync;
use App\Services\Wholesalers\WholesalerLookupService;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class WholesalerLookupParallelTest extends AuthenticatedFeatureTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        app(WholesalerCatalogSync::class)->sync();
        Wholesaler::query()->update(['active' => true]);
    }

    #[Test]
    public function parallel_lookup_returns_without_blocking_on_empty_connectors(): void
    {
        $lookup = app(WholesalerLookupService::class);

        $offers = $lookup->lookupByPartNumber('PARALLEL-SKU-001', null, true);

        $this->assertIsArray($offers);
    }

    #[Test]
    public function consultar_endpoint_for_single_wholesaler_responds(): void
    {
        $wholesaler = Wholesaler::query()->where('active', true)->firstOrFail();

        $response = $this->postJson("/api/mayoristas/{$wholesaler->id}/consultar", [
            'partNumber' => 'WD19',
        ]);

        $response->assertOk()
            ->assertJsonPath('wholesalerId', $wholesaler->id)
            ->assertJsonPath('partNumber', 'WD19')
            ->assertJsonStructure(['offers', 'count']);
    }
}
