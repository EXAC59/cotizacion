<?php

namespace Tests\Unit;

use App\Services\Wholesalers\WholesalerSalesAliasService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WholesalerSalesAliasServiceTest extends TestCase
{
    #[Test]
    public function it_maps_fixed_bodega_aliases(): void
    {
        $svc = new WholesalerSalesAliasService;

        $this->assertSame('BODEGA01', $svc->aliasForCode('CT'));
        $this->assertSame('BODEGA02', $svc->aliasForCode('CVA'));
        $this->assertSame('BODEGA03', $svc->aliasForCode('EXEL'));
        $this->assertSame('BODEGA04', $svc->aliasForCode('INGRAM'));
        $this->assertSame('BODEGA05', $svc->aliasForCode('ASC'));
    }

    #[Test]
    public function it_masks_offer_payload_for_sales(): void
    {
        $svc = new WholesalerSalesAliasService;
        $masked = $svc->maskOffer([
            'wholesalerId' => 'uuid-1',
            'wholesalerCode' => 'CT',
            'wholesalerName' => 'CT Internacional',
            'warehouse' => 'Azcapotzalco (35A)',
            'cost' => 10,
        ]);

        $this->assertSame('BODEGA01', $masked['wholesalerName']);
        $this->assertSame('BODEGA01', $masked['wholesalerCode']);
        $this->assertSame('uuid-1', $masked['wholesalerId']);
        $this->assertSame('Almacén CEDIS Azcapotzalco', $masked['warehouse']);
    }

    #[Test]
    public function it_masks_error_messages_citing_wholesaler(): void
    {
        $svc = new WholesalerSalesAliasService;
        $msg = $svc->maskErrorMessage('CT: sin precio/existencia para «X»');

        $this->assertStringNotContainsString('CT:', (string) $msg);
        $this->assertStringContainsString('BODEGA01', (string) $msg);
    }
}
