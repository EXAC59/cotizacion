<?php

namespace App\Services\Wholesalers\Connectors;

use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerOffer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiWholesalerConnector extends AbstractWholesalerConnector
{
    protected function integrationType(): string
    {
        return 'api';
    }

    /**
     * @return list<WholesalerOffer>
     */
    protected function fetchOffers(Wholesaler $wholesaler, string $partNumber): array
    {
        $prefix = (string) ($wholesaler->config_json['env_prefix'] ?? '');
        $baseUrl = rtrim((string) env("{$prefix}_BASE_URL", ''), '/');
        $lookupPath = (string) env("{$prefix}_LOOKUP_PATH", '/products/{part_number}');
        $url = $baseUrl.str_replace('{part_number}', urlencode($partNumber), $lookupPath);
        $sourceIp = trim((string) env("{$prefix}_SOURCE_IP", ''));

        try {
            $response = $this->httpClient($prefix)->get($url);

            if ($response->failed()) {
                Log::warning('Wholesaler API lookup failed', [
                    'wholesaler' => $wholesaler->code,
                    'url' => $url,
                    'source_ip' => $sourceIp !== '' ? $sourceIp : null,
                    'status' => $response->status(),
                ]);

                return [$this->errorOffer($wholesaler, $partNumber, "HTTP {$response->status()}")];
            }

            $data = $response->json();
            if (! is_array($data)) {
                return [$this->errorOffer($wholesaler, $partNumber, 'Respuesta inválida del mayorista')];
            }

            return [$this->mapResponse($wholesaler, $partNumber, $data)];
        } catch (\Throwable $e) {
            Log::warning('Wholesaler API lookup exception', [
                'wholesaler' => $wholesaler->code,
                'url' => $url,
                'source_ip' => $sourceIp !== '' ? $sourceIp : null,
                'error' => $e->getMessage(),
            ]);

            return [$this->errorOffer($wholesaler, $partNumber, $e->getMessage())];
        }
    }

    /**
     * Cliente HTTP configurado por prefijo de env (WHOLESALER_CT_*, etc.).
     */
    public function httpClient(string $prefix): PendingRequest
    {
        $timeout = (int) env("{$prefix}_TIMEOUT", 15);
        $sourceIp = trim((string) env("{$prefix}_SOURCE_IP", ''));

        $client = Http::timeout(max(1, $timeout))
            ->withHeaders($this->authHeaders($prefix))
            ->acceptJson();

        if ($sourceIp !== '') {
            $client = $client->withOptions([
                'curl' => [
                    CURLOPT_INTERFACE => $sourceIp,
                ],
            ]);
        }

        return $client;
    }

    /**
     * @return array<string, string>
     */
    public function authHeaders(string $prefix): array
    {
        $apiKey = trim((string) env("{$prefix}_API_KEY", ''));
        if ($apiKey === '') {
            return [];
        }

        $authHeader = trim((string) env("{$prefix}_AUTH_HEADER", 'Authorization'));
        if ($authHeader === '' || strcasecmp($authHeader, 'Authorization') === 0) {
            return ['Authorization' => "Bearer {$apiKey}"];
        }

        return [$authHeader => $apiKey];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapResponse(Wholesaler $wholesaler, string $partNumber, array $data): WholesalerOffer
    {
        $payload = $data;
        if (isset($data['data']) && is_array($data['data'])) {
            $payload = $data['data'];
        }
        if (isset($data['product']) && is_array($data['product'])) {
            $payload = $data['product'];
        }
        if (isset($data['products'][0]) && is_array($data['products'][0])) {
            $payload = $data['products'][0];
        }
        if (isset($data['items'][0]) && is_array($data['items'][0])) {
            $payload = $data['items'][0];
        }

        $pricing = isset($payload['pricing']) && is_array($payload['pricing']) ? $payload['pricing'] : [];
        $availability = isset($payload['availability']) && is_array($payload['availability'])
            ? $payload['availability']
            : [];

        $cost = (float) (
            $payload['cost']
            ?? $payload['price']
            ?? $payload['customerPrice']
            ?? $payload['unitPrice']
            ?? $pricing['customerPrice']
            ?? $pricing['price']
            ?? 0
        );

        $stock = (int) (
            $payload['stock']
            ?? $payload['quantity']
            ?? $payload['availableQuantity']
            ?? $availability['availableQuantity']
            ?? $availability['quantity']
            ?? 0
        );

        $warehouse = (string) (
            $payload['warehouse']
            ?? $payload['location']
            ?? $payload['branch']
            ?? $availability['warehouseId']
            ?? ''
        );

        $description = $payload['description']
            ?? $payload['productDescription']
            ?? $payload['title']
            ?? null;

        return new WholesalerOffer(
            wholesalerId: $wholesaler->id,
            wholesalerCode: $wholesaler->code,
            wholesalerName: $wholesaler->name,
            partNumber: $partNumber,
            cost: $cost,
            stock: $stock,
            warehouse: $warehouse,
            leadDays: (int) ($payload['lead_days'] ?? $payload['leadDays'] ?? 0),
            description: is_string($description) ? $description : '',
        );
    }

    private function errorOffer(Wholesaler $wholesaler, string $partNumber, string $message): WholesalerOffer
    {
        return new WholesalerOffer(
            wholesalerId: $wholesaler->id,
            wholesalerCode: $wholesaler->code,
            wholesalerName: $wholesaler->name,
            partNumber: $partNumber,
            cost: 0,
            stock: 0,
            error: $message,
        );
    }
}
