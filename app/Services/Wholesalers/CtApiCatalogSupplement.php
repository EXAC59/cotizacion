<?php

namespace App\Services\Wholesalers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Complementa el FTP incompleto de CT: productos que sí están en la API
 * (existencia/promociones) pero no en productos.json, indexados por UPC.
 */
class CtApiCatalogSupplement
{
    private const PREFIX = 'WHOLESALER_CT';

    private CtCatalogIndex $catalogIndex;

    public function __construct(?CtCatalogIndex $catalogIndex = null)
    {
        $this->catalogIndex = $catalogIndex ?? new CtCatalogIndex;
    }

    /**
     * @return array{apiOnly: list<string>, upcMap: array<string, string>, syncedAt: string|null}
     */
    public function snapshot(): array
    {
        /** @var array{apiOnly: list<string>, upcMap: array<string, string>, syncedAt: string|null} */
        return Cache::get($this->cacheKey(), [
            'apiOnly' => [],
            'upcMap' => [],
            'syncedAt' => null,
        ]);
    }

    public function resolveByUpc(string $upcOrPart): ?string
    {
        $upc = preg_replace('/\D+/', '', $upcOrPart) ?? '';
        if (strlen($upc) < 8) {
            return null;
        }

        $map = $this->snapshot()['upcMap'];

        return $map[$upc] ?? null;
    }

    /**
     * @return array{apiOnly: int, upcMapped: int, syncedAt: string}
     */
    public function sync(bool $fetchUpc = true, int $upcLimit = 300): array
    {
        $token = $this->resolveToken();
        if ($token === '') {
            throw new \RuntimeException('CT: sin token (configura API_KEY o email/cliente/rfc)');
        }

        $baseUrl = rtrim((string) env(self::PREFIX.'_BASE_URL', 'https://api.ctonline.mx:3001'), '/');
        $http = $this->httpClient()->withHeaders(['x-auth' => $token]);

        $list = $http->get("{$baseUrl}/existencia/promociones")->json();
        $apiCodes = [];
        if (is_array($list)) {
            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $code = strtoupper(trim((string) ($row['codigo'] ?? '')));
                if ($code !== '') {
                    $apiCodes[$code] = true;
                }
            }
        }

        $ftpCodes = [];
        foreach ($this->catalogIndex->index() as $clave) {
            $ftpCodes[strtoupper(trim((string) $clave))] = true;
        }

        $apiOnly = array_values(array_diff(array_keys($apiCodes), array_keys($ftpCodes)));
        sort($apiOnly);

        $upcMap = [];
        if ($fetchUpc) {
            $limit = max(0, $upcLimit);
            foreach (array_slice($apiOnly, 0, $limit) as $code) {
                try {
                    $vol = $http->get("{$baseUrl}/paqueteria/volumetria/{$code}")->json();
                    $upc = $this->extractUpc($vol);
                    if ($upc !== '') {
                        $upcMap[$upc] = $code;
                    }
                } catch (\Throwable $e) {
                    Log::debug('CT volumetria skip', ['code' => $code, 'error' => $e->getMessage()]);
                }
            }
        }

        $payload = [
            'apiOnly' => $apiOnly,
            'upcMap' => $upcMap,
            'syncedAt' => now()->toIso8601String(),
        ];
        Cache::put($this->cacheKey(), $payload, now()->addHours(max(1, (int) env(self::PREFIX.'_SUPPLEMENT_TTL', 12))));

        return [
            'apiOnly' => count($apiOnly),
            'upcMapped' => count($upcMap),
            'syncedAt' => $payload['syncedAt'],
        ];
    }

    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function extractUpc(mixed $vol): string
    {
        if (! is_array($vol)) {
            return '';
        }
        $row = isset($vol[0]) && is_array($vol[0]) ? $vol[0] : $vol;
        $upc = preg_replace('/\D+/', '', (string) ($row['UPC'] ?? $row['upc'] ?? '')) ?? '';

        return strlen($upc) >= 8 ? $upc : '';
    }

    private function resolveToken(): string
    {
        $static = trim((string) env(self::PREFIX.'_API_KEY', ''));
        if ($static !== '') {
            return $static;
        }

        return (string) Cache::remember('wholesaler_ct_token_'.md5((string) env(self::PREFIX.'_CLIENTE', 'ct')), now()->addHours(11), function () {
            $email = trim((string) env(self::PREFIX.'_EMAIL', ''));
            $cliente = trim((string) env(self::PREFIX.'_CLIENTE', ''));
            $rfc = trim((string) env(self::PREFIX.'_RFC', ''));
            if ($email === '' || $cliente === '' || $rfc === '') {
                return '';
            }
            $baseUrl = rtrim((string) env(self::PREFIX.'_BASE_URL', 'https://api.ctonline.mx:3001'), '/');
            $response = $this->httpClient()->post("{$baseUrl}/cliente/token", [
                'email' => $email,
                'cliente' => $cliente,
                'rfc' => $rfc,
            ]);
            if ($response->failed()) {
                return '';
            }

            return trim((string) ($response->json('token') ?? ''));
        });
    }

    private function httpClient(): \Illuminate\Http\Client\PendingRequest
    {
        $timeout = max(1, (int) env(self::PREFIX.'_TIMEOUT', 20));
        $sourceIp = trim((string) env(self::PREFIX.'_SOURCE_IP', ''));
        $client = Http::timeout($timeout)->acceptJson();
        if ($sourceIp !== '') {
            $client = $client->withOptions([
                'curl' => [CURLOPT_INTERFACE => $sourceIp],
            ]);
        }

        return $client;
    }

    private function cacheKey(): string
    {
        return 'wholesaler_ct_api_supplement_v1_'.md5((string) env(self::PREFIX.'_BASE_URL', 'ct'));
    }
}
