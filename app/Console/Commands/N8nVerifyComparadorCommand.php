<?php

namespace App\Console\Commands;

use App\Models\ComparisonJob;
use App\Services\N8nClient;
use App\Services\Wholesalers\ComparatorJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class N8nVerifyComparadorCommand extends Command
{
    protected $signature = 'n8n:verify-comparador';

    protected $description = 'Verifica webhook n8n del comparador de precios y flujo end-to-end';

    public function handle(N8nClient $n8n, ComparatorJobService $jobs): int
    {
        $webhookUrl = config('n8n.comparator_webhook_url');
        $baseUrl = config('n8n.base_url');

        $this->info('N8N_COMPARATOR_WEBHOOK_URL: '.($webhookUrl ?: '(vacío)'));

        if (! $n8n->isComparatorConfigured()) {
            $this->error('N8N_COMPARATOR_WEBHOOK_URL no configurado en .env');

            return self::FAILURE;
        }

        try {
            $health = Http::timeout(10)->get(rtrim((string) $baseUrl, '/').'/healthz');
            if (! $health->successful()) {
                $this->warn('n8n healthz no respondió OK');
            }
        } catch (\Throwable) {
            $this->warn('No se pudo contactar n8n en '.$baseUrl);
        }

        try {
            $response = Http::timeout(15)->post($webhookUrl, [
                'ping' => true,
                'job_id' => '00000000-0000-4000-8000-000000000099',
                'part_number' => 'VERIFY-SKU',
                'quantity' => 1,
                'preferred_warehouse' => 'CDMX',
            ]);
            $status = $response->status();
            if ($status === 404) {
                $this->error('Webhook 404 — importa docs/n8n/comparador-precios.workflow.json');

                return self::FAILURE;
            }
            $this->info("Webhook accesible (HTTP {$status})");
        } catch (\Throwable $e) {
            $this->error('No se pudo contactar el webhook: '.$e->getMessage());

            return self::FAILURE;
        }

        config(['n8n.comparator_webhook_url' => $webhookUrl]);

        $job = $jobs->dispatch('C9200L-24T-4G-E', 1, 'CDMX', ['source' => 'artisan-verify']);

        $deadline = time() + (int) config('n8n.comparator_poll_timeout', 60);
        while (time() < $deadline) {
            $fresh = ComparisonJob::query()->find($job->id);
            if ($fresh && $fresh->status === ComparisonJob::STATUS_DONE) {
                $this->info('Job completado: '.$fresh->id);
                $this->line('  Ofertas: '.count($fresh->offers ?? []));
                $this->line('  Mejor: '.($fresh->best['wholesalerName'] ?? '—'));
                $this->info('Verificación comparador n8n OK');

                return self::SUCCESS;
            }
            if ($fresh && $fresh->status === ComparisonJob::STATUS_ERROR) {
                $this->error('Job falló: '.$fresh->error_message);

                return self::FAILURE;
            }
            sleep(2);
        }

        $this->warn('Timeout esperando callback — revisa Executions en n8n');

        return self::FAILURE;
    }
}
