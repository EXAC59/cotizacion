<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Wholesaler;
use App\Models\WholesalerStockSnapshot;
use App\Services\Wholesalers\CvaCatalogIndex;
use App\Services\Wholesalers\WholesalerCatalogSync;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class LowStockPollTest extends AuthenticatedFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(WholesalerCatalogSync::class)->sync();
        Wholesaler::query()->update(['active' => true]);
        AppSetting::current()->update(['min_stock_alert' => 5]);
    }

    #[Test]
    public function poll_command_persists_cva_low_stock_snapshots(): void
    {
        app(CvaCatalogIndex::class)->replaceCatalog(
            [
                'LOWSKU1' => 'LOW1',
                'OKSKU1' => 'OK1',
            ],
            [
                'LOW1' => [
                    'nombre' => 'Producto bajo uno',
                    'descripcion' => '',
                    'marca' => 'X',
                    'codigo' => 'LOW-SKU-1',
                    'precio' => 10.0,
                    'stock' => 2,
                    'warehouse' => 'CEDIS CVA',
                ],
                'OK1' => [
                    'nombre' => 'Producto ok',
                    'descripcion' => '',
                    'marca' => 'X',
                    'codigo' => 'OK-SKU-1',
                    'precio' => 10.0,
                    'stock' => 20,
                    'warehouse' => 'CEDIS CVA',
                ],
            ],
            now()->toIso8601String(),
        );

        $exit = Artisan::call('wholesalers:poll-low-stock', ['--only' => 'CVA']);
        $this->assertSame(0, $exit);

        $cvaId = Wholesaler::query()->where('code', 'CVA')->value('id');
        $this->assertNotNull($cvaId);

        $this->assertDatabaseHas('wholesaler_stock_snapshots', [
            'wholesaler_id' => $cvaId,
            'part_number' => 'LOW-SKU-1',
            'stock' => 2,
            'warehouse' => 'CEDIS CVA',
        ]);

        $this->assertDatabaseMissing('wholesaler_stock_snapshots', [
            'wholesaler_id' => $cvaId,
            'part_number' => 'OK-SKU-1',
        ]);

        $response = $this->getJson('/api/mayoristas/stock-bajo')->assertOk();
        $item = collect($response->json('items'))->firstWhere('partNumber', 'LOW-SKU-1');
        $this->assertNotNull($item);
        $this->assertSame('CVA', $item['wholesalerCode']);
    }

    #[Test]
    public function poll_replaces_previous_snapshots_for_wholesaler(): void
    {
        $cva = Wholesaler::query()->where('code', 'CVA')->firstOrFail();
        WholesalerStockSnapshot::query()->create([
            'wholesaler_id' => $cva->id,
            'part_number' => 'OLD-SKU',
            'product_name' => 'Viejo',
            'stock' => 1,
            'warehouse' => 'A',
            'source' => 'test',
            'polled_at' => now(),
        ]);

        app(CvaCatalogIndex::class)->replaceCatalog(
            ['NEWSKU' => 'N1'],
            [
                'N1' => [
                    'nombre' => 'Nuevo bajo',
                    'descripcion' => '',
                    'marca' => 'X',
                    'codigo' => 'NEW-SKU',
                    'precio' => 1.0,
                    'stock' => 1,
                    'warehouse' => 'B',
                ],
            ],
            now()->toIso8601String(),
        );

        Artisan::call('wholesalers:poll-low-stock', ['--only' => 'CVA']);

        $this->assertDatabaseMissing('wholesaler_stock_snapshots', [
            'wholesaler_id' => $cva->id,
            'part_number' => 'OLD-SKU',
        ]);
        $this->assertDatabaseHas('wholesaler_stock_snapshots', [
            'wholesaler_id' => $cva->id,
            'part_number' => 'NEW-SKU',
            'stock' => 1,
        ]);
    }
}
