<?php

namespace Tests\Unit;

use App\Services\Wholesalers\CtApiCatalogSupplement;
use App\Services\Wholesalers\CtCatalogIndex;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CtApiCatalogSupplementTest extends TestCase
{
    #[Test]
    public function it_resolves_clave_from_cached_upc_map(): void
    {
        Cache::put('wholesaler_ct_api_supplement_v1_'.md5((string) env('WHOLESALER_CT_BASE_URL', 'ct')), [
            'apiOnly' => ['CARHPP2110'],
            'upcMap' => ['886112670115' => 'CARHPP2110'],
            'syncedAt' => now()->toIso8601String(),
        ], now()->addHour());

        $supplement = new CtApiCatalogSupplement;
        $this->assertSame('CARHPP2110', $supplement->resolveByUpc('886112670115'));
        $this->assertSame('CARHPP2110', $supplement->resolveByUpc('886-11267-0115'));
        $this->assertNull($supplement->resolveByUpc('123'));
    }

    #[Test]
    public function catalog_index_uses_upc_supplement_when_alias_and_ftp_miss(): void
    {
        config(['ct_part_aliases' => []]);
        Cache::put('wholesaler_ct_api_supplement_v1_'.md5((string) env('WHOLESALER_CT_BASE_URL', 'ct')), [
            'apiOnly' => ['CARHPP2110'],
            'upcMap' => ['886112670115' => 'CARHPP2110'],
            'syncedAt' => now()->toIso8601String(),
        ], now()->addHour());

        $index = new class extends CtCatalogIndex
        {
            public function index(): array
            {
                return [];
            }

            public function aliases(): array
            {
                return [];
            }
        };

        $this->assertSame('CARHPP2110', $index->resolveCtCode('886112670115'));
    }
}
