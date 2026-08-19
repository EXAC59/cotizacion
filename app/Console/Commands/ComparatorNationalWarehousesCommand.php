<?php

namespace App\Console\Commands;

use App\Models\ComparatorSetting;
use App\Services\Wholesalers\CtWarehouseDirectory;
use Illuminate\Console\Command;

class ComparatorNationalWarehousesCommand extends Command
{
    protected $signature = 'comparator:national-warehouses
                            {--force : Sobrescribe prioridad aunque ya exista una lista guardada}';

    protected $description = 'Actualiza la prioridad de almacenes a toda la República (catálogo CT)';

    public function handle(): int
    {
        $codes = CtWarehouseDirectory::defaultPriorityCodes();
        $settings = ComparatorSetting::current();
        $current = $settings->warehouse_priority;

        if (is_array($current) && $current !== [] && ! $this->option('force')) {
            $this->warn('Ya hay prioridad guardada ('.count($current).' códigos). Usa --force para reemplazar.');
            $this->line(CtWarehouseDirectory::defaultPriorityCsv());

            return self::SUCCESS;
        }

        $settings->update(['warehouse_priority' => $codes]);
        $this->info('Prioridad nacional aplicada: '.count($codes).' regiones CT.');
        $this->line(CtWarehouseDirectory::defaultPriorityCsv());

        return self::SUCCESS;
    }
}
