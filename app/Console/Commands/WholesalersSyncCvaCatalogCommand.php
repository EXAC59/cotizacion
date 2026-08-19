<?php

namespace App\Console\Commands;

use App\Services\Wholesalers\CvaCatalogIndex;
use App\Services\Wholesalers\CvaCatalogSyncService;
use App\Services\Wholesalers\LowStock\LowStockPollService;
use Illuminate\Console\Command;

class WholesalersSyncCvaCatalogCommand extends Command
{
    protected $signature = 'wholesalers:sync-cva-catalog
                            {--batch=LG : Tamaño de lote precios_stock (SM|MD|LG|XL)}
                            {--skip-descriptions : Solo precios/stock, sin lista_precios}
                            {--status : Solo muestra estado del índice local}';

    protected $description = 'Sincroniza el catálogo completo de Grupo CVA a Exacto (disco + caché)';

    public function handle(
        CvaCatalogSyncService $sync,
        CvaCatalogIndex $index,
        LowStockPollService $lowStockPoller,
    ): int {

        if ($this->option('status')) {
            $this->table(
                ['Campo', 'Valor'],
                [
                    ['Productos', (string) $index->count()],
                    ['Último sync', $index->syncedAt() ?? '(nunca)'],
                ],
            );

            return self::SUCCESS;
        }

        $withDescriptions = ! (bool) $this->option('skip-descriptions');
        $batch = (string) $this->option('batch');

        $this->info('Sincronizando catálogo CVA…');
        $this->line('Batch: '.$batch.' · Descripciones: '.($withDescriptions ? 'sí (lista_precios)' : 'no'));

        try {
            $result = $sync->sync(
                withDescriptions: $withDescriptions,
                batch: $batch,
                onProgress: function (string $phase, int $page, int $total, int $products): void {
                    $this->output->write(sprintf(
                        "\r[%s] página %d/%d — productos %d    ",
                        $phase,
                        $page,
                        $total,
                        $products,
                    ));
                },
            );
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Productos', (string) $result['products']],
                ['Códigos indexados', (string) $result['codes']],
                ['Páginas stock', (string) $result['pages_stock']],
                ['Páginas lista', (string) $result['pages_lista']],
                ['Synced at', $result['synced_at']],
            ],
        );
        $this->info('Catálogo CVA sincronizado.');

        try {
            $poll = $lowStockPoller->poll(['CVA']);
            $saved = $poll['wholesalers'][0]['saved'] ?? 0;
            $this->info("Snapshot stock bajo CVA actualizado ({$saved} productos).");
        } catch (\Throwable $e) {
            $this->warn('No se pudo refrescar snapshot de stock bajo CVA: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
