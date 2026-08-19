<?php

namespace Tests\Unit;

use App\Services\Wholesalers\CtWarehouseDirectory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CtWarehouseDirectoryTest extends TestCase
{
    #[Test]
    public function it_formats_ct_code_as_city_label(): void
    {
        $this->assertSame('Guadalajara (13A)', CtWarehouseDirectory::formatLabel('13A'));
        $this->assertSame('Morelia (14A)', CtWarehouseDirectory::formatLabel('14A'));
        $this->assertSame('CEDIS Azcapotzalco (35A)', CtWarehouseDirectory::formatLabel('35A'));
    }

    #[Test]
    public function it_maps_codes_to_region_for_scoring(): void
    {
        $this->assertSame('GDL', CtWarehouseDirectory::matchKey('13A'));
        $this->assertSame('GDL', CtWarehouseDirectory::matchKey('Guadalajara (13A)'));
        $this->assertSame('CDMX', CtWarehouseDirectory::matchKey('35A'));
        $this->assertSame('MTY', CtWarehouseDirectory::matchKey('24A'));
        $this->assertSame('CMT', CtWarehouseDirectory::matchKey('53A'));
        $this->assertSame('D2A', CtWarehouseDirectory::matchKey('D2A'));
        $this->assertSame('CDMX', CtWarehouseDirectory::matchKey('CDMX'));
    }

    #[Test]
    public function it_formats_cedis_labels(): void
    {
        $this->assertSame('CEDIS Monterrey (53A)', CtWarehouseDirectory::formatLabel('53A'));
        $this->assertSame('CEDIS Hermosillo (D2A)', CtWarehouseDirectory::formatLabel('D2A'));
    }

    #[Test]
    public function it_lists_all_mexican_regions(): void
    {
        $options = CtWarehouseDirectory::regionOptions();
        $this->assertGreaterThanOrEqual(35, count($options));
        $values = array_column($options, 'value');
        $this->assertContains('CDMX', $values);
        $this->assertContains('HMO', $values);
        $this->assertContains('CMT', $values);
        $this->assertContains('D2A', $values);
        $this->assertContains('CUN', $values);
        $this->assertContains('MID', $values);
        $this->assertSame('CMT', $options[0]['value']);
        $this->assertSame('D2A', $options[1]['value']);

        $byValue = array_column($options, 'label', 'value');
        $this->assertStringContainsString('CEDIS Monterrey', $byValue['CMT']);
        $this->assertStringContainsString('CEDIS Hermosillo', $byValue['D2A']);
    }

    #[Test]
    public function it_sums_cedis_stock_separately_from_city_store(): void
    {
        $candidates = [
            ['code' => '24A', 'stock' => 10],
            ['code' => '53A', 'stock' => 40],
            ['code' => '01A', 'stock' => 3],
            ['code' => 'D2A', 'stock' => 12],
        ];

        $this->assertSame(10, CtWarehouseDirectory::stockForPreferredRegion($candidates, 'MTY'));
        $this->assertSame(40, CtWarehouseDirectory::stockForPreferredRegion($candidates, 'CMT'));
        $this->assertSame(3, CtWarehouseDirectory::stockForPreferredRegion($candidates, 'HMO'));
        $this->assertSame(12, CtWarehouseDirectory::stockForPreferredRegion($candidates, 'D2A'));
    }

    #[Test]
    public function it_sums_stock_only_for_preferred_region(): void
    {
        $candidates = [
            ['code' => '35A', 'stock' => 65],
            ['code' => '13A', 'stock' => 10],
            ['code' => '30A', 'stock' => 4],
        ];

        $this->assertSame(65, CtWarehouseDirectory::stockForPreferredRegion($candidates, 'CDMX'));
        $this->assertSame(10, CtWarehouseDirectory::stockForPreferredRegion($candidates, 'GDL'));
        $this->assertSame(4, CtWarehouseDirectory::stockForPreferredRegion($candidates, 'TOL'));
        $this->assertSame(0, CtWarehouseDirectory::stockForPreferredRegion($candidates, 'MTY'));
        $this->assertSame(79, CtWarehouseDirectory::stockForPreferredRegion($candidates, null));
    }

    #[Test]
    public function it_sums_stock_across_multiple_preferred_regions(): void
    {
        $candidates = [
            ['code' => '53A', 'stock' => 40],
            ['code' => 'D2A', 'stock' => 12],
            ['code' => '35A', 'stock' => 65],
        ];

        $this->assertSame(52, CtWarehouseDirectory::stockForPreferredRegion($candidates, ['CMT', 'D2A']));
        $this->assertSame('53A', CtWarehouseDirectory::pickBestCode($candidates, ['CMT', 'D2A']));
        $this->assertSame('D2A', CtWarehouseDirectory::pickBestCode($candidates, ['D2A', 'CMT']));
    }

    #[Test]
    public function it_keeps_preferred_label_when_region_has_zero_stock(): void
    {
        $label = CtWarehouseDirectory::labelForPreferredOrBest('35A', 0, 'D2A');
        $this->assertSame('CEDIS Hermosillo (D2A)', $label);

        $labelCmt = CtWarehouseDirectory::labelForPreferredOrBest('35A', 0, 'CMT');
        $this->assertSame('CEDIS Monterrey (53A)', $labelCmt);

        $withStock = CtWarehouseDirectory::labelForPreferredOrBest('53A', 40, 'CMT');
        $this->assertSame('CEDIS Monterrey (53A)', $withStock);
    }

    #[Test]
    public function it_masks_warehouse_city_for_sales(): void
    {
        $this->assertSame('Almacén CEDIS Azcapotzalco', CtWarehouseDirectory::formatLabelMasked('CEDIS Azcapotzalco (35A)'));
        $this->assertSame('Almacén CEDIS Monterrey', CtWarehouseDirectory::formatLabelMasked('CEDIS Monterrey (53A)'));
        $this->assertSame('Almacén CEDIS Hermosillo', CtWarehouseDirectory::formatLabelMasked('D2A'));
        $this->assertSame('Almacén Hermosillo', CtWarehouseDirectory::formatLabelMasked('01A'));
        $this->assertSame('Monterrey', CtWarehouseDirectory::cityLabel('53A'));
        $this->assertSame('Azcapotzalco', CtWarehouseDirectory::cityLabel('35A'));
        $this->assertTrue(CtWarehouseDirectory::isCedis('35A'));
        $this->assertTrue(CtWarehouseDirectory::isCedis('Azcapotzalco (35A)'));
        $this->assertFalse(CtWarehouseDirectory::isBranch('35A'));
        $this->assertTrue(CtWarehouseDirectory::isBranch('01A'));
    }

    #[Test]
    public function it_sums_stock_only_for_specific_branch_preference(): void
    {
        $candidates = [
            ['code' => '35A', 'stock' => 65],
            ['code' => '40A', 'stock' => 20],
            ['code' => '13A', 'stock' => 10],
        ];

        $this->assertSame(65, CtWarehouseDirectory::stockForPreferredRegion($candidates, '35A'));
        $this->assertSame(85, CtWarehouseDirectory::stockForPreferredRegion($candidates, 'CDMX'));
        $this->assertSame(10, CtWarehouseDirectory::stockForPreferredRegion($candidates, '13A'));
    }

    #[Test]
    public function it_lists_each_ct_branch_separately(): void
    {
        $options = CtWarehouseDirectory::branchOptions();
        $this->assertGreaterThanOrEqual(45, count($options));
        $values = array_column($options, 'value');
        $this->assertContains('35A', $values);
        $this->assertContains('13A', $values);
        $this->assertContains('D2A', $values);
    }
}
