<?php

namespace Tests\Unit;

use App\Services\Wholesalers\CvaWarehouseDirectory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CvaWarehouseDirectoryTest extends TestCase
{
    #[Test]
    public function it_lists_all_cva_branches_from_catalog(): void
    {
        $options = CvaWarehouseDirectory::branchOptions();
        $this->assertCount(25, $options);
        $values = array_column($options, 'value');
        $this->assertContains('CVA-1', $values);
        $this->assertContains('CVA-24', $values);
        $this->assertContains('CVA-46', $values);
        $this->assertContains('CVA-51', $values);
        $this->assertContains('CVA-54', $values);
    }

    #[Test]
    public function it_resolves_inventory_names_to_preference_values(): void
    {
        $this->assertSame('CVA-1', CvaWarehouseDirectory::preferenceValueFromName('GUADALAJARA'));
        $this->assertSame('CVA-46', CvaWarehouseDirectory::preferenceValueFromName('CEDIS Guadalajara'));
        $this->assertSame('CVA-46', CvaWarehouseDirectory::preferenceValueFromName('CEDIS GUADALAJARA'));
        $this->assertSame('CVA-24', CvaWarehouseDirectory::preferenceValueFromName('CDMX'));
        $this->assertSame('CVA-54', CvaWarehouseDirectory::preferenceValueFromName('CEDIS Monterrey'));
    }

    #[Test]
    public function it_matches_preferred_cva_branch(): void
    {
        $this->assertTrue(CvaWarehouseDirectory::matchesPreferred('Guadalajara', ['CVA-1']));
        $this->assertFalse(CvaWarehouseDirectory::matchesPreferred('Guadalajara', ['CVA-24']));
        $this->assertTrue(CvaWarehouseDirectory::matchesPreferred('CEDIS Guadalajara', ['CVA-46']));
    }

    #[Test]
    public function it_masks_with_city_names(): void
    {
        $this->assertSame('Almacén CEDIS Guadalajara', CvaWarehouseDirectory::formatLabelMasked('CEDIS Guadalajara'));
        $this->assertSame('Almacén CEDIS Monterrey', CvaWarehouseDirectory::formatLabelMasked('CVA-54'));
        $this->assertSame('Almacén Querétaro', CvaWarehouseDirectory::formatLabelMasked('CVA-6'));
        $this->assertSame('Guadalajara', CvaWarehouseDirectory::cityLabel('CVA-46'));
        $this->assertSame('Querétaro', CvaWarehouseDirectory::cityLabel('CVA-6'));
    }
}
