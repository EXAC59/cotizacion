<?php

namespace App\Console\Commands;

use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerCatalogSync;
use App\Services\Wholesalers\WholesalerLookupService;
use Illuminate\Console\Command;

class WholesalersTestCtCommand extends Command
{
    protected $signature = 'wholesalers:test-ct {partNumber : Número de parte a consultar}';

    protected $description = 'Prueba la integración API de CT Internacional (configuración e IP dedicada)';

    public function handle(WholesalerCatalogSync $sync, WholesalerLookupService $lookup): int
    {
        $sync->sync();

        $wholesaler = Wholesaler::query()->where('code', 'CT')->first();

        if ($wholesaler === null) {
            $this->error('No se encontró el mayorista CT en la base de datos.');

            return self::FAILURE;
        }

        $prefix = (string) ($wholesaler->config_json['env_prefix'] ?? 'WHOLESALER_CT');
        $baseUrl = env("{$prefix}_BASE_URL");
        $apiKey = env("{$prefix}_API_KEY");
        $email = env("{$prefix}_EMAIL");
        $cliente = env("{$prefix}_CLIENTE");
        $rfc = env("{$prefix}_RFC");
        $sourceIp = env("{$prefix}_SOURCE_IP");
        $lookupPath = env("{$prefix}_LOOKUP_PATH", '/existencia/promociones/{part_number}');

        $this->info("Mayorista: {$wholesaler->name} ({$wholesaler->code})");
        $this->line('Activo: '.($wholesaler->active ? 'sí' : 'no'));
        $this->line('Configurado: '.($wholesaler->isConfigured() ? 'sí' : 'no'));
        $this->newLine();
        $this->table(
            ['Variable', 'Valor'],
            [
                ["{$prefix}_BASE_URL", $baseUrl ?: '(vacío)'],
                ["{$prefix}_API_KEY", $apiKey ? '********' : '(vacío)'],
                ["{$prefix}_EMAIL", $email ?: '(vacío)'],
                ["{$prefix}_CLIENTE", $cliente ?: '(vacío)'],
                ["{$prefix}_RFC", $rfc ? '********' : '(vacío)'],
                ["{$prefix}_LOOKUP_PATH", $lookupPath],
                ["{$prefix}_SOURCE_IP", $sourceIp ?: '(vacío — IP VPS en whitelist CT)'],
            ],
        );

        if (! $wholesaler->isConfigured()) {
            $this->warn('Completa WHOLESALER_CT_API_KEY o email/cliente/rfc en .env antes de probar.');

            return self::FAILURE;
        }

        $partNumber = (string) $this->argument('partNumber');
        $this->newLine();
        $this->info("Consultando parte: {$partNumber}");

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
                ['Costo', (string) ($offer['cost'] ?? 0)],
                ['Stock', (string) ($offer['stock'] ?? 0)],
                ['Almacén', ($offer['warehouse'] ?? '') !== '' ? (string) $offer['warehouse'] : '—'],
                ['Días entrega', (string) ($offer['leadDays'] ?? 0)],
            ],
        );

        $this->info('Consulta CT completada correctamente.');

        return self::SUCCESS;
    }
}
