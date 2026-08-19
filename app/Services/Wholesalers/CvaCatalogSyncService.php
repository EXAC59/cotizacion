<?php

namespace App\Services\Wholesalers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Descarga el catálogo CVA (paginado) y lo persiste en CvaCatalogIndex.
 */
class CvaCatalogSyncService
{
    private const MIN_EXPECTED_PRODUCTS = 100;

    private const PREFIX = 'WHOLESALER_CVA';

    private const DEFAULT_BASE = 'https://apicvaservices.grupocva.com/api/v2';

    public function __construct(
        private readonly CvaCatalogIndex $index = new CvaCatalogIndex,
    ) {}

    /**
     * @return array{products: int, codes: int, pages_stock: int, pages_lista: int, synced_at: string}
     */
    public function sync(bool $withDescriptions = true, string $batch = 'LG', ?callable $onProgress = null): array
    {
        $token = $this->resolveToken();
        if ($token === '') {
            throw new \RuntimeException('CVA: configura WHOLESALER_CVA_USER y WHOLESALER_CVA_PASSWORD');
        }

        /** @var array<string, array{nombre: string, descripcion: string, marca: string, codigo: string, precio: float, stock: int, warehouse: string}> $products */
        $products = [];
        /** @var array<string, string> $codes */
        $codes = [];

        $pagesStock = $this->syncPreciosStock($token, $batch, $products, $codes, $onProgress);

        if (count($products) < self::MIN_EXPECTED_PRODUCTS) {
            throw new \RuntimeException(sprintf(
                'CVA: sincronización incompleta (%d productos en %d páginas); se conserva el catálogo anterior.',
                count($products),
                $pagesStock,
            ));
        }

        // Checkpoint: guardar precios/stock aunque falle lista_precios después.
        $syncedAt = now()->toIso8601String();
        $this->index->replaceCatalog($codes, $products, $syncedAt);

        $pagesLista = 0;
        if ($withDescriptions) {
            try {
                $pagesLista = $this->syncListaPrecios($token, $products, $codes, $onProgress);
                $syncedAt = now()->toIso8601String();
                $this->index->replaceCatalog($codes, $products, $syncedAt);
            } catch (\Throwable $e) {
                Log::warning('CVA lista_precios sync failed; keeping precios/stock catalog', [
                    'products' => count($products),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'products' => count($products),
            'codes' => count($codes),
            'pages_stock' => $pagesStock,
            'pages_lista' => $pagesLista,
            'synced_at' => $syncedAt,
        ];
    }

    /**
     * @param  array<string, array{nombre: string, descripcion: string, marca: string, codigo: string, precio: float, stock: int, warehouse: string}>  $products
     * @param  array<string, string>  $codes
     */
    private function syncPreciosStock(
        string $token,
        string $batch,
        array &$products,
        array &$codes,
        ?callable $onProgress,
    ): int {
        $batch = strtoupper(trim($batch));
        if (! in_array($batch, ['SM', 'MD', 'LG', 'XL'], true)) {
            $batch = 'LG';
        }

        $baseUrl = $this->baseUrl();
        $page = 1;
        $totalPages = 1;
        $pagesFetched = 0;

        while ($page <= $totalPages) {
            $response = $this->httpClient()
                ->withToken($token)
                ->get("{$baseUrl}/catalogo_clientes/precios_stock_ofertas", [
                    'batch' => $batch,
                    'MonedaPesos' => '1',
                    'page' => $page,
                ]);

            if ($response->status() === 401) {
                Cache::forget($this->tokenCacheKey());
                $token = $this->resolveToken(force: true);
                if ($token === '') {
                    throw new \RuntimeException('CVA: token expirado y no se pudo renovar');
                }

                continue;
            }

            if ($response->failed()) {
                throw new \RuntimeException("CVA precios_stock HTTP {$response->status()} (page {$page})");
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new \RuntimeException("CVA precios_stock respuesta inválida (page {$page})");
            }

            $articulos = is_array($data['articulos'] ?? null) ? $data['articulos'] : [];
            if ($articulos === [] && $page === 1 && ! isset($data['paginacion'])) {
                // Algunas respuestas de error
                $msg = (string) ($data['message'] ?? 'sin artículos');
                throw new \RuntimeException("CVA precios_stock vacío: {$msg}");
            }

            $pag = is_array($data['paginacion'] ?? null) ? $data['paginacion'] : [];
            $totalPages = max(1, (int) ($pag['total_paginas'] ?? $page));

            foreach ($articulos as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $this->ingestStockRow($row, $products, $codes);
            }

            $pagesFetched++;
            if ($onProgress) {
                $onProgress('stock', $page, $totalPages, count($products));
            }

            $page++;
            if ($page > 5000) {
                Log::warning('CVA sync stock: tope de páginas alcanzado');
                break;
            }
        }

        return $pagesFetched;
    }

    /**
     * @param  array<string, array{nombre: string, descripcion: string, marca: string, codigo: string, precio: float, stock: int, warehouse: string}>  $products
     * @param  array<string, string>  $codes
     */
    private function syncListaPrecios(
        string $token,
        array &$products,
        array &$codes,
        ?callable $onProgress,
    ): int {
        $baseUrl = $this->baseUrl();
        $page = 1;
        $totalPages = 1;
        $pagesFetched = 0;
        $consecutiveFailures = 0;

        while ($page <= $totalPages) {
            try {
                $response = $this->httpClient()
                    ->withToken($token)
                    ->get("{$baseUrl}/catalogo_clientes/lista_precios", [
                        'page' => $page,
                    ]);
            } catch (\Throwable $e) {
                $consecutiveFailures++;
                Log::warning('CVA lista_precios page exception', [
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                if ($consecutiveFailures >= 5) {
                    break;
                }
                $page++;

                continue;
            }

            if ($response->status() === 401) {
                Cache::forget($this->tokenCacheKey());
                $token = $this->resolveToken(force: true);
                if ($token === '') {
                    throw new \RuntimeException('CVA: token expirado en lista_precios');
                }

                continue;
            }

            if ($response->status() === 404) {
                break;
            }

            if ($response->failed()) {
                $consecutiveFailures++;
                Log::warning('CVA lista_precios falló', ['page' => $page, 'status' => $response->status()]);
                if ($consecutiveFailures >= 5) {
                    break;
                }
                $page++;

                continue;
            }

            $consecutiveFailures = 0;

            $data = $response->json();
            if (! is_array($data)) {
                break;
            }

            if (isset($data['message']) && ! isset($data['articulos'])) {
                break;
            }

            $articulos = is_array($data['articulos'] ?? null) ? $data['articulos'] : [];
            $pag = is_array($data['paginacion'] ?? null) ? $data['paginacion'] : [];
            $totalPages = max(1, (int) ($pag['total_paginas'] ?? $page));

            foreach ($articulos as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $this->ingestListaRow($row, $products, $codes);
            }

            $pagesFetched++;
            if ($onProgress) {
                $onProgress('lista', $page, $totalPages, count($products));
            }

            // Checkpoint periódico para no perder descripciones si corta después.
            if ($pagesFetched % 25 === 0) {
                $this->index->replaceCatalog($codes, $products, now()->toIso8601String());
            }

            $page++;
            if ($page > 10000) {
                break;
            }
        }

        return $pagesFetched;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array{nombre: string, descripcion: string, marca: string, codigo: string, precio: float, stock: int, warehouse: string}>  $products
     * @param  array<string, string>  $codes
     */
    private function ingestStockRow(array $row, array &$products, array &$codes): void
    {
        $clave = trim((string) ($row['clave'] ?? ''));
        if ($clave === '') {
            return;
        }

        $codigo = trim((string) ($row['codigo'] ?? ''));
        $rawPrice = $row['precio'] ?? 0;
        $precio = is_numeric($rawPrice) ? (float) $rawPrice : 0.0;
        if (is_array($row['promocion'] ?? null) && is_numeric($row['promocion']['precio'] ?? null)) {
            $promo = (float) $row['promocion']['precio'];
            if ($promo > 0) {
                $precio = $promo;
            }
        }

        [$stock, $warehouse] = $this->pickInventory($row);
        $existing = $products[$clave] ?? null;
        $nombre = is_array($existing) ? (string) ($existing['nombre'] ?? '') : '';
        $descripcion = is_array($existing) ? (string) ($existing['descripcion'] ?? '') : '';
        $marca = is_array($existing) ? (string) ($existing['marca'] ?? '') : '';

        if ($nombre === '') {
            $nombre = $codigo !== '' ? $codigo : $clave;
        }
        if ($descripcion === '') {
            $descripcion = $codigo !== '' ? "{$clave} / {$codigo}" : $clave;
        }

        $products[$clave] = [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'marca' => $marca,
            'codigo' => $codigo !== '' ? $codigo : (string) ($existing['codigo'] ?? ''),
            'precio' => $precio,
            'stock' => $stock,
            'warehouse' => $warehouse,
        ];

        $this->indexCodes($codes, $clave, $codigo);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array{nombre: string, descripcion: string, marca: string, codigo: string, precio: float, stock: int, warehouse: string}>  $products
     * @param  array<string, string>  $codes
     */
    private function ingestListaRow(array $row, array &$products, array &$codes): void
    {
        $clave = trim((string) ($row['clave'] ?? ''));
        if ($clave === '') {
            return;
        }

        $codigo = trim((string) ($row['codigo_fabricante'] ?? $row['codigo'] ?? ''));
        $descripcion = trim((string) ($row['descripcion'] ?? ''));
        $marca = trim((string) ($row['marca'] ?? ''));
        $nombre = $descripcion !== '' ? $descripcion : ($codigo !== '' ? $codigo : $clave);

        $existing = $products[$clave] ?? [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'marca' => $marca,
            'codigo' => $codigo,
            'precio' => 0.0,
            'stock' => 0,
            'warehouse' => '',
        ];

        $rawPrice = $row['precio'] ?? null;
        $precio = is_numeric($rawPrice) ? (float) $rawPrice : (float) ($existing['precio'] ?? 0);
        $disp = (int) ($row['disponible'] ?? 0);
        $cd = (int) ($row['disponibleCD'] ?? 0);
        $stock = max((int) ($existing['stock'] ?? 0), $disp + $cd);
        $warehouse = (string) ($existing['warehouse'] ?? '');
        if ($warehouse === '') {
            if ($disp > 0) {
                $warehouse = 'Sucursal CVA';
            } elseif ($cd > 0) {
                $warehouse = 'CEDIS Guadalajara';
            }
        }

        $products[$clave] = [
            'nombre' => $descripcion !== '' ? $descripcion : (string) ($existing['nombre'] ?? $nombre),
            'descripcion' => $descripcion !== '' ? $descripcion : (string) ($existing['descripcion'] ?? ''),
            'marca' => $marca !== '' ? $marca : (string) ($existing['marca'] ?? ''),
            'codigo' => $codigo !== '' ? $codigo : (string) ($existing['codigo'] ?? ''),
            'precio' => $precio > 0 ? $precio : (float) ($existing['precio'] ?? 0),
            'stock' => $stock,
            'warehouse' => $warehouse,
        ];

        $this->indexCodes($codes, $clave, $codigo);
    }

    /**
     * @param  array<string, string>  $codes
     */
    private function indexCodes(array &$codes, string $clave, string $codigo): void
    {
        $claveNorm = $this->index->normalize($clave);
        if ($claveNorm !== '') {
            $codes[$claveNorm] = $clave;
        }
        $codigoNorm = $this->index->normalize($codigo);
        if ($codigoNorm !== '' && ! isset($codes[$codigoNorm])) {
            $codes[$codigoNorm] = $clave;
        }
    }

    /**
     * @param  array<string, mixed>  $article
     * @return array{0: int, 1: string}
     */
    private function pickInventory(array $article): array
    {
        $inventario = $article['inventario'] ?? null;
        if (! is_array($inventario)) {
            return [0, ''];
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
                $bestName = $name;
            }
        }

        return [$total > 0 ? $total : $bestStock, $bestName];
    }

    private function resolveToken(bool $force = false): string
    {
        $user = trim((string) env(self::PREFIX.'_USER', ''));
        $password = (string) env(self::PREFIX.'_PASSWORD', '');
        if ($user === '' || $password === '') {
            return '';
        }

        if ($force) {
            Cache::forget($this->tokenCacheKey());
        }

        return (string) Cache::remember($this->tokenCacheKey(), now()->addHours(11), function () {
            return $this->requestToken();
        });
    }

    private function requestToken(): string
    {
        $user = trim((string) env(self::PREFIX.'_USER', ''));
        $password = (string) env(self::PREFIX.'_PASSWORD', '');
        $response = $this->httpClient()->post($this->baseUrl().'/user/login', [
            'user' => $user,
            'password' => $password,
        ]);

        if ($response->failed()) {
            Log::warning('CVA catalog login failed', ['status' => $response->status()]);

            return '';
        }

        return trim((string) ($response->json('token') ?? ''));
    }

    private function tokenCacheKey(): string
    {
        return 'wholesaler_cva_token_'.md5(trim((string) env(self::PREFIX.'_USER', 'cva')));
    }

    private function baseUrl(): string
    {
        return rtrim((string) env(self::PREFIX.'_BASE_URL', self::DEFAULT_BASE), '/');
    }

    private function httpClient(): PendingRequest
    {
        // Sync de catálogo pagina mucho; 20s en Docker suele cortar lista_precios.
        $timeout = max(5, (int) env(self::PREFIX.'_TIMEOUT', 60));
        $syncTimeout = max($timeout, (int) env(self::PREFIX.'_SYNC_TIMEOUT', 90));

        return Http::timeout($syncTimeout)->acceptJson()->retry(2, 1000);
    }
}
