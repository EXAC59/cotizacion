<?php

namespace Tests\Unit;

use App\Models\Wholesaler;
use App\Services\Wholesalers\Connectors\CvaConnector;
use App\Services\Wholesalers\CvaCatalogIndex;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CvaConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_maps_precios_stock_with_promo_and_inventory(): void
    {
        config(['cache.default' => 'array']);

        config(['cache.default' => 'array']);

        Http::fake([
            '*/user/login' => Http::response([
                'usuario' => ['id_cliente' => '1', 'clave_cliente' => '37274', 'usuario' => 'admin37274'],
                'token' => 'test-token-cva',
            ], 200),
            '*/precios_stock_ofertas*' => Http::response([
                'id' => '10470918',
                'clave' => 'PC-6814',
                'codigo' => 'SB5-TEST',
                'precio' => 300.0,
                'moneda' => 'Pesos',
                'promocion' => [
                    'precio' => 280.0,
                    'moneda' => 'Pesos',
                    'descripcion' => 'PROMO',
                ],
                'inventario' => [
                    ['clave' => 46, 'nombre' => 'CENTRO DE DISTRIBUCION GUADALAJARA', 'disponible' => 12],
                    ['nombre' => 'TOTAL', 'disponible' => 12],
                ],
            ], 200),
            '*/lista_precios*' => Http::response([
                'clave' => 'PC-6814',
                'codigo_fabricante' => 'SB5-TEST',
                'descripcion' => 'Servidor de prueba con descripción detallada',
                'marca' => 'Marca prueba',
            ], 200),
        ]);

        $wholesaler = new Wholesaler([
            'code' => 'CVA',
            'name' => 'Grupo CVA',
            'integration' => 'api',
            'active' => true,
            'config_json' => ['env_prefix' => 'WHOLESALER_CVA'],
        ]);
        $wholesaler->id = '00000000-0000-0000-0000-0000000000c1';

        // env() en tests lee $_ENV / $_SERVER
        $_SERVER['WHOLESALER_CVA_USER'] = 'admin37274';
        $_SERVER['WHOLESALER_CVA_PASSWORD'] = 'secret';
        $_SERVER['WHOLESALER_CVA_BASE_URL'] = 'https://apicvaservices.grupocva.com/api/v2';
        $_ENV['WHOLESALER_CVA_USER'] = 'admin37274';
        $_ENV['WHOLESALER_CVA_PASSWORD'] = 'secret';
        $_ENV['WHOLESALER_CVA_BASE_URL'] = 'https://apicvaservices.grupocva.com/api/v2';
        putenv('WHOLESALER_CVA_USER=admin37274');
        putenv('WHOLESALER_CVA_PASSWORD=secret');
        putenv('WHOLESALER_CVA_BASE_URL=https://apicvaservices.grupocva.com/api/v2');

        $this->assertTrue($wholesaler->isConfigured());

        $connector = new CvaConnector;
        $this->assertTrue($connector->supports($wholesaler));

        $offers = $connector->lookup($wholesaler, 'PC-6814');
        $this->assertCount(1, $offers);
        $offer = $offers[0];
        $this->assertNull($offer->error, $offer->error ?? '');
        $this->assertSame(280.0, $offer->cost);
        $this->assertSame(12, $offer->stock);
        $this->assertTrue(str_contains(strtoupper($offer->warehouse), 'GUADALAJARA'), "Expected warehouse to contain 'GUADALAJARA' (case-insensitive)");
        $this->assertSame('SB5-TEST', $offer->partNumber);
        $this->assertSame('Servidor de prueba con descripción detallada', $offer->description);
    }

    public function test_falls_back_to_codigo_when_clave_empty(): void
    {
        config(['cache.default' => 'array']);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/user/login')) {
                return Http::response(['token' => 'tok'], 200);
            }
            if (str_contains($url, 'clave=')) {
                return Http::response(['message' => 'No se encontraron productos.'], 404);
            }
            if (str_contains($url, 'codigo=')) {
                return Http::response([
                    'clave' => 'CV-1856',
                    'codigo' => '6QN28A',
                    'precio' => 1500.5,
                    'moneda' => 'Pesos',
                    'inventario' => [
                        ['nombre' => 'VENTAS GUADALAJARA', 'disponible' => 3],
                        ['nombre' => 'TOTAL', 'disponible' => 3],
                    ],
                ], 200);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $wholesaler = new Wholesaler([
            'code' => 'CVA',
            'name' => 'Grupo CVA',
            'integration' => 'api',
            'active' => true,
            'config_json' => ['env_prefix' => 'WHOLESALER_CVA'],
        ]);
        $wholesaler->id = '00000000-0000-0000-0000-0000000000c2';

        $_SERVER['WHOLESALER_CVA_USER'] = 'admin37274';
        $_SERVER['WHOLESALER_CVA_PASSWORD'] = 'secret';
        $_ENV['WHOLESALER_CVA_USER'] = 'admin37274';
        $_ENV['WHOLESALER_CVA_PASSWORD'] = 'secret';
        putenv('WHOLESALER_CVA_USER=admin37274');
        putenv('WHOLESALER_CVA_PASSWORD=secret');

        $offers = (new CvaConnector)->lookup($wholesaler, '6QN28A');
        $this->assertNull($offers[0]->error, $offers[0]->error ?? '');
        $this->assertSame(1500.5, $offers[0]->cost);
        $this->assertSame(3, $offers[0]->stock);
    }

    public function test_rejects_stock_from_a_different_api_product(): void
    {
        config(['cache.default' => 'array']);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/user/login')) {
                return Http::response(['token' => 'tok'], 200);
            }

            return Http::response([
                'clave' => 'CN-OTRO',
                'codigo' => 'CZ104AL',
                'precio' => 100,
                'moneda' => 'Pesos',
                'inventario' => [
                    ['nombre' => 'TOTAL', 'disponible' => 40],
                ],
            ], 200);
        });

        $wholesaler = new Wholesaler([
            'code' => 'CVA',
            'name' => 'Grupo CVA',
            'integration' => 'api',
            'active' => true,
            'config_json' => ['env_prefix' => 'WHOLESALER_CVA'],
        ]);
        $wholesaler->id = '00000000-0000-0000-0000-0000000000c3';

        $_SERVER['WHOLESALER_CVA_USER'] = 'admin37274';
        $_SERVER['WHOLESALER_CVA_PASSWORD'] = 'secret';
        $_ENV['WHOLESALER_CVA_USER'] = 'admin37274';
        $_ENV['WHOLESALER_CVA_PASSWORD'] = 'secret';
        putenv('WHOLESALER_CVA_USER=admin37274');
        putenv('WHOLESALER_CVA_PASSWORD=secret');

        $catalog = $this->createMock(CvaCatalogIndex::class);
        $catalog->method('candidateClaves')->willReturn([]);
        $offer = (new CvaConnector($catalog))->lookup($wholesaler, 'CZ103AL')[0];

        $this->assertNotNull($offer->error);
        $this->assertSame(0.0, $offer->cost);
        $this->assertSame(0, $offer->stock);
    }

    public function test_uses_official_description_search_before_reporting_not_found(): void
    {
        config(['cache.default' => 'array']);

        Http::fake(function (Request $request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_contains($url, '/user/login')) {
                return Http::response(['token' => 'tok'], 200);
            }

            if (str_contains($url, '/catalogo_clientes/lista_precios') && ($query['desc'] ?? '') === 'SKU-ABC') {
                return Http::response([
                    'articulos' => [[
                        'clave' => 'CVA-100',
                        'codigo_fabricante' => 'SKU-ABC',
                        'descripcion' => 'Producto oficial encontrado por descripción',
                    ]],
                ], 200);
            }

            if (str_contains($url, '/catalogo_clientes/precios_stock_ofertas') && ($query['clave'] ?? '') === 'CVA-100') {
                return Http::response([
                    'clave' => 'CVA-100',
                    'codigo' => 'SKU-ABC',
                    'precio' => 250.50,
                    'moneda' => 'Pesos',
                    'inventario' => [
                        ['nombre' => 'CEDIS CVA', 'disponible' => 7],
                        ['nombre' => 'TOTAL', 'disponible' => 7],
                    ],
                ], 200);
            }

            return Http::response(['message' => 'No se encontraron productos.'], 404);
        });

        $wholesaler = new Wholesaler([
            'code' => 'CVA',
            'name' => 'Grupo CVA',
            'integration' => 'api',
            'active' => true,
            'config_json' => ['env_prefix' => 'WHOLESALER_CVA'],
        ]);
        $wholesaler->id = '00000000-0000-0000-0000-0000000000c4';

        $_SERVER['WHOLESALER_CVA_USER'] = 'admin37274';
        $_SERVER['WHOLESALER_CVA_PASSWORD'] = 'secret';
        $_ENV['WHOLESALER_CVA_USER'] = 'admin37274';
        $_ENV['WHOLESALER_CVA_PASSWORD'] = 'secret';
        putenv('WHOLESALER_CVA_USER=admin37274');
        putenv('WHOLESALER_CVA_PASSWORD=secret');

        $catalog = $this->createMock(CvaCatalogIndex::class);
        $catalog->method('candidateClaves')->willReturn([]);

        $offer = (new CvaConnector($catalog))->lookup($wholesaler, 'sku-abc')[0];

        $this->assertNull($offer->error, $offer->error ?? '');
        $this->assertSame('SKU-ABC', $offer->partNumber);
        $this->assertSame('Producto oficial encontrado por descripción', $offer->description);
        $this->assertSame(250.50, $offer->cost);
        $this->assertSame(7, $offer->stock);
    }

    public function test_official_description_search_rejects_a_different_manufacturer_sku(): void
    {
        config(['cache.default' => 'array']);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/user/login')) {
                return Http::response(['token' => 'tok'], 200);
            }
            if (str_contains($request->url(), '/catalogo_clientes/lista_precios')) {
                return Http::response([
                    'articulos' => [[
                        'clave' => 'CVA-WRONG',
                        'codigo_fabricante' => 'SKU-OTHER',
                        'descripcion' => 'Producto parecido pero incorrecto',
                    ]],
                ], 200);
            }

            return Http::response(['message' => 'No se encontraron productos.'], 404);
        });

        $wholesaler = new Wholesaler([
            'code' => 'CVA',
            'name' => 'Grupo CVA',
            'integration' => 'api',
            'active' => true,
            'config_json' => ['env_prefix' => 'WHOLESALER_CVA'],
        ]);
        $wholesaler->id = '00000000-0000-0000-0000-0000000000c5';

        $_SERVER['WHOLESALER_CVA_USER'] = 'admin37274';
        $_SERVER['WHOLESALER_CVA_PASSWORD'] = 'secret';
        $_ENV['WHOLESALER_CVA_USER'] = 'admin37274';
        $_ENV['WHOLESALER_CVA_PASSWORD'] = 'secret';
        putenv('WHOLESALER_CVA_USER=admin37274');
        putenv('WHOLESALER_CVA_PASSWORD=secret');

        $catalog = $this->createMock(CvaCatalogIndex::class);
        $catalog->method('candidateClaves')->willReturn([]);

        $offer = (new CvaConnector($catalog))->lookup($wholesaler, 'SKU-ABC')[0];

        $this->assertNotNull($offer->error);
        $this->assertSame(0.0, $offer->cost);
        $this->assertSame(0, $offer->stock);
    }
}
