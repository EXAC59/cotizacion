<?php

namespace App\Console\Commands;

use App\Services\Wholesalers\WholesalerCatalogSync;
use Illuminate\Console\Command;

class WholesalersSyncCatalogCommand extends Command
{
    protected $signature = 'wholesalers:sync-catalog';

    protected $description = 'Sincroniza el catálogo de mayoristas desde config/wholesalers.php';

    public function handle(WholesalerCatalogSync $sync): int
    {
        $result = $sync->sync();

        $this->info("Catálogo sincronizado: {$result['created']} nuevos, {$result['updated']} actualizados, {$result['total']} total.");

        return self::SUCCESS;
    }
}
