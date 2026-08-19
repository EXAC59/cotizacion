<?php

namespace App\Console\Commands;

use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerCatalogSync;
use App\Services\Wholesalers\WholesalerLookupService;
use Illuminate\Console\Command;

class WholesalersTestCvaCommand extends Command
{
    protected $signature = 'wholesalers:test-cva {partNumber : Clave CVA o número de parte}';

    protected $description = 'Prueba la integración API de Grupo CVA (login + precios/stock)';

    public function handle(WholesalerCatalogSync $sync, WholesalerLookupService $lookup): int
    {
        $sync->sync();

        $wholesaler = Wholesaler::query()->where('code', 'CVA')->first();

        if ($wholesaler === null) {
            $this->error('No se encontró el mayorista CVA en la base de datos.');

            return self::FAILURE;
        }

        $prefix = (string) ($wholesaler->config_json['env_prefix'] ?? 'WHOLESALER_CVA');
        $baseUrl = env("{$prefix}_BASE_URL");
        $user = env("{$prefix}_USER");

        $this->info("Mayorista: {$wholesaler->name} ({$wholesaler->code})");
        $this->line('Activo: '.($wholesaler->active ? 'sí' : 'no'));
        $this->line('Integración: '.$wholesaler->integration);
        $this->line('Configurado: '.($wholesaler->isConfigured() ? 'sí' : 'no'));
        $this->newLine();
        $this->table(
            ['Variable', 'Valor'],
            [
                ["{$prefix}_BASE_URL", $baseUrl ?: '(default API CVA)'],
                ["{$prefix}_USER", $user ?: '(vacío)'],
                ["{$prefix}_PASSWORD", env("{$prefix}_PASSWORD") ? '********' : '(vacío)'],
                ["{$prefix}_TIMEOUT", (string) env("{$prefix}_TIMEOUT", '20')],
            ],
        );

        if (! $wholesaler->isConfigured()) {
            $this->warn('Completa WHOLESALER_CVA_USER y WHOLESALER_CVA_PASSWORD en .env antes de probar.');

            return self::FAILURE;
        }

        $partNumber = (string) $this->argument('partNumber');
        $this->newLine();
        $this->info("Consultando: {$partNumber}");

        $offers = $lookup->lookupForWholesaler($wholesaler, $partNumber);
        $offer = $offers[0] ?? null;

        if ($offer === null) {
            $this->error('Sin respuesta del conector.');

            return self::FAILURE;
        }

        if (! empty($offer['error'])) {
            $this->error("Error: {$offer['error']}");

            return self::FAILURE;
        }

        $this->table(
            ['Campo', 'Valor'],
            [
                ['Clave/parte', (string) ($offer['partNumber'] ?? '')],
                ['Descripción', ($offer['description'] ?? '') !== '' ? (string) $offer['description'] : '—'],
                ['Costo (MXN)', (string) ($offer['cost'] ?? 0)],
                ['Stock', (string) ($offer['stock'] ?? 0)],
                ['Almacén', ($offer['warehouse'] ?? '') !== '' ? (string) $offer['warehouse'] : '—'],
            ],
        );

        $this->info('Consulta CVA completada correctamente.');

        return self::SUCCESS;
    }
}
