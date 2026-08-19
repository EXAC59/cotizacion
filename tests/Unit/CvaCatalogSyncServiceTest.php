<?php

namespace Tests\Unit;

use App\Services\Wholesalers\CvaCatalogSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CvaCatalogSyncServiceTest extends TestCase
{
    public function test_it_rejects_an_incomplete_catalog_before_replacing_the_index(): void
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        foreach (['SERVER', 'ENV'] as $scope) {
            $GLOBALS['_'.$scope]['WHOLESALER_CVA_USER'] = 'test-user';
            $GLOBALS['_'.$scope]['WHOLESALER_CVA_PASSWORD'] = 'test-password';
            $GLOBALS['_'.$scope]['WHOLESALER_CVA_BASE_URL'] = 'https://cva.test/api/v2';
        }
        putenv('WHOLESALER_CVA_USER=test-user');
        putenv('WHOLESALER_CVA_PASSWORD=test-password');
        putenv('WHOLESALER_CVA_BASE_URL=https://cva.test/api/v2');

        Http::fake([
            'https://cva.test/api/v2/user/login' => Http::response(['token' => 'token'], 200),
            'https://cva.test/api/v2/catalogo_clientes/precios_stock_ofertas*' => Http::response([
                'articulos' => [[
                    'clave' => 'ONLY-1',
                    'codigo' => 'SKU-1',
                    'precio' => 10,
                    'inventario' => [],
                ]],
                'paginacion' => ['total_paginas' => 1, 'pagina' => 1],
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sincronización incompleta');

        app(CvaCatalogSyncService::class)->sync(withDescriptions: false);
    }
}
