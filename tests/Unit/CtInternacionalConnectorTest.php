<?php

namespace Tests\Unit;

use App\Models\Wholesaler;
use App\Services\Wholesalers\Connectors\CtInternacionalConnector;
use App\Services\Wholesalers\CtCatalogIndex;
use App\Services\Wholesalers\CtWarehouseDirectory;
use App\Services\Wholesalers\WholesalerCatalogSync;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class CtInternacionalConnectorTest extends AuthenticatedFeatureTestCase
{
    #[Test]
    public function it_rejects_stock_from_a_different_api_product(): void
    {
        app(WholesalerCatalogSync::class)->sync();

        Http::fake([
            '*/existencia/promociones/*' => Http::response([
                'codigo' => 'CZ104AL',
                'precio' => 99,
                'moneda' => 'MXN',
                'almacenes' => [['35A' => 50]],
            ]),
        ]);

        $this->setEnvVar('WHOLESALER_CT_API_KEY', 'test-token');
        $this->setEnvVar('WHOLESALER_CT_BASE_URL', 'https://api.ctonline.mx:3001');
        $this->setEnvVar('WHOLESALER_CT_LOOKUP_PATH', '/existencia/promociones/{part_number}');
        $this->setEnvVar('WHOLESALER_CT_SOURCE_IP', '');

        $catalog = $this->createMock(CtCatalogIndex::class);
        $catalog->method('candidateClaves')->willReturn([]);

        $wholesaler = Wholesaler::query()->where('code', 'CT')->firstOrFail();
        $offer = (new CtInternacionalConnector($catalog))->lookup($wholesaler, 'CZ103AL')[0];

        $this->assertNotNull($offer->error);
        $this->assertSame(0.0, $offer->cost);
        $this->assertSame(0, $offer->stock);
    }

    #[Test]
    public function it_maps_ct_promociones_response_to_offer_in_mxn(): void
    {
        app(WholesalerCatalogSync::class)->sync();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/existencia/promociones/ACCBLC010')) {
                return Http::response([
                    'codigo' => 'ACCBLC010',
                    'precio' => 10,
                    'moneda' => 'MXN',
                    'almacenes' => [
                        ['14A' => 5, 'promocion' => ['precio' => 8, 'vigente' => ['ini' => '2019-01-01T00:00:00.000Z', 'fin' => '2099-01-01T00:00:00.000Z']]],
                        ['46A' => 3],
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $this->setEnvVar('WHOLESALER_CT_API_KEY', 'test-token');
        $this->setEnvVar('WHOLESALER_CT_BASE_URL', 'https://api.ctonline.mx:3001');
        $this->setEnvVar('WHOLESALER_CT_LOOKUP_PATH', '/existencia/promociones/{part_number}');
        $this->setEnvVar('WHOLESALER_CT_SOURCE_IP', '');

        $catalog = $this->createMock(CtCatalogIndex::class);
        $catalog->method('candidateClaves')->willReturn([]);
        $catalog->method('productName')->willReturn('Cable HDMI demo');

        $wholesaler = Wholesaler::query()->where('code', 'CT')->firstOrFail();

        $connector = new CtInternacionalConnector($catalog);
        $offers = $connector->lookup($wholesaler, 'ACCBLC010');

        $this->assertCount(1, $offers);
        $this->assertSame(8.0, $offers[0]->cost);
        // Sin preferencia: stock del almacén con más existencia (Morelia 5).
        $this->assertSame(5, $offers[0]->stock);
        $this->assertSame('Morelia (14A)', $offers[0]->warehouse);
        $this->assertSame('Cable HDMI demo', $offers[0]->description);
        $this->assertNull($offers[0]->error);
    }

    #[Test]
    public function it_picks_national_best_stock_warehouse_ignoring_preferred(): void
    {
        app(WholesalerCatalogSync::class)->sync();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/existencia/promociones/ACCBLC010')) {
                return Http::response([
                    'codigo' => 'ACCBLC010',
                    'precio' => 10,
                    'moneda' => 'MXN',
                    'almacenes' => [
                        ['13A' => 500],
                        ['21A' => 12, 'promocion' => ['precio' => 9, 'vigente' => ['ini' => '2019-01-01T00:00:00.000Z', 'fin' => '2099-01-01T00:00:00.000Z']]],
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $this->setEnvVar('WHOLESALER_CT_API_KEY', 'test-token');
        $this->setEnvVar('WHOLESALER_CT_BASE_URL', 'https://api.ctonline.mx:3001');
        $this->setEnvVar('WHOLESALER_CT_LOOKUP_PATH', '/existencia/promociones/{part_number}');
        $this->setEnvVar('WHOLESALER_CT_SOURCE_IP', '');

        // Preferido no debe limitar: se elige el almacén con más stock (13A).
        CtWarehouseDirectory::setPreferredForLookup('PUE');
        try {
            $wholesaler = Wholesaler::query()->where('code', 'CT')->firstOrFail();
            $connector = new CtInternacionalConnector;
            $offers = $connector->lookup($wholesaler, 'ACCBLC010');
        } finally {
            CtWarehouseDirectory::setPreferredForLookup(null);
        }

        $this->assertCount(1, $offers);
        $this->assertSame('Guadalajara (13A)', $offers[0]->warehouse);
        $this->assertSame(10.0, $offers[0]->cost);
        $this->assertSame(500, $offers[0]->stock);
    }

    #[Test]
    public function it_retries_with_catalog_clave_when_modelo_returns_empty(): void
    {
        app(WholesalerCatalogSync::class)->sync();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/existencia/promociones/CZ103AL')) {
                return Http::response([
                    'codigo' => 'CZ103AL',
                    'precio' => 0,
                    'moneda' => 'MXN',
                    'almacenes' => [],
                ]);
            }
            if (str_contains($request->url(), '/existencia/promociones/CARHPP2110')) {
                return Http::response([
                    'codigo' => 'CARHPP2110',
                    'precio' => 189.63,
                    'moneda' => 'MXN',
                    'almacenes' => [
                        ['35A' => 161, 'promocion' => ['precio' => 174.56, 'vigente' => ['ini' => '2019-01-01T00:00:00.000Z', 'fin' => '2099-01-01T00:00:00.000Z']]],
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $this->setEnvVar('WHOLESALER_CT_API_KEY', 'test-token');
        $this->setEnvVar('WHOLESALER_CT_BASE_URL', 'https://api.ctonline.mx:3001');
        $this->setEnvVar('WHOLESALER_CT_LOOKUP_PATH', '/existencia/promociones/{part_number}');
        $this->setEnvVar('WHOLESALER_CT_SOURCE_IP', '');

        $catalog = $this->createMock(CtCatalogIndex::class);
        $catalog->method('candidateClaves')->willReturn(['CARHPP2110']);
        $catalog->method('productName')->willReturn('Tinta HP 662, CZ103AL, Negro');

        $wholesaler = Wholesaler::query()->where('code', 'CT')->firstOrFail();
        $connector = new CtInternacionalConnector($catalog);
        $offers = $connector->lookup($wholesaler, 'CZ103AL');

        $this->assertCount(1, $offers);
        $this->assertNull($offers[0]->error);
        $this->assertSame(174.56, $offers[0]->cost);
        $this->assertSame('CZ103AL', $offers[0]->partNumber);
    }

    #[Test]
    public function it_returns_all_successful_offers_when_catalog_has_three_or_more_matches(): void
    {
        app(WholesalerCatalogSync::class)->sync();

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/existencia/promociones/CTAAA001')) {
                return Http::response([
                    'codigo' => 'CTAAA001',
                    'precio' => 10,
                    'moneda' => 'MXN',
                    'almacenes' => [['14A' => 2]],
                ]);
            }
            if (str_contains($url, '/existencia/promociones/CTBBB002')) {
                return Http::response([
                    'codigo' => 'CTBBB002',
                    'precio' => 20,
                    'moneda' => 'MXN',
                    'almacenes' => [['14A' => 3]],
                ]);
            }
            if (str_contains($url, '/existencia/promociones/CTCCC003')) {
                return Http::response([
                    'codigo' => 'CTCCC003',
                    'precio' => 30,
                    'moneda' => 'MXN',
                    'almacenes' => [['14A' => 4]],
                ]);
            }

            return Http::response(['codigo' => 'INCOMPLETE', 'precio' => 0, 'moneda' => 'MXN', 'almacenes' => []], 200);
        });

        $this->setEnvVar('WHOLESALER_CT_API_KEY', 'test-token');
        $this->setEnvVar('WHOLESALER_CT_BASE_URL', 'https://api.ctonline.mx:3001');
        $this->setEnvVar('WHOLESALER_CT_LOOKUP_PATH', '/existencia/promociones/{part_number}');
        $this->setEnvVar('WHOLESALER_CT_SOURCE_IP', '');

        $catalog = $this->createMock(CtCatalogIndex::class);
        $catalog->method('candidateClaves')->willReturn(['CTAAA001', 'CTBBB002', 'CTCCC003']);
        $catalog->method('productName')->willReturnCallback(function (string $key): ?string {
            return match ($key) {
                'CTAAA001' => 'Producto A',
                'CTBBB002' => 'Producto B',
                'CTCCC003' => 'Producto C',
                default => null,
            };
        });

        $wholesaler = Wholesaler::query()->where('code', 'CT')->firstOrFail();
        $connector = new CtInternacionalConnector($catalog);
        $offers = $connector->lookup($wholesaler, 'INCOMPLETE');

        $this->assertCount(3, $offers);
        $this->assertSame(10.0, $offers[0]->cost);
        $this->assertSame(20.0, $offers[1]->cost);
        $this->assertSame(30.0, $offers[2]->cost);
        $this->assertSame('CTAAA001', $offers[0]->partNumber);
        $this->assertSame('CTBBB002', $offers[1]->partNumber);
        $this->assertSame('CTCCC003', $offers[2]->partNumber);
        $this->assertStringContainsString('CTAAA001', $offers[0]->description);
        $this->assertStringContainsString('CTBBB002', $offers[1]->description);
        $this->assertStringContainsString('CTCCC003', $offers[2]->description);
    }

    #[Test]
    public function it_keeps_first_success_when_catalog_has_fewer_than_three_matches(): void
    {
        app(WholesalerCatalogSync::class)->sync();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/existencia/promociones/CTAAA001')) {
                return Http::response([
                    'codigo' => 'CTAAA001',
                    'precio' => 11,
                    'moneda' => 'MXN',
                    'almacenes' => [['14A' => 5]],
                ]);
            }
            if (str_contains($request->url(), '/existencia/promociones/CTBBB002')) {
                return Http::response([
                    'codigo' => 'CTBBB002',
                    'precio' => 22,
                    'moneda' => 'MXN',
                    'almacenes' => [['14A' => 5]],
                ]);
            }

            return Http::response(['codigo' => 'PARTIAL', 'precio' => 0, 'moneda' => 'MXN', 'almacenes' => []]);
        });

        $this->setEnvVar('WHOLESALER_CT_API_KEY', 'test-token');
        $this->setEnvVar('WHOLESALER_CT_BASE_URL', 'https://api.ctonline.mx:3001');
        $this->setEnvVar('WHOLESALER_CT_LOOKUP_PATH', '/existencia/promociones/{part_number}');
        $this->setEnvVar('WHOLESALER_CT_SOURCE_IP', '');

        $catalog = $this->createMock(CtCatalogIndex::class);
        $catalog->method('candidateClaves')->willReturn(['CTAAA001', 'CTBBB002']);
        $catalog->method('productName')->willReturn('Solo primero');

        $wholesaler = Wholesaler::query()->where('code', 'CT')->firstOrFail();
        $connector = new CtInternacionalConnector($catalog);
        $offers = $connector->lookup($wholesaler, 'PARTIAL');

        $this->assertCount(1, $offers);
        $this->assertSame(11.0, $offers[0]->cost);
    }

    #[Test]
    public function it_falls_back_to_national_stock_when_preferred_region_has_none(): void
    {
        app(WholesalerCatalogSync::class)->sync();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/existencia/promociones/ACCBLC010')) {
                return Http::response([
                    'codigo' => 'ACCBLC010',
                    'precio' => 10,
                    'moneda' => 'MXN',
                    'almacenes' => [
                        ['35A' => 65],
                        ['13A' => 10],
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $this->setEnvVar('WHOLESALER_CT_API_KEY', 'test-token');
        $this->setEnvVar('WHOLESALER_CT_BASE_URL', 'https://api.ctonline.mx:3001');
        $this->setEnvVar('WHOLESALER_CT_LOOKUP_PATH', '/existencia/promociones/{part_number}');
        $this->setEnvVar('WHOLESALER_CT_SOURCE_IP', '');

        CtWarehouseDirectory::setPreferredForLookup('TOL');
        try {
            $wholesaler = Wholesaler::query()->where('code', 'CT')->firstOrFail();
            $connector = new CtInternacionalConnector;
            $offers = $connector->lookup($wholesaler, 'ACCBLC010');
        } finally {
            CtWarehouseDirectory::setPreferredForLookup(null);
        }

        $this->assertCount(1, $offers);
        // Preferido Toluca sin stock → ofrecer el mejor almacén nacional (35A).
        $this->assertSame('CEDIS Azcapotzalco (35A)', $offers[0]->warehouse);
        $this->assertSame(65, $offers[0]->stock);
    }

    #[Test]
    public function it_uses_later_preferred_warehouse_when_first_has_no_stock(): void
    {
        app(WholesalerCatalogSync::class)->sync();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/existencia/promociones/ACCBLC010')) {
                return Http::response([
                    'codigo' => 'ACCBLC010',
                    'precio' => 10,
                    'moneda' => 'MXN',
                    'almacenes' => [
                        ['D2A' => 0],
                        ['53A' => 106],
                        ['35A' => 104],
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $this->setEnvVar('WHOLESALER_CT_API_KEY', 'test-token');
        $this->setEnvVar('WHOLESALER_CT_BASE_URL', 'https://api.ctonline.mx:3001');
        $this->setEnvVar('WHOLESALER_CT_LOOKUP_PATH', '/existencia/promociones/{part_number}');
        $this->setEnvVar('WHOLESALER_CT_SOURCE_IP', '');

        CtWarehouseDirectory::setPreferredForLookup(['D2A', '53A', '35A']);
        try {
            $wholesaler = Wholesaler::query()->where('code', 'CT')->firstOrFail();
            $connector = new CtInternacionalConnector;
            $offers = $connector->lookup($wholesaler, 'ACCBLC010');
        } finally {
            CtWarehouseDirectory::setPreferredForLookup(null);
        }

        $this->assertCount(1, $offers);
        $this->assertNull($offers[0]->error);
        $this->assertSame(106, $offers[0]->stock);
        $this->assertSame('CEDIS Monterrey (53A)', $offers[0]->warehouse);
    }

    private function setEnvVar(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
