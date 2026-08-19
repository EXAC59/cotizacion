<?php

namespace Database\Seeders;

use App\Models\Wholesaler;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventoryItemsSeeder extends Seeder
{
    /**
     * Inventario de ejemplo para alertas de stock bajo en el dashboard.
     *
     * @var list<array{part_number: string, product_name: string, stock: int, warehouse: string, wholesaler_code?: string}>
     */
    private const ITEMS = [
        ['part_number' => 'C9200L-24T-4G-E', 'product_name' => 'Switch Cisco C9200', 'stock' => 2, 'warehouse' => 'CDMX', 'wholesaler_code' => 'INGRAM'],
        ['part_number' => 'KVR16N11S8/4', 'product_name' => 'Memoria Kingston 4GB', 'stock' => 3, 'warehouse' => 'MTY', 'wholesaler_code' => 'EXEL'],
        ['part_number' => 'U6-PRO', 'product_name' => 'Access Point Ubiquiti U6 Pro', 'stock' => 1, 'warehouse' => 'GDL', 'wholesaler_code' => 'CT'],
        ['part_number' => 'MR36-HW', 'product_name' => 'Cisco Meraki MR36', 'stock' => 4, 'warehouse' => 'CDMX', 'wholesaler_code' => 'INGRAM'],
        ['part_number' => 'DS224+', 'product_name' => 'NAS Synology DS224+', 'stock' => 8, 'warehouse' => 'QRO', 'wholesaler_code' => 'EXEL'],
        ['part_number' => 'RT-AX88U', 'product_name' => 'Router ASUS RT-AX88U', 'stock' => 0, 'warehouse' => 'PUE', 'wholesaler_code' => 'CT'],
        ['part_number' => 'WD80EFBX', 'product_name' => 'Disco WD Red 8TB', 'stock' => 2, 'warehouse' => 'MTY', 'wholesaler_code' => 'INGRAM'],
        ['part_number' => 'TL-SG1024', 'product_name' => 'Switch TP-Link 24 puertos', 'stock' => 12, 'warehouse' => 'CDMX', 'wholesaler_code' => 'EXEL'],
        ['part_number' => 'HPE-5130-24G', 'product_name' => 'Switch HPE 5130', 'stock' => 1, 'warehouse' => 'GDL', 'wholesaler_code' => 'INGRAM'],
        ['part_number' => 'LOG-MX-KEYS', 'product_name' => 'Teclado Logitech MX Keys', 'stock' => 5, 'warehouse' => 'CDMX', 'wholesaler_code' => 'CT'],
    ];

    public function run(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('inventory_items')) {
            return;
        }

        $wholesalers = Wholesaler::query()->pluck('id', 'code');

        foreach (self::ITEMS as $item) {
            $code = strtoupper((string) ($item['wholesaler_code'] ?? ''));
            $wholesalerId = $code !== '' ? ($wholesalers[$code] ?? null) : null;

            DB::table('inventory_items')->updateOrInsert(
                [
                    'part_number' => $item['part_number'],
                    'warehouse' => $item['warehouse'],
                ],
                [
                    'product_name' => $item['product_name'],
                    'stock' => $item['stock'],
                    'wholesaler_id' => $wholesalerId,
                    'updated_at' => now(),
                ],
            );
        }
    }
}
