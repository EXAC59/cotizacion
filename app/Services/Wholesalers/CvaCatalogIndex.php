<?php

namespace App\Services\Wholesalers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Índice local del catálogo CVA (sincronizado vía API precios_stock_ofertas / lista_precios).
 *
 * @phpstan-type CvaProduct array{nombre: string, descripcion: string, marca: string, codigo: string, precio: float, stock: int, warehouse: string}
 */
class CvaCatalogIndex
{
    private const PREFIX = 'WHOLESALER_CVA';

    private const DISK_RELATIVE = 'cva-catalog/catalog.json';

    /**
     * @return list<array{clave: string, partNumber: string|null, nombre: string, descripcion: string, marca: string}>
     */
    public function search(string $query, int $limit = 15): array
    {
        $limit = max(1, min(50, $limit));
        $qNorm = $this->normalize($query);
        $qFold = $this->fold($query);

        if (strlen($qNorm) < 2 && mb_strlen($qFold) < 2) {
            return [];
        }

        $bundle = $this->catalogBundle();

        return $this->searchInCatalog($bundle['codes'], $bundle['products'], $qNorm, $qFold, $limit);
    }

    /**
     * @return list<array{clave: string, partNumber: string|null, nombre: string, descripcion: string, marca: string}>
     */
    public function searchMatching(?string $sku, ?string $descripcion, int $limit = 15): array
    {
        $limit = max(1, min(50, $limit));
        $sku = trim((string) $sku);
        $descripcion = trim((string) $descripcion);

        $skuUsable = $this->isUsableQuery($sku);
        $descUsable = $this->isUsableQuery($descripcion);

        if ($skuUsable && $descUsable) {
            // El código exacto de fabricante/CVA manda sobre diferencias de redacción
            // entre catálogos (tinta, cartucho, consumible, etc.).
            $resolved = $this->resolveClave($sku);
            if ($resolved !== null && $resolved !== '') {
                $exact = array_values(array_filter(
                    $this->search($sku, max($limit, 15)),
                    fn (array $hit): bool => $this->normalize((string) ($hit['clave'] ?? ''))
                        === $this->normalize($resolved),
                ));
                if ($exact !== []) {
                    return array_slice($exact, 0, $limit);
                }
            }

            $pool = max($limit * 4, 40);
            $bySku = $this->search($sku, $pool);
            $qNorm = $this->normalize($descripcion);
            $qFold = $this->fold($descripcion);
            $out = [];
            foreach ($bySku as $hit) {
                $hay = $this->fold(($hit['nombre'] ?? '').' '.($hit['descripcion'] ?? '').' '.($hit['marca'] ?? ''));
                $hayNorm = $this->normalize(($hit['nombre'] ?? '').' '.($hit['descripcion'] ?? '').' '.($hit['clave'] ?? ''));
                $ok = true;
                if ($qFold !== '' && mb_strlen($qFold) >= 2) {
                    foreach (preg_split('/\s+/', $qFold, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                        if (mb_strlen($token) < 2) {
                            continue;
                        }
                        if (! str_contains($hay, $token)) {
                            $ok = false;
                            break;
                        }
                    }
                } elseif ($qNorm !== '' && strlen($qNorm) >= 2) {
                    $ok = str_contains($hayNorm, $qNorm);
                }
                if (! $ok) {
                    continue;
                }
                $out[] = $hit;
                if (count($out) >= $limit) {
                    break;
                }
            }

            return $out;
        }

        if ($skuUsable) {
            return $this->search($sku, $limit);
        }

        if ($descUsable) {
            return $this->search($descripcion, $limit);
        }

        return [];
    }

    public function resolveClave(string $partNumber): ?string
    {
        $normalized = $this->normalize($partNumber);
        if ($normalized === '') {
            return null;
        }

        $codes = $this->catalogBundle()['codes'];

        $exact = $codes[$normalized] ?? null;
        if (is_string($exact) && $exact !== '') {
            return $exact;
        }

        // Algunos fabricantes agregan un sufijo regional a su SKU (AL, MX,
        // etc.). CVA puede guardar el mismo producto sin ese sufijo.
        if (preg_match('/^(.+\d)([A-Z]{1,3})$/', $normalized, $matches)) {
            $base = $matches[1];
            $regional = $codes[$base] ?? null;
            if (is_string($regional) && $regional !== '') {
                return $regional;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function candidateClaves(string $query, int $limit = 15): array
    {
        $resolved = $this->resolveClave($query);

        // El comparador no debe convertir una coincidencia parcial en otro producto.
        // La búsqueda aproximada queda disponible únicamente para autocomplete.
        return $resolved !== null && $resolved !== '' ? [$resolved] : [];
    }

    public function productName(string $clave): ?string
    {
        $clave = trim($clave);
        if ($clave === '') {
            return null;
        }

        $products = $this->catalogBundle()['products'];
        $meta = $products[$clave] ?? $products[$this->normalize($clave)] ?? null;
        if (! is_array($meta)) {
            return null;
        }

        $nombre = trim((string) ($meta['nombre'] ?? ''));

        return $nombre !== '' ? $nombre : null;
    }

    public function count(): int
    {
        return count($this->catalogBundle()['products']);
    }

    public function syncedAt(): ?string
    {
        $meta = $this->diskMeta();

        return is_string($meta['synced_at'] ?? null) ? $meta['synced_at'] : null;
    }

    /**
     * Reemplaza el índice en disco y caché tras un sync completo.
     *
     * @param  array<string, string>  $codes
     * @param  array<string, CvaProduct>  $products
     */
    public function replaceCatalog(array $codes, array $products, ?string $syncedAt = null): void
    {
        $incoming = count($products);
        $existing = $this->count();
        // Evitar pisar un catálogo completo con un sync parcial/roto (p. ej. 1 SKU).
        if ($incoming > 0 && $existing >= 500 && $incoming < (int) max(50, $existing * 0.1)) {
            Log::warning('CVA catalog replace aborted: incoming too small vs existing', [
                'incoming' => $incoming,
                'existing' => $existing,
            ]);

            throw new \RuntimeException(
                "CVA: sync devolvió solo {$incoming} productos (hay {$existing} en disco). No se sobrescribe el catálogo."
            );
        }

        $payload = [
            'synced_at' => $syncedAt ?? now()->toIso8601String(),
            'codes' => $codes,
            'products' => $products,
        ];

        $path = $this->diskPath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        Cache::forget($this->cacheKey());
        if ($codes !== []) {
            $ttl = max(5, (int) env(self::PREFIX.'_CATALOG_TTL', 240));
            Cache::put($this->cacheKey(), [
                'codes' => $codes,
                'products' => $products,
            ], now()->addMinutes($ttl));
        }
    }

    /**
     * Productos del catálogo CVA con stock bajo (mayor a 0 y menor al umbral).
     *
     * @return list<array{partNumber: string, product: string, stock: int, warehouse: string}>
     */
    public function lowStockProducts(int $threshold, int $limit = 20): array
    {
        $threshold = max(1, $threshold);
        $limit = max(1, min(100, $limit));
        $bundle = $this->catalogBundle();
        $out = [];

        foreach ($bundle['products'] as $clave => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $stock = (int) ($meta['stock'] ?? 0);
            if ($stock <= 0 || $stock >= $threshold) {
                continue;
            }
            $codigo = trim((string) ($meta['codigo'] ?? ''));
            $partNumber = $codigo !== '' ? $codigo : (string) $clave;
            $nombre = trim((string) ($meta['nombre'] ?? ''));
            $out[] = [
                'partNumber' => $partNumber,
                'product' => $nombre !== '' ? $nombre : $partNumber,
                'stock' => $stock,
                'warehouse' => trim((string) ($meta['warehouse'] ?? '')),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['stock'] <=> $b['stock']);

        return array_slice($out, 0, $limit);
    }

    public function normalize(string $value): string
    {
        $value = strtoupper(trim($value));

        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }

    public function fold(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($trans) && $trans !== '') {
            $value = $trans;
        }

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    /**
     * @param  array<string, string>  $codes
     * @param  array<string, CvaProduct>  $products
     * @return list<array{clave: string, partNumber: string|null, nombre: string, descripcion: string, marca: string}>
     */
    public function searchInCatalog(
        array $codes,
        array $products,
        string $qNorm,
        string $qFold,
        int $limit = 15,
    ): array {
        $limit = max(1, min(50, $limit));
        /** @var array<string, array{score: int, partNumber: string|null}> $scored */
        $scored = [];

        if ($qNorm !== '' && strlen($qNorm) >= 2) {
            foreach ($codes as $key => $clave) {
                $clave = (string) $clave;
                if ($clave === '') {
                    continue;
                }
                $claveNorm = $this->normalize($clave);
                $score = null;
                if ($key === $qNorm || $claveNorm === $qNorm) {
                    $score = 300;
                } elseif (str_starts_with($key, $qNorm) || str_starts_with($claveNorm, $qNorm)) {
                    $score = 200;
                } elseif (
                    str_starts_with($qNorm, $key)
                    && preg_match('/^[A-Z]{1,3}$/', substr($qNorm, strlen($key)))
                ) {
                    $score = 180;
                } elseif (str_contains($key, $qNorm) || str_contains($claveNorm, $qNorm)) {
                    $score = 150;
                } elseif (strlen($qNorm) >= 4 && str_ends_with($key, $qNorm)) {
                    $score = 120;
                }
                if ($score === null) {
                    continue;
                }
                $partNumber = $key === $claveNorm ? null : (string) $key;
                if (! isset($scored[$clave]) || $score > $scored[$clave]['score']) {
                    $scored[$clave] = ['score' => $score, 'partNumber' => $partNumber];
                }
            }
        }

        if ($qFold !== '' && mb_strlen($qFold) >= 2) {
            foreach ($products as $claveKey => $meta) {
                if (! is_array($meta)) {
                    continue;
                }
                // Evitar entradas normalizadas duplicadas
                if ($claveKey !== ($codes[$this->normalize((string) $claveKey)] ?? $claveKey)
                    && isset($codes[$this->normalize((string) $claveKey)])) {
                    // Prefer canonical clave from codes map
                }
                $canonical = $codes[$this->normalize((string) $claveKey)] ?? (string) $claveKey;
                if (isset($scored[$canonical]) && $scored[$canonical]['score'] >= 200) {
                    continue;
                }
                $hay = $this->fold(
                    ((string) ($meta['nombre'] ?? '')).' '
                    .((string) ($meta['descripcion'] ?? '')).' '
                    .((string) ($meta['marca'] ?? '')).' '
                    .((string) ($meta['codigo'] ?? ''))
                );
                if (! str_contains($hay, $qFold) && ! $this->tokensMatch($hay, $qFold)) {
                    continue;
                }
                $score = 80;
                if (! isset($scored[$canonical]) || $score > $scored[$canonical]['score']) {
                    $scored[$canonical] = ['score' => $score, 'partNumber' => null];
                }
            }
        }

        uasort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $out = [];
        foreach ($scored as $clave => $info) {
            $meta = $products[$clave] ?? $products[$this->normalize($clave)] ?? [
                'nombre' => $clave,
                'descripcion' => '',
                'marca' => '',
                'codigo' => '',
                'precio' => 0.0,
                'stock' => 0,
                'warehouse' => '',
            ];
            $out[] = [
                'clave' => $clave,
                'partNumber' => $info['partNumber'],
                'nombre' => (string) ($meta['nombre'] ?? $clave),
                'descripcion' => (string) ($meta['descripcion'] ?? ''),
                'marca' => (string) ($meta['marca'] ?? ''),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function tokensMatch(string $hay, string $qFold): bool
    {
        $tokens = preg_split('/\s+/', $qFold, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => mb_strlen($t) >= 2));
        if ($tokens === []) {
            return false;
        }
        foreach ($tokens as $token) {
            if (! str_contains($hay, $token)) {
                return false;
            }
        }

        return true;
    }

    private function isUsableQuery(string $query): bool
    {
        $query = trim($query);
        if ($query === '') {
            return false;
        }

        return strlen($this->normalize($query)) >= 2 || mb_strlen($this->fold($query)) >= 2;
    }

    /**
     * @return array{codes: array<string, string>, products: array<string, CvaProduct>}
     */
    private function catalogBundle(): array
    {
        $ttl = max(5, (int) env(self::PREFIX.'_CATALOG_TTL', 240));
        $cacheKey = $this->cacheKey();

        /** @var array{codes: array<string, string>, products: array<string, CvaProduct>}|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['codes'], $cached['products']) && $cached['codes'] !== []) {
            return $cached;
        }

        $fromDisk = $this->loadFromDisk();
        if ($fromDisk['codes'] !== []) {
            Cache::put($cacheKey, $fromDisk, now()->addMinutes($ttl));

            return $fromDisk;
        }

        return ['codes' => [], 'products' => []];
    }

    /**
     * @return array{codes: array<string, string>, products: array<string, CvaProduct>}
     */
    private function loadFromDisk(): array
    {
        $path = $this->diskPath();
        if (! File::exists($path)) {
            return ['codes' => [], 'products' => []];
        }

        try {
            /** @var array<string, mixed> $json */
            $json = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::warning('CVA catalog disk read failed', ['error' => $e->getMessage()]);

            return ['codes' => [], 'products' => []];
        }

        $codes = is_array($json['codes'] ?? null) ? $json['codes'] : [];
        $products = is_array($json['products'] ?? null) ? $json['products'] : [];

        /** @var array<string, string> $codes */
        /** @var array<string, CvaProduct> $products */
        return [
            'codes' => $codes,
            'products' => $products,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function diskMeta(): array
    {
        $path = $this->diskPath();
        if (! File::exists($path)) {
            return [];
        }
        try {
            /** @var array<string, mixed> $json */
            $json = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

            return is_array($json) ? $json : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function diskPath(): string
    {
        return storage_path('app/'.self::DISK_RELATIVE);
    }

    private function cacheKey(): string
    {
        return 'wholesaler_cva_catalog_bundle_v1';
    }
}
