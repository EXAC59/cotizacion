<?php

namespace Database\Seeders;

use App\Services\Wholesalers\WholesalerCatalogSync;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            DashboardUsersSeeder::class,
        ]);

        app(WholesalerCatalogSync::class)->sync();

        $this->call([
            InventoryItemsSeeder::class,
        ]);
    }
}
