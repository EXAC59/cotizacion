<?php

namespace Tests\Unit;

use App\Models\Wholesaler;
use App\Services\Wholesalers\Connectors\CsvWholesalerConnector;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CsvWholesalerConnectorTest extends TestCase
{
    public function test_reads_local_csv_catalog(): void
    {
        Cache::flush();

        $example = storage_path('app/wholesalers/exel.example.csv');
        $this->assertFileExists($example);

        config()->set('app.env', 'testing');
        putenv('WHOLESALER_EXEL_CSV_PATH=wholesalers/exel.example.csv');
        $_ENV['WHOLESALER_EXEL_CSV_PATH'] = 'wholesalers/exel.example.csv';
        $_SERVER['WHOLESALER_EXEL_CSV_PATH'] = 'wholesalers/exel.example.csv';

        $wholesaler = new Wholesaler;
        $wholesaler->forceFill([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'code' => 'EXEL',
            'name' => 'Exel del Norte',
            'integration' => 'csv',
            'active' => true,
            'config_json' => ['env_prefix' => 'WHOLESALER_EXEL'],
        ]);
        $wholesaler->syncOriginal();

        $connector = new CsvWholesalerConnector;
        $offers = $connector->lookup($wholesaler, 'DEMO-SKU-001');

        $this->assertCount(1, $offers);
        $this->assertNull($offers[0]->error);
        $this->assertSame(1250.5, $offers[0]->cost);
        $this->assertSame(12, $offers[0]->stock);
    }
}
