<?php

namespace App\Console\Commands;

use App\Services\DoclingClient;
use App\Services\N8nClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class N8nVerifyLecturaCommand extends Command
{
    protected $signature = 'n8n:verify-lectura';

    protected $description = 'Verifica configuración n8n, Docling y webhook de lectura';

    public function handle(DoclingClient $docling, N8nClient $n8n): int
    {
        $webhookUrl = config('n8n.webhook_url');
        $baseUrl = config('n8n.base_url');

        $this->info('N8N_BASE_URL: '.($baseUrl ?: '(vacío)'));
        $this->info('N8N_WEBHOOK_URL: '.($webhookUrl ?: '(vacío)'));

        if (! $n8n->isConfigured()) {
            $this->error('N8N_WEBHOOK_URL no configurado en .env');

            return self::FAILURE;
        }

        $doclingPing = $docling->ping();
        $this->line('Docling: '.($doclingPing['ok'] ? 'OK' : 'NO RESPONDE'));
        if (! $doclingPing['ok']) {
            $this->warn($doclingPing['error'] ?? 'Levanta Docling con docker compose -f docker-compose.lectura.yml up -d');
        }

        try {
            $health = Http::timeout(10)->get(rtrim((string) $baseUrl, '/').'/healthz');
            if (! $health->successful()) {
                $this->warn('n8n healthz no respondió OK — comprueba http://localhost:5678');
            }
        } catch (\Throwable) {
            $this->warn('No se pudo contactar n8n en '.$baseUrl);
        }

        try {
            $response = Http::timeout(15)->post($webhookUrl, [
                'ping' => true,
                'source' => 'artisan-verify',
            ]);
            $status = $response->status();
            if ($status === 404) {
                $this->error('Webhook 404 — importa y activa docs/n8n/lectura-cotizacion.workflow.json en n8n');

                return self::FAILURE;
            }
            if ($status >= 500) {
                $this->warn("Webhook registrado pero el flujo devolvió HTTP {$status}.");
            } else {
                $this->info("Webhook accesible (HTTP {$status})");
                $this->line('  (Sin archivo: en Executions verás error en Docling — ignóralo; prueba subiendo un Excel/PDF real.)');
            }
        } catch (\Throwable $e) {
            $this->error('No se pudo contactar el webhook: '.$e->getMessage());
            $this->line('Comprueba que n8n esté en ejecución (http://localhost:5678)');

            return self::FAILURE;
        }

        $this->info('Verificación n8n lectura OK');

        return self::SUCCESS;
    }
}
