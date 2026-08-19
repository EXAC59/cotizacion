<?php

namespace Tests\Unit;

use App\Services\Wholesalers\OfferNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OfferNormalizerFreightTest extends TestCase
{
    #[Test]
    public function it_marks_sf_as_no_freight(): void
    {
        $out = OfferNormalizer::enrich([
            'cost' => 100,
            'stock' => 5,
            'tipo_flete' => 'SF',
        ]);

        $this->assertFalse($out['hasFreight']);
        $this->assertSame('Sin flete', $out['freightNote']);
    }

    #[Test]
    public function it_marks_ff_as_with_freight(): void
    {
        $out = OfferNormalizer::enrich([
            'cost' => 100,
            'stock' => 5,
            'tipo_flete' => 'FF',
        ]);

        $this->assertTrue($out['hasFreight']);
        $this->assertSame('Con flete', $out['freightNote']);
    }

    #[Test]
    public function it_leaves_unknown_when_api_has_no_freight_field(): void
    {
        $out = OfferNormalizer::enrich([
            'cost' => 100,
            'stock' => 5,
        ]);

        $this->assertNull($out['hasFreight']);
        $this->assertNull($out['freightNote']);
    }

    #[Test]
    public function it_marks_ct_cedis_without_freight(): void
    {
        $out = OfferNormalizer::enrich([
            'cost' => 100,
            'stock' => 5,
            'wholesalerCode' => 'CT',
            'warehouse' => 'CEDIS Monterrey (53A)',
        ]);

        $this->assertFalse($out['hasFreight']);
        $this->assertSame('Sin flete', $out['freightNote']);
    }

    #[Test]
    public function it_marks_ct_branch_with_freight(): void
    {
        $out = OfferNormalizer::enrich([
            'cost' => 100,
            'stock' => 5,
            'wholesalerCode' => 'CT',
            'warehouse' => 'Hermosillo (01A)',
        ]);

        $this->assertTrue($out['hasFreight']);
        $this->assertSame('Con flete', $out['freightNote']);
    }

    #[Test]
    public function it_marks_ct_azcapotzalco_cedis_without_freight(): void
    {
        $out = OfferNormalizer::enrich([
            'cost' => 100,
            'stock' => 5,
            'wholesalerCode' => 'CT',
            'warehouse' => 'CEDIS Azcapotzalco (35A)',
        ]);

        $this->assertFalse($out['hasFreight']);
        $this->assertSame('Sin flete', $out['freightNote']);
    }

    #[Test]
    public function it_marks_cva_cedis_without_freight(): void
    {
        $out = OfferNormalizer::enrich([
            'cost' => 100,
            'stock' => 5,
            'wholesalerCode' => 'CVA',
            'warehouse' => 'CEDIS Guadalajara',
        ]);

        $this->assertFalse($out['hasFreight']);
        $this->assertSame('Sin flete', $out['freightNote']);
    }

    #[Test]
    public function it_marks_cva_centro_distribucion_mexico_without_freight(): void
    {
        $out = OfferNormalizer::enrich([
            'cost' => 100,
            'stock' => 5,
            'wholesalerCode' => 'CVA',
            'warehouse' => 'CENTRO DE DISTRIBUCION MEXICO',
        ]);

        $this->assertFalse($out['hasFreight']);
        $this->assertSame('Sin flete', $out['freightNote']);
    }

    #[Test]
    public function it_marks_cva_branch_with_freight(): void
    {
        $out = OfferNormalizer::enrich([
            'cost' => 100,
            'stock' => 5,
            'wholesalerCode' => 'CVA',
            'warehouse' => 'Querétaro',
        ]);

        $this->assertTrue($out['hasFreight']);
        $this->assertSame('Con flete', $out['freightNote']);
    }

    #[Test]
    public function it_cedis_overrides_api_freight_flag(): void
    {
        $out = OfferNormalizer::enrich([
            'cost' => 100,
            'stock' => 5,
            'wholesalerCode' => 'CT',
            'warehouse' => 'CEDIS Hermosillo (D2A)',
            'tipo_flete' => 'FF',
        ]);

        $this->assertFalse($out['hasFreight']);
        $this->assertSame('Sin flete', $out['freightNote']);
    }
}
