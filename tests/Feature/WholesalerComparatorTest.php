<?php

namespace Tests\Feature;

use App\Models\Wholesaler;
use App\Services\Wholesalers\ComparatorSettingsService;
use App\Services\Wholesalers\WholesalerCatalogSync;
use App\Services\Wholesalers\WholesalerComparatorService;
use App\Services\Wholesalers\WholesalerDemoOfferService;
use App\Services\Wholesalers\WholesalerLookupService;
use App\Services\Wholesalers\WholesalerPerformanceService;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class WholesalerComparatorTest extends AuthenticatedFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(WholesalerCatalogSync::class)->sync();
        Wholesaler::query()->update(['active' => true]);
    }

    #[Test]
    public function it_ranks_offers_and_selects_best_by_price_stock_and_warehouse(): void
    {
        $response = $this->postJson('/api/mayoristas/comparar', [
            'partNumber' => 'C9200L-24T-4G-E',
            'quantity' => 2,
            'preferredWarehouse' => 'CDMX',
        ]);

        $response->assertOk()
            ->assertJsonPath('partNumber', 'C9200L-24T-4G-E')
            ->assertJsonStructure(['offers', 'best', 'demoMode']);

        $offers = $response->json('offers');
        $this->assertNotEmpty($offers);
        $this->assertTrue($offers[0]['isBest']);
        $this->assertSame(1, $offers[0]['rank']);
        $this->assertTrue($response->json('demoMode'));
    }

    #[Test]
    public function it_compares_batch_of_lines(): void
    {
        $response = $this->postJson('/api/mayoristas/comparar-lote', [
            'preferredWarehouse' => 'MTY',
            'lines' => [
                ['partNumber' => 'WD19', 'quantity' => 1],
                ['partNumber' => 'U6-PLUS', 'quantity' => 3],
            ],
        ]);

        $response->assertOk()->assertJsonPath('count', 2);
        $this->assertCount(2, $response->json('results'));
    }

    #[Test]
    public function comparator_service_prefers_cheaper_offer_with_stock(): void
    {
        $comparator = app(WholesalerComparatorService::class);

        $result = $comparator->compare('TEST-SKU', 5, 'CDMX');

        $best = $result['best'];
        $this->assertNotNull($best);
        $this->assertTrue($best['isBest']);
        $this->assertNotEmpty($result['offers']);
    }

    #[Test]
    public function lowercase_sku_keeps_an_available_offer_when_another_wholesaler_has_no_match(): void
    {
        $lookup = $this->mock(WholesalerLookupService::class);
        $lookup->shouldReceive('lookupByPartNumber')
            ->once()
            ->with('CZ103AL', null, true, null)
            ->andReturn([
                [
                    'wholesalerId' => 'ct-id',
                    'wholesalerCode' => 'CT',
                    'wholesalerName' => 'CT Internacional',
                    'partNumber' => 'CZ103AL',
                    'cost' => 174.56,
                    'stock' => 33,
                    'warehouse' => 'CEDIS',
                    'leadDays' => 0,
                    'error' => null,
                ],
                [
                    'wholesalerId' => 'cva-id',
                    'wholesalerCode' => 'CVA',
                    'wholesalerName' => 'Grupo CVA',
                    'partNumber' => 'CZ103AL',
                    'cost' => 0,
                    'stock' => 0,
                    'warehouse' => '',
                    'leadDays' => 0,
                    'error' => 'Sin resultado',
                ],
            ]);

        $comparator = new WholesalerComparatorService(
            $lookup,
            $this->mock(WholesalerDemoOfferService::class),
            $this->mock(ComparatorSettingsService::class),
            $this->mock(WholesalerPerformanceService::class),
        );

        $result = $comparator->compare('  cz103al  ', 1);

        $this->assertSame('CZ103AL', $result['partNumber']);
        $this->assertCount(1, $result['offers']);
        $this->assertSame('CT', $result['offers'][0]['wholesalerCode']);
        $this->assertSame(33, $result['offers'][0]['stock']);
        $this->assertContains('CVA', array_column($result['notFound'], 'wholesalerCode'));
        $this->assertNull($result['lookupError']);
    }

    #[Test]
    public function supplier_reference_never_replaces_the_requested_sku(): void
    {
        $lookup = $this->mock(WholesalerLookupService::class);
        $lookup->shouldReceive('lookupByPartNumber')
            ->once()
            ->with('T664320', null, true, null)
            ->andReturn([
                [
                    'wholesalerId' => 'ct-id',
                    'wholesalerCode' => 'CT',
                    'wholesalerName' => 'CT Internacional',
                    'partNumber' => 'T664320AL',
                    'cost' => 159.30,
                    'stock' => 106,
                    'warehouse' => 'CEDIS Monterrey (53A)',
                    'leadDays' => 0,
                    'error' => null,
                ],
            ]);

        $comparator = new WholesalerComparatorService(
            $lookup,
            $this->mock(WholesalerDemoOfferService::class),
            $this->mock(ComparatorSettingsService::class),
            $this->mock(WholesalerPerformanceService::class),
        );

        $result = $comparator->compare('T664320', 1);

        $this->assertSame('T664320', $result['offers'][0]['partNumber']);
        $this->assertSame('T664320AL', $result['offers'][0]['supplierPartNumber']);
    }

    #[Test]
    public function ranked_offers_include_v2_fields(): void
    {
        $comparator = app(WholesalerComparatorService::class);

        $result = $comparator->compare('C9200L-24T-4G-E', 2, 'CDMX');
        $first = $result['offers'][0] ?? null;

        $this->assertNotNull($first);
        $this->assertArrayHasKey('unitCost', $first);
        $this->assertArrayHasKey('availabilityType', $first);
        $this->assertArrayHasKey('performanceScore', $first);
        $this->assertContains($first['availabilityType'], ['local', 'import']);
    }

    #[Test]
    public function it_ranks_by_cost_then_stock_skipping_zero_stock(): void
    {
        $comparator = app(WholesalerComparatorService::class);

        $ranked = $comparator->rankRawOffers([
            [
                'wholesalerId' => 'wh-ct',
                'wholesalerCode' => 'CT',
                'wholesalerName' => 'CT Internacional',
                'partNumber' => 'T664320',
                'cost' => 100,
                'stock' => 0,
                'warehouse' => 'CEDIS Monterrey (53A)',
                'leadDays' => 0,
                'availabilityType' => 'local',
            ],
            [
                'wholesalerId' => 'wh-cva',
                'wholesalerCode' => 'CVA',
                'wholesalerName' => 'Grupo CVA',
                'partNumber' => 'T664320',
                'cost' => 105,
                'stock' => 150,
                'warehouse' => 'CEDIS CDMX Centro Sur',
                'leadDays' => 0,
                'availabilityType' => 'local',
            ],
            [
                'wholesalerId' => 'wh-ingram',
                'wholesalerCode' => 'INGRAM',
                'wholesalerName' => 'Ingram Micro',
                'partNumber' => 'T664320',
                'cost' => 105,
                'stock' => 88,
                'warehouse' => 'CDMX',
                'leadDays' => 0,
                'availabilityType' => 'local',
            ],
        ], 1, null);

        $this->assertCount(2, $ranked);
        $best = $ranked[0]->toArray();
        $second = $ranked[1]->toArray();

        $this->assertSame('CVA', $best['wholesalerCode']);
        $this->assertTrue($best['isBest']);
        $this->assertSame(1, $best['rank']);
        $this->assertSame(150, $best['stock']);

        $this->assertSame('INGRAM', $second['wholesalerCode']);
        $this->assertFalse($second['isBest']);
        $this->assertSame(2, $second['rank']);
    }

    #[Test]
    public function it_breaks_cost_ties_by_higher_stock(): void
    {
        $comparator = app(WholesalerComparatorService::class);

        $ranked = $comparator->rankRawOffers([
            [
                'wholesalerId' => 'wh-a',
                'wholesalerCode' => 'A',
                'wholesalerName' => 'A',
                'partNumber' => 'SKU',
                'cost' => 50,
                'stock' => 10,
                'warehouse' => 'X',
                'leadDays' => 0,
                'availabilityType' => 'local',
            ],
            [
                'wholesalerId' => 'wh-b',
                'wholesalerCode' => 'B',
                'wholesalerName' => 'B',
                'partNumber' => 'SKU',
                'cost' => 50,
                'stock' => 40,
                'warehouse' => 'Y',
                'leadDays' => 0,
                'availabilityType' => 'local',
            ],
        ], 1, null);

        $this->assertSame('B', $ranked[0]->toArray()['wholesalerCode']);
        $this->assertTrue($ranked[0]->toArray()['isBest']);
        $this->assertSame('A', $ranked[1]->toArray()['wholesalerCode']);
    }

    #[Test]
    public function it_excludes_offers_with_stock_below_quantity(): void
    {
        $comparator = app(WholesalerComparatorService::class);

        $ranked = $comparator->rankRawOffers([
            [
                'wholesalerId' => 'wh-cheap',
                'wholesalerCode' => 'CHEAP',
                'wholesalerName' => 'Barato',
                'partNumber' => 'SKU',
                'cost' => 10,
                'stock' => 2,
                'warehouse' => 'X',
                'leadDays' => 0,
                'availabilityType' => 'local',
            ],
            [
                'wholesalerId' => 'wh-ok',
                'wholesalerCode' => 'OK',
                'wholesalerName' => 'Suficiente',
                'partNumber' => 'SKU',
                'cost' => 99,
                'stock' => 20,
                'warehouse' => 'Y',
                'leadDays' => 0,
                'availabilityType' => 'local',
            ],
        ], 5, null);

        $this->assertCount(1, $ranked);
        $best = $ranked[0]->toArray();
        $this->assertSame('OK', $best['wholesalerCode']);
        $this->assertTrue($best['isBest']);
    }

    #[Test]
    public function import_penalty_applies_when_local_stock_exists(): void
    {
        $comparator = app(WholesalerComparatorService::class);

        // Mismo costo/stock: gana menor leadDays (local 0d vs import 5d).
        $ranked = $comparator->rankRawOffers([
            [
                'wholesalerId' => 'wh-local',
                'wholesalerCode' => 'LOC',
                'wholesalerName' => 'Local',
                'partNumber' => 'SKU-1',
                'cost' => 1000,
                'stock' => 10,
                'warehouse' => 'CDMX',
                'leadDays' => 0,
                'availabilityType' => 'local',
            ],
            [
                'wholesalerId' => 'wh-import',
                'wholesalerCode' => 'IMP',
                'wholesalerName' => 'Import',
                'partNumber' => 'SKU-1',
                'cost' => 1000,
                'stock' => 10,
                'warehouse' => 'CDMX',
                'leadDays' => 5,
                'availabilityType' => 'import',
            ],
        ], 2, 'CDMX');

        $this->assertNotEmpty($ranked);
        $best = $ranked[0]->toArray();
        $this->assertSame('local', $best['availabilityType']);
    }
}
