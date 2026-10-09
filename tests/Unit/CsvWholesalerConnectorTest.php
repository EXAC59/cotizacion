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

        $example = tempnam(sys_get_temp_dir(), 'cotizacion-csv-');
        file_put_contents($example, "sku,cost,stock,warehouse\nDEMO-SKU-001,1250.50,12,CDMX\n");
        $key = 'TEST_CSV_FIXTURE_CSV_PATH';
        $oldEnv = $_ENV[$key] ?? null;
        $oldServer = $_SERVER[$key] ?? null;
        $oldProcess = getenv($key);
        putenv($key.'='.$example);
        $_ENV[$key] = $_SERVER[$key] = $example;

        try {
            $wholesaler = new Wholesaler;
            $wholesaler->forceFill([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'code' => 'EXEL',
                'name' => 'Exel del Norte',
                'integration' => 'csv',
                'active' => true,
                'config_json' => ['env_prefix' => 'TEST_CSV_FIXTURE'],
            ]);
            $wholesaler->syncOriginal();

            $connector = new CsvWholesalerConnector;
            $offers = $connector->lookup($wholesaler, 'DEMO-SKU-001');

            $this->assertCount(1, $offers);
            $this->assertNull($offers[0]->error);
            $this->assertSame(1250.5, $offers[0]->cost);
            $this->assertSame(12, $offers[0]->stock);
            $this->assertSame('CDMX', $offers[0]->warehouse);
        } finally {
            putenv($oldProcess === false ? $key : $key.'='.$oldProcess);
            unset($_ENV[$key], $_SERVER[$key]);
            if ($oldEnv !== null) {
                $_ENV[$key] = $oldEnv;
            }
            if ($oldServer !== null) {
                $_SERVER[$key] = $oldServer;
            }
            unlink($example);
            Cache::flush();
        }
    }
}
