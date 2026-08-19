<?php

namespace App\Services\Wholesalers\Connectors;

use App\Models\Wholesaler;
use App\Services\Wholesalers\CvaCatalogIndex;
use App\Services\Wholesalers\CvaWarehouseDirectory;
use App\Services\Wholesalers\SkuLookupPolicy;
use App\Services\Wholesalers\SkuNormalizer;
use App\Services\Wholesalers\WholesalerOffer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Grupo CVA — https://apicvaservices.grupocva.com/documentation/
 * Auth: POST /user/login → Bearer token (12 h).
 * Lookup: GET /catalogo_clientes/precios_stock_ofertas?clave=|codigo=
 * Catálogo local: CvaCatalogIndex (sync: wholesalers:sync-cva-catalog).
 */
class CvaConnector extends AbstractWholesalerConnector
{
    private const PREFIX = 'WHOLESALER_CVA';

    private const DEFAULT_BASE = 'https://apicvaservices.grupocva.com/api/v2';

    private CvaCatalogIndex $catalogIndex;

    public function __construct(?CvaCatalogIndex $catalogIndex = null)
    {
        $this->catalogIndex = $catalogIndex ?? new CvaCatalogIndex;
    }

    protected function integrationType(): string
    {
        return 'api';
    }

    public function supports(Wholesaler $wholesaler): bool
    {
        return strtoupper($wholesaler->code) === 'CVA';
    }

    /**
     * @return list<WholesalerOffer>
     */
    protected function fetchOffers(Wholesaler $wholesaler, string $partNumber): array
    {
        $token = $this->resolveToken();
        if ($token === '') {
            return [$this->errorOffer(
                $wholesaler,
                $partNumber,
                'CVA: configura WHOLESALER_CVA_USER y WHOLESALER_CVA_PASSWORD',
            )];
        }

        $query = SkuNormalizer::forLookup($partNumber);
        if ($query === '') {
            return [$this->errorOffer($wholesaler, $partNumber, 'CVA: número de parte vacío')];
        }

        // Catálogo CVA primero y consulta directa del SKU después.
        $candidates = SkuLookupPolicy::candidates(
            $query,
            $this->catalogIndex->candidateClaves($query, 8),
        );

        foreach ($candidates as $candidate) {
            foreach (['clave', 'codigo'] as $param) {
                $offer = $this->lookupByParam($wholesaler, $partNumber, $param, $candidate, $token);
                if ($offer->error === null && ($offer->cost > 0 || $offer->stock > 0)) {
                    $detail = $this->productDetail($offer->partNumber, $candidate, $token);
                    if ($detail !== null) {
                        return [$this->withProductDetails(
                            $offer,
                            $detail['partNumber'],
                            $detail['description'],
                        )];
                    }

                    $name = $this->catalogIndex->productName($offer->partNumber);
                    if ($name !== null && $offer->description === '') {
                        return [$this->withDescription($offer, $name)];
                    }

                    return [$offer];
                }
                if ($offer->error !== null && (str_contains((string) $offer->error, '401') || str_contains((string) $offer->error, 'token'))) {
                    return [$offer];
                }
            }
        }

        // Último recurso oficial antes de declarar el SKU inexistente: el
        // web service de CVA permite búsqueda genérica dentro de la
        // descripción. Solo aceptamos el resultado si su número de fabricante
        // coincide exactamente con el SKU solicitado.
        $officialSearchOffer = $this->lookupByOfficialDescription(
            $wholesaler,
            $partNumber,
            $query,
            $token,
        );
        if ($officialSearchOffer !== null) {
            return [$officialSearchOffer];
        }

        return [$this->errorOffer(
            $wholesaler,
            $partNumber,
            "CVA: sin precio/existencia para «{$partNumber}» (probado catálogo local + clave/codigo + búsqueda oficial por descripción).",
        )];
    }

    private function lookupByOfficialDescription(
        Wholesaler $wholesaler,
        string $partNumber,
        string $query,
        string $token,
    ): ?WholesalerOffer {
        try {
            $response = $this->httpClient()
                ->withToken($token)
                ->get($this->baseUrl().'/catalogo_clientes/lista_precios', [
                    'desc' => $query,
                ]);

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return null;
            }

            $articles = isset($data['articulos']) && is_array($data['articulos'])
                ? $data['articulos']
                : [$data];

            foreach (array_slice($articles, 0, 25) as $article) {
                if (! is_array($article)) {
                    continue;
                }

                $manufacturerSku = trim((string) (
                    $article['codigo_fabricante']
                    ?? $article['codigo']
                    ?? ''
                ));
                if (! SkuLookupPolicy::responseMatchesCandidate($query, [$manufacturerSku])) {
                    continue;
                }

                $internalKey = trim((string) ($article['clave'] ?? ''));
                if ($internalKey === '') {
                    continue;
                }

                $offer = $this->lookupByParam(
                    $wholesaler,
                    $partNumber,
                    'clave',
                    $internalKey,
                    $token,
                );
                if ($offer->error !== null || ($offer->cost <= 0 && $offer->stock <= 0)) {
                    continue;
                }

                $description = trim((string) ($article['descripcion'] ?? ''));

                return $description !== ''
                    ? $this->withProductDetails($offer, $manufacturerSku, $description)
                    : $offer;
            }
        } catch (\Throwable $e) {
            Log::warning('CVA official description search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @return array{partNumber: string, description: string}|null
     */
    private function productDetail(string $partNumber, string $clave, string $token): ?array
    {
        $query = trim($partNumber) !== '' ? trim($partNumber) : trim($clave);
        if ($query === '') {
            return null;
        }

        $cacheKey = 'wholesaler_cva_product_detail_'.md5($this->normalizeCode($query));

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($query, $clave, $token) {
            try {
                $response = $this->httpClient()
                    ->withToken($token)
                    ->get($this->baseUrl().'/catalogo_clientes/lista_precios', [
                        'codigo' => $query,
                    ]);

                if ($response->failed()) {
                    return null;
                }

                $data = $response->json();
                if (! is_array($data)) {
                    return null;
                }

                $row = isset($data['articulos'][0]) && is_array($data['articulos'][0])
                    ? $data['articulos'][0]
                    : $data;
                $manufacturerSku = trim((string) ($row['codigo_fabricante'] ?? $row['codigo'] ?? ''));
                $internalClave = trim((string) ($row['clave'] ?? ''));
                $expected = array_filter([
                    $this->normalizeCode($query),
                    $this->normalizeCode($clave),
                ]);

                if (
                    ! in_array($this->normalizeCode($manufacturerSku), $expected, true)
                    && ! in_array($this->normalizeCode($internalClave), $expected, true)
                ) {
                    return null;
                }

                $description = trim((string) ($row['descripcion'] ?? ''));
                if ($description === '') {
                    return null;
                }

                return [
                    'partNumber' => $manufacturerSku !== '' ? $manufacturerSku : $query,
                    'description' => $description,
                ];
            } catch (\Throwable $e) {
                Log::warning('CVA product detail lookup failed', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    private function normalizeCode(string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($value))) ?? '';
    }

    private function withProductDetails(
        WholesalerOffer $offer,
        string $partNumber,
        string $description,
    ): WholesalerOffer {
        return new WholesalerOffer(
            wholesalerId: $offer->wholesalerId,
            wholesalerCode: $offer->wholesalerCode,
            wholesalerName: $offer->wholesalerName,
            partNumber: $partNumber !== '' ? $partNumber : $offer->partNumber,
            cost: $offer->cost,
            stock: $offer->stock,
            warehouse: $offer->warehouse,
            leadDays: $offer->leadDays,
            description: $description,
            error: $offer->error,
        );
    }

    private function withDescription(WholesalerOffer $offer, string $description): WholesalerOffer
    {
        return new WholesalerOffer(
            wholesalerId: $offer->wholesalerId,
            wholesalerCode: $offer->wholesalerCode,
            wholesalerName: $offer->wholesalerName,
            partNumber: $offer->partNumber,
            cost: $offer->cost,
            stock: $offer->stock,
            warehouse: $offer->warehouse,
            leadDays: $offer->leadDays,
            description: $description,
            error: $offer->error,
        );
    }

    private function lookupByParam(
        Wholesaler $wholesaler,
        string $partNumber,
        string $param,
        string $value,
        string $token,
    ): WholesalerOffer {
        $baseUrl = $this->baseUrl();
        $url = "{$baseUrl}/catalogo_clientes/precios_stock_ofertas";

        try {
            $response = $this->httpClient()
                ->withToken($token)
                ->get($url, [
                    $param => $value,
                    'MonedaPesos' => '1',
                ]);

            if ($response->status() === 401) {
                Cache::forget($this->tokenCacheKey());

                return $this->errorOffer($wholesaler, $partNumber, 'CVA: token no autorizado (401)');
            }

            if ($response->status() === 404) {
                return $this->errorOffer($wholesaler, $partNumber, 'CVA: sin resultado');
            }

            if ($response->failed()) {
                Log::warning('CVA lookup failed', [
                    'param' => $param,
                    'status' => $response->status(),
                ]);

                return $this->errorOffer($wholesaler, $partNumber, "CVA: HTTP {$response->status()}");
            }

            $data = $response->json();
            if (! is_array($data)) {
                return $this->errorOffer($wholesaler, $partNumber, 'CVA: respuesta inválida');
            }

            $article = $this->extractArticle($data);
            if ($article === null) {
                return $this->errorOffer($wholesaler, $partNumber, 'CVA: sin resultado');
            }

            if (! SkuLookupPolicy::responseMatchesCandidate($value, [
                $article['clave'] ?? null,
                $article['codigo'] ?? null,
                $article['codigo_fabricante'] ?? null,
            ])) {
                return $this->errorOffer(
                    $wholesaler,
                    $partNumber,
                    "CVA: la API devolvió un producto distinto al candidato «{$value}»",
                );
            }

            return $this->mapArticle($wholesaler, $partNumber, $article);
        } catch (\Throwable $e) {
            Log::warning('CVA lookup exception', [
                'param' => $param,
                'error' => $e->getMessage(),
            ]);

            return $this->errorOffer($wholesaler, $partNumber, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function extractArticle(array $data): ?array
    {
        if (isset($data['articulos']) && is_array($data['articulos'])) {
            $first = $data['articulos'][0] ?? null;

            return is_array($first) ? $first : null;
        }

        // Respuesta de producto individual (sin envelope articulos)
        if (isset($data['clave']) || isset($data['precio']) || isset($data['inventario'])) {
            return $data;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $article
     */
    private function mapArticle(Wholesaler $wholesaler, string $partNumber, array $article): WholesalerOffer
    {
        $rawPrice = $article['precio'] ?? 0;
        if (is_string($rawPrice) && ! is_numeric($rawPrice)) {
            $rawPrice = 0;
        }
        $basePrice = (float) $rawPrice;
        $promo = is_array($article['promocion'] ?? null) ? $article['promocion'] : null;
        $promoRaw = $promo['precio'] ?? null;
        $promoPrice = (is_numeric($promoRaw) && (float) $promoRaw > 0) ? (float) $promoRaw : null;
        $unitPrice = ($promoPrice !== null && $promoPrice > 0) ? $promoPrice : $basePrice;

        // MonedaPesos=1 debería traer MXN; por si acaso convertimos USD con tc cacheado.
        $currency = $this->normalizeCurrency((string) ($article['moneda'] ?? 'Pesos'));
        if ($currency === 'USD' && $unitPrice > 0) {
            $rate = $this->exchangeRateHint();
            if ($rate > 0) {
                $unitPrice *= $rate;
            }
        }

        [$stock, $warehouse] = $this->pickInventory($article);

        $clave = trim((string) ($article['clave'] ?? ''));
        $codigo = trim((string) ($article['codigo'] ?? $article['codigo_fabricante'] ?? ''));
        $description = trim((string) ($article['descripcion'] ?? ''));
        if ($description === '' && $clave !== '') {
            $description = $clave.($codigo !== '' ? " / {$codigo}" : '');
        }

        return new WholesalerOffer(
            wholesalerId: $wholesaler->id,
            wholesalerCode: $wholesaler->code,
            wholesalerName: $wholesaler->name,
            // Mostrar el SKU/código de fabricante para poder cotejarlo con CT;
            // la clave interna CVA se usa únicamente para consultar su API.
            partNumber: $codigo !== '' ? $codigo : ($clave !== '' ? $clave : $partNumber),
            cost: round(max(0, $unitPrice), 2),
            stock: $stock,
            warehouse: $warehouse,
            leadDays: 0,
            description: $description,
        );
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array{0: int, 1: string}
     */
    private function pickInventory(array $article): array
    {
        $inventario = $article['inventario'] ?? null;
        if (! is_array($inventario)) {
            $disp = (int) ($article['disponible'] ?? 0);
            $cd = (int) ($article['disponibleCD'] ?? 0);
            if ($disp > 0) {
                // Sin desglose: la API no indica cuál sucursal es “la tuya”.
                return [$disp, 'CDMX'];
            }
            if ($cd > 0) {
                return [$cd, 'CEDIS Guadalajara'];
            }

            return [max($disp, $cd), ''];
        }

        $bestStock = 0;
        $bestName = '';
        $total = 0;

        foreach ($inventario as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['nombre'] ?? ''));
            $qty = (int) ($row['disponible'] ?? 0);
            if (strcasecmp($name, 'TOTAL') === 0) {
                $total = $qty;

                continue;
            }
            if ($qty > $bestStock) {
                $bestStock = $qty;
                $bestName = $this->canonicalWarehouseName($name);
            }
        }

        $stock = $total > 0 ? $total : $bestStock;
        $label = $bestName !== '' ? $bestName : ($stock > 0 ? 'CVA' : '');

        return [$stock, $label];
    }

    private function canonicalWarehouseName(string $name): string
    {
        $value = CvaWarehouseDirectory::preferenceValueFromName($name);
        if ($value === null) {
            return $name;
        }

        $clave = substr($value, 4);
        /** @var array{name?: string}|null $row */
        $row = config('cva_warehouses.'.$clave);

        return is_array($row) && ! empty($row['name'])
            ? (string) $row['name']
            : $name;
    }

    private function normalizeCurrency(string $raw): string
    {
        $u = strtoupper(trim($raw));
        if (str_contains($u, 'DOLAR') || $u === 'USD') {
            return 'USD';
        }

        return 'MXN';
    }

    /**
     * Tipo de cambio aproximado si MonedaPesos no convirtió (fallback).
     * Preferimos no llamar APIs extra; se puede fijar WHOLESALER_CVA_FX.
     */
    private function exchangeRateHint(): float
    {
        $fixed = (float) env(self::PREFIX.'_FX', 0);
        if ($fixed > 0) {
            return $fixed;
        }

        return 0;
    }

    private function resolveToken(): string
    {
        $user = trim((string) env(self::PREFIX.'_USER', ''));
        $password = (string) env(self::PREFIX.'_PASSWORD', '');
        if ($user === '' || $password === '') {
            return '';
        }

        return (string) Cache::remember($this->tokenCacheKey(), now()->addHours(11), function () {
            return $this->requestToken();
        });
    }

    private function requestToken(): string
    {
        $user = trim((string) env(self::PREFIX.'_USER', ''));
        $password = (string) env(self::PREFIX.'_PASSWORD', '');
        $baseUrl = $this->baseUrl();

        try {
            $response = $this->httpClient()->post("{$baseUrl}/user/login", [
                'user' => $user,
                'password' => $password,
            ]);

            if ($response->failed()) {
                Log::warning('CVA login failed', ['status' => $response->status()]);

                return '';
            }

            return trim((string) ($response->json('token') ?? ''));
        } catch (\Throwable $e) {
            Log::warning('CVA login exception', ['error' => $e->getMessage()]);

            return '';
        }
    }

    private function tokenCacheKey(): string
    {
        $user = trim((string) env(self::PREFIX.'_USER', 'cva'));

        return 'wholesaler_cva_token_'.md5($user);
    }

    private function baseUrl(): string
    {
        return rtrim((string) env(self::PREFIX.'_BASE_URL', self::DEFAULT_BASE), '/');
    }

    private function httpClient(): PendingRequest
    {
        $timeout = max(1, (int) env(self::PREFIX.'_TIMEOUT', 20));

        return Http::timeout($timeout)->acceptJson();
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
