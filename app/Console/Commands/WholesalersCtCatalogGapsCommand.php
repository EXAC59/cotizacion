<?php

namespace App\Console\Commands;

use App\Services\Wholesalers\CtApiCatalogSupplement;
use App\Services\Wholesalers\CtCatalogIndex;
use Illuminate\Console\Command;

class WholesalersCtCatalogGapsCommand extends Command
{
    protected $signature = 'wholesalers:ct-catalog-gaps
        {--sync-upc : Consulta volumetría CT para mapear UPC→clave de productos solo-API}
        {--upc-limit=300 : Máximo de productos solo-API a consultar por UPC}
        {--show=30 : Cuántos códigos solo-API mostrar}';

    protected $description = 'Reporta el hueco FTP vs API de CT y opcionalmente sincroniza UPC de productos faltantes en FTP';

    public function handle(CtCatalogIndex $catalog, CtApiCatalogSupplement $supplement): int
    {
        $catalog->forget();

        if ($this->option('sync-upc')) {
            $this->info('Sincronizando suplemento API (puede tardar)...');
            try {
                $stats = $supplement->sync(true, (int) $this->option('upc-limit'));
                $this->table(
                    ['Métrica', 'Valor'],
                    [
                        ['Solo en API (no FTP)', (string) $stats['apiOnly']],
                        ['UPC mapeados', (string) $stats['upcMapped']],
                        ['Synced at', $stats['syncedAt']],
                    ],
                );
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $snap = $supplement->snapshot();
        $apiOnly = $snap['apiOnly'];
        $show = max(0, (int) $this->option('show'));

        $this->info('Catálogo CT — huecos FTP vs API');
        $this->line('Aliases configurados: '.count($catalog->aliases()));
        $this->line('Índice FTP (claves/modelos): '.count($catalog->index()));
        $this->line('Solo API (cache): '.count($apiOnly));
        $this->line('UPC→clave (cache): '.count($snap['upcMap']));
        $this->line('Último sync: '.($snap['syncedAt'] ?? '(sin sync; usa --sync-upc)'));
        $this->newLine();
        $this->comment('El FTP omiten artículos que sí existen en la web/API (ej. CARHPP2110 / modelo CZ103AL).');
        $this->comment('Modelos OEM sin FTP → config/ct_part_aliases.php o WHOLESALER_CT_PART_ALIASES.');
        $this->comment('Cotizaciones con UPC → se resuelven tras --sync-upc.');

        if ($apiOnly !== [] && $show > 0) {
            $this->newLine();
            $this->info('Ejemplos solo-API (clave CT):');
            foreach (array_slice($apiOnly, 0, $show) as $code) {
                $this->line('  '.$code);
            }
        }

        $upcSamples = array_slice($snap['upcMap'], 0, 10, true);
        if ($upcSamples !== []) {
            $this->newLine();
            $this->info('Ejemplos UPC→clave:');
            foreach ($upcSamples as $upc => $clave) {
                $this->line("  {$upc} → {$clave}");
            }
        }

        return self::SUCCESS;
    }
}
