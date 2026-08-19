<?php

namespace App\Services;

use App\Exceptions\N8nWebhookException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class N8nClient
{
    public function isConfigured(): bool
    {
        return (bool) config('n8n.webhook_url');
    }

    public function isComparatorConfigured(): bool
    {
        return (bool) config('n8n.comparator_webhook_url');
    }

    /**
     * Dispara un webhook de n8n para iniciar un flujo de lectura/procesamiento.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function dispararLectura(array $payload): ?array
    {
        $webhookUrl = config('n8n.webhook_url');

        if (! $webhookUrl) {
            Log::warning('n8n no configurado: N8N_WEBHOOK_URL vacío');

            return null;
        }

        try {
            $response = Http::timeout((int) config('n8n.timeout', 30))
                ->acceptJson()
                ->post($webhookUrl, $payload);
        } catch (ConnectionException $e) {
            $this->failConnection($webhookUrl, $e);
        }

        if ($response->failed()) {
            $this->failWebhook($response->status(), $response->json(), $response->body());
        }

        return $response->json();
    }

    /**
     * Dispara el webhook n8n del comparador de precios.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function dispararComparador(
        string $jobId,
        string $partNumber,
        float $quantity,
        ?string $preferredWarehouse = null,
        array $context = [],
    ): ?array {
        $webhookUrl = config('n8n.comparator_webhook_url');

        if (! $webhookUrl) {
            throw new \RuntimeException('N8N_COMPARATOR_WEBHOOK_URL no configurado');
        }

        $demoMode = (bool) config('quote_comparator.demo_offers', false);

        // Respuesta rápida: no bloquear el disparar del SPA si n8n tarda.
        try {
            $response = Http::timeout(3)
                ->acceptJson()
                ->post($webhookUrl, [
                    'job_id' => $jobId,
                    'part_number' => $partNumber,
                    'partNumber' => $partNumber,
                    'quantity' => $quantity,
                    'preferred_warehouse' => $preferredWarehouse,
                    'preferredWarehouse' => $preferredWarehouse,
                    'context' => $context,
                    'demo_mode' => $demoMode,
                    'use_mock' => $demoMode,
                ]);
        } catch (ConnectionException $e) {
            $this->failConnection($webhookUrl, $e);
        }

        if ($response->failed()) {
            $this->failWebhook($response->status(), $response->json(), $response->body());
        }

        return $response->json();
    }

    /**
     * Envía un archivo al webhook de n8n (campo multipart «archivo»).
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    public function dispararLecturaArchivo(string $absolutePath, array $meta = []): ?array
    {
        $webhookUrl = config('n8n.webhook_url');

        if (! $webhookUrl) {
            Log::warning('n8n no configurado: N8N_WEBHOOK_URL vacío');

            return null;
        }

        if ($absolutePath === '' || ! is_file($absolutePath)) {
            throw new N8nWebhookException(500, [
                'message' => 'No se encontró el archivo a enviar a n8n. Vuelve a subir el documento.',
            ]);
        }

        $mime = mime_content_type($absolutePath) ?: 'application/octet-stream';
        $basename = basename($absolutePath);

        // Webhook con responseMode onReceived: responde al instante; Docling corre en segundo plano.
        $url = $webhookUrl;
        if (! empty($meta['request_id'])) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator.'request_id='.urlencode((string) $meta['request_id']);
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->attach('archivo', file_get_contents($absolutePath) ?: '', $basename, ['Content-Type' => $mime])
                ->post($url, $meta);
        } catch (ConnectionException $e) {
            $this->failConnection($url, $e);
        }

        if ($response->failed()) {
            $this->failWebhook($response->status(), $response->json(), $response->body());
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function failWebhook(int $status, ?array $json, string $rawBody): void
    {
        Log::error('Error al enviar archivo a webhook n8n', [
            'status' => $status,
            'body' => $rawBody,
        ]);

        throw new N8nWebhookException($status, $json);
    }

    private function failConnection(string $url, ConnectionException $e): never
    {
        Log::error('n8n no alcanzable', [
            'url' => $url,
            'error' => $e->getMessage(),
        ]);

        throw new N8nWebhookException(503, [
            'message' => 'No se pudo conectar con n8n. Verifica N8N_WEBHOOK_URL (en Docker usa http://n8n:5678/...).',
        ]);
    }

    private function client(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('n8n.base_url'), '/'))
            ->timeout((int) config('n8n.timeout', 30))
            ->acceptJson();

        $apiKey = config('n8n.api_key');

        if ($apiKey) {
            $request->withHeaders([
                'X-N8N-API-KEY' => $apiKey,
            ]);
        }

        return $request;
    }
}
