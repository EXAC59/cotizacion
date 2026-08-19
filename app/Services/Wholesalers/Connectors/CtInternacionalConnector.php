<?php

namespace App\Services\Wholesalers\Connectors;

use App\Models\Wholesaler;
use App\Services\Wholesalers\CtCatalogIndex;
use App\Services\Wholesalers\CtWarehouseDirectory;
use App\Services\Wholesalers\SkuLookupPolicy;
use App\Services\Wholesalers\WholesalerOffer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CT Connect — https://api.ctonline.mx/
 * Auth: header x-auth (token). Token fijo en WHOLESALER_CT_API_KEY o generado con email/cliente/rfc.
 * Catálogo FTP (productos.json) mapea numParte/modelo → clave CT cuando hace falta.
 */
class CtInternacionalConnector extends AbstractWholesalerConnector
{
    private const PREFIX = 'WHOLESALER_CT';

    private CtCatalogIndex $catalogIndex;

    public function __construct(?CtCatalogIndex $catalogIndex = null)
    {
        $this->catalogIndex = $catalogIndex ?? new CtCatalogIndex;
    }

    protected function integrationType(): string
    {
        return 'api';
    }

    public function supports(Wholesaler $wholesaler): bool
    {
        return $wholesaler->code === 'CT';
    }

    /**
     * @return list<WholesalerOffer>
     */
    protected function fetchOffers(Wholesaler $wholesaler, string $partNumber): array
    {
        $token = $this->resolveToken();
        if ($token === '') {
            return [$this->errorOffer($wholesaler, $partNumber, 'CT: configura WHOLESALER_CT_API_KEY o email/cliente/rfc')];
        }

        $baseUrl = rtrim((string) env(self::PREFIX.'_BASE_URL', 'https://api.ctonline.mx:3001'), '/');
        $catalogClaves = $this->catalogIndex->candidateClaves($partNumber);
        $multiAnalyze = count($catalogClaves) >= 3;
        // Enlistar solo las 3 mejores coincidencias para comparar precios.
        $clavesForLookup = $multiAnalyze
            ? array_slice($catalogClaves, 0, 3)
            : $catalogClaves;
        $codesToTry = $this->candidateCodes($partNumber, $clavesForLookup, $multiAnalyze);

        /** @var list<WholesalerOffer> $collected */
        $collected = [];

        foreach ($codesToTry as $code) {
            $offer = $this->lookupByCode($wholesaler, $partNumber, $code, $token, $baseUrl);

            if ($offer->error !== null || ($offer->cost <= 0 && $offer->stock <= 0)) {
                continue;
            }

            if (! $multiAnalyze) {
                return [$offer];
            }

            $collected[] = $this->offerWithCatalogLabel($offer, $code);
        }

        if ($multiAnalyze && $collected !== []) {
            return $collected;
        }

        $tried = implode(', ', $codesToTry);

        return [$this->errorOffer(
            $wholesaler,
            $partNumber,
            "CT: sin precio/existencia para «{$partNumber}» (probado: {$tried}). Usa clave CT o numParte del catálogo FTP.",
        )];
    }

    /**
     * @param  list<string>  $catalogClaves
     * @return list<string>
     */
    private function candidateCodes(string $partNumber, ?array $catalogClaves = null, bool $multiAnalyze = false): array
    {
        $catalogClaves ??= $this->catalogIndex->candidateClaves($partNumber);

        // Catálogo web/FTP primero; API directa después. En búsqueda ambigua
        // sólo se prueban las claves verificadas del catálogo.
        return SkuLookupPolicy::candidates($partNumber, $catalogClaves, ! $multiAnalyze);
    }

    private function offerWithCatalogLabel(WholesalerOffer $offer, string $ctCode): WholesalerOffer
    {
        $name = trim($offer->description);
        if ($name === '') {
            $name = $this->catalogIndex->productDescription($ctCode)
                ?? $this->catalogIndex->productName($ctCode)
                ?? $ctCode;
        }

        $label = $name;
        if ($ctCode !== '' && ! str_contains($name, $ctCode)) {
            $label = "{$name} [{$ctCode}]";
        }

        $displaySku = $this->displaySkuForCode($ctCode, $ctCode !== '' ? $ctCode : $offer->partNumber);

        return new WholesalerOffer(
            wholesalerId: $offer->wholesalerId,
            wholesalerCode: $offer->wholesalerCode,
            wholesalerName: $offer->wholesalerName,
            // SKU / numParte de fabricante (no la clave CT) cuando el catálogo lo tiene.
            partNumber: $displaySku !== '' ? $displaySku : $offer->partNumber,
            cost: $offer->cost,
            stock: $offer->stock,
            warehouse: $offer->warehouse,
            leadDays: $offer->leadDays,
            description: $label,
            error: $offer->error,
        );
    }

    private function lookupByCode(
        Wholesaler $wholesaler,
        string $requestedPartNumber,
        string $ctCode,
        string $token,
        string $baseUrl,
    ): WholesalerOffer {
        $lookupPath = (string) env(self::PREFIX.'_LOOKUP_PATH', '/existencia/promociones/{part_number}');
        $url = $baseUrl.str_replace('{part_number}', rawurlencode($ctCode), $lookupPath);

        try {
            $response = $this->httpClient()->withHeaders(['x-auth' => $token])->get($url);

            if ($response->status() === 401) {
                Cache::forget($this->tokenCacheKey());

                return $this->errorOffer($wholesaler, $requestedPartNumber, 'CT: token no autorizado (401)');
            }

            if ($response->failed()) {
                Log::warning('CT lookup failed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return $this->errorOffer($wholesaler, $requestedPartNumber, "CT: HTTP {$response->status()}");
            }

            $data = $response->json();
            if (! is_array($data)) {
                return $this->errorOffer($wholesaler, $requestedPartNumber, 'CT: respuesta inválida');
            }

            if (! SkuLookupPolicy::responseMatchesCandidate($ctCode, [
                $data['codigo'] ?? null,
                $data['clave'] ?? null,
                $data['numParte'] ?? null,
                $data['modelo'] ?? null,
            ])) {
                return $this->errorOffer(
                    $wholesaler,
                    $requestedPartNumber,
                    "CT: la API devolvió un producto distinto al candidato «{$ctCode}»",
                );
            }

            return $this->mapPromocionesResponse(
                $wholesaler,
                $requestedPartNumber,
                $ctCode,
                $data,
                $token,
                $baseUrl,
            );
        } catch (\Throwable $e) {
            Log::warning('CT lookup exception', ['error' => $e->getMessage(), 'code' => $ctCode]);

            return $this->errorOffer($wholesaler, $requestedPartNumber, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapPromocionesResponse(
        Wholesaler $wholesaler,
        string $partNumber,
        string $lookedUpCode,
        array $data,
        string $token,
        string $baseUrl,
    ): WholesalerOffer {
        $basePrice = (float) ($data['precio'] ?? 0);
        $currency = strtoupper((string) ($data['moneda'] ?? 'MXN'));
        $warehouses = is_array($data['almacenes'] ?? null) ? $data['almacenes'] : [];

        $candidates = [];
        /** @var array<string, float|null> $unitPriceByCode */
        $unitPriceByCode = [];

        foreach ($warehouses as $row) {
            if (! is_array($row)) {
                continue;
            }

            $promoPrice = $this->activePromoPrice($row['promocion'] ?? null);
            $unitPrice = $promoPrice ?? ($basePrice > 0 ? $basePrice : null);

            foreach ($row as $key => $value) {
                if (! is_string($key) || ! preg_match('/^(?:\d{2}A|D2A)$/', $key)) {
                    continue;
                }

                $stock = (int) $value;
                if ($stock > 0) {
                    $candidates[] = ['code' => $key, 'stock' => $stock];
                    $unitPriceByCode[$key] = $unitPrice;
                }
            }
        }

        // Búsqueda nacional: la mejor oferta CT por stock/precio de TODOS los almacenes.
        // Los almacenes preferidos no ocultan stock de otros (ranking suave queda en el comparador).
        $bestWarehouse = CtWarehouseDirectory::pickBestCode($candidates, []);
        $selectedStock = $bestWarehouse !== ''
            ? CtWarehouseDirectory::stockForCode($candidates, $bestWarehouse)
            : 0;
        $bestUnitPrice = $unitPriceByCode[$bestWarehouse]
            ?? ($basePrice > 0 ? $basePrice : 0.0);

        $costMxn = $this->toMxn((float) $bestUnitPrice, $currency, $token, $baseUrl);
        $warehouseLabel = $bestWarehouse !== ''
            ? CtWarehouseDirectory::formatLabel($bestWarehouse)
            : '';

        $apiCode = trim((string) ($data['codigo'] ?? ''));
        $description = '';
        foreach ([$apiCode, $lookedUpCode, $partNumber] as $nameKey) {
            if ($nameKey === '') {
                continue;
            }
            $found = $this->catalogIndex->productDescription($nameKey)
                ?? $this->catalogIndex->productName($nameKey);
            if ($found !== null && $found !== '') {
                $description = $found;
                break;
            }
        }

        return new WholesalerOffer(
            wholesalerId: $wholesaler->id,
            wholesalerCode: $wholesaler->code,
            wholesalerName: $wholesaler->name,
            partNumber: $this->displaySkuForCode($lookedUpCode, $partNumber),
            cost: round($costMxn, 2),
            stock: $selectedStock,
            warehouse: $warehouseLabel,
            leadDays: 0,
            description: $description,
        );
    }

    /**
     * Expone numParte/SKU al usuario; la API CT sigue consultándose por clave internamente.
     */
    private function displaySkuForCode(string $ctCode, string $fallback): string
    {
        if (! $this->preferManufacturerSku()) {
            return $fallback !== '' ? $fallback : $ctCode;
        }

        $sku = $this->catalogIndex->manufacturerSkuForClave($ctCode);
        if (is_string($sku) && $sku !== '') {
            return $sku;
        }

        return $fallback !== '' ? $fallback : $ctCode;
    }

    private function preferManufacturerSku(): bool
    {
        $raw = env(self::PREFIX.'_PREFER_SKU', true);

        if (is_bool($raw)) {
            return $raw;
        }

        $normalized = strtolower(trim((string) $raw));

        return ! in_array($normalized, ['0', 'false', 'no', 'off'], true);
    }

    /**
     * @param  array<string, mixed>|null  $promo
     */
    private function activePromoPrice(?array $promo): ?float
    {
        if ($promo === null || ! isset($promo['precio'])) {
            return null;
        }

        $vigente = $promo['vigente'] ?? null;
        if (is_array($vigente)) {
            $now = now();
            $ini = isset($vigente['ini']) ? strtotime((string) $vigente['ini']) : false;
            $fin = isset($vigente['fin']) ? strtotime((string) $vigente['fin']) : false;
            if ($ini !== false && $now->getTimestamp() < $ini) {
                return null;
            }
            if ($fin !== false && $now->getTimestamp() > $fin) {
                return null;
            }
        }

        return (float) $promo['precio'];
    }

    private function toMxn(float $amount, string $currency, string $token, string $baseUrl): float
    {
        if ($amount <= 0) {
            return 0;
        }

        if ($currency !== 'USD') {
            return $amount;
        }

        $rate = $this->exchangeRate($token, $baseUrl);

        return $amount * ($rate > 0 ? $rate : 1);
    }

    private function exchangeRate(string $token, string $baseUrl): float
    {
        return (float) Cache::remember('wholesaler_ct_fx', now()->addHour(), function () use ($token, $baseUrl) {
            try {
                $response = $this->httpClient()
                    ->withHeaders(['x-auth' => $token])
                    ->get("{$baseUrl}/pedido/tipoCambio");

                if ($response->successful()) {
                    $json = $response->json();

                    return (float) ($json['tipoCambio'] ?? 0);
                }
            } catch (\Throwable) {
                // fallback
            }

            return 0;
        });
    }

    private function resolveToken(): string
    {
        $static = trim((string) env(self::PREFIX.'_API_KEY', ''));
        if ($static !== '') {
            return $static;
        }

        return (string) Cache::remember($this->tokenCacheKey(), now()->addHours(11), function () {
            return $this->requestToken();
        });
    }

    private function requestToken(): string
    {
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
            Log::warning('CT token request failed', ['status' => $response->status()]);

            return '';
        }

        return trim((string) ($response->json('token') ?? ''));
    }

    private function tokenCacheKey(): string
    {
        return 'wholesaler_ct_token_'.md5((string) env(self::PREFIX.'_CLIENTE', 'ct'));
    }

    private function httpClient(): PendingRequest
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
