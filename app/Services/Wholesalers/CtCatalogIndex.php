<?php

namespace App\Services\Wholesalers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Índice del catálogo FTP de CT (productos.json) para mapear
 * número de parte / modelo → clave CT.
 */
class CtCatalogIndex
{
    private const PREFIX = 'WHOLESALER_CT';

    public function resolveCtCode(string $partNumber): ?string
    {
        $normalized = $this->normalize($partNumber);
        if ($normalized === '') {
            return null;
        }

        $alias = $this->aliases()[$normalized] ?? null;
        if (is_string($alias) && $alias !== '') {
            return $alias;
        }

        // Productos ausentes en FTP pero presentes en API: resolución por UPC.
        $byUpc = (new CtApiCatalogSupplement($this))->resolveByUpc($partNumber);
        if ($byUpc !== null && $byUpc !== '') {
            return $byUpc;
        }

        $index = $this->index();
        if (isset($index[$normalized])) {
            return $index[$normalized];
        }

        if (strlen($normalized) < 5) {
            return null;
        }

        // Variante regional única: t664320 → T664320AL (resto 1–3 letras).
        $byVariantPrefix = $this->uniqueRegionalVariantClave($index, $normalized, 'prefix');
        if ($byVariantPrefix !== null) {
            return $byVariantPrefix;
        }

        // Query más largo: t664320-al → índice T664320.
        $byVariantLonger = $this->uniqueRegionalVariantClave($index, $normalized, 'query_longer');
        if ($byVariantLonger !== null) {
            return $byVariantLonger;
        }

        // Base sin sufijo regional: T664320AL → lookup exacto T664320.
        $base = $this->stripTrailingLetterSuffix($normalized);
        if ($base !== null && isset($index[$base])) {
            return $index[$base];
        }

        // SKU parcial (ej. PTPL210 → ACPTPL210) si hay una sola coincidencia por sufijo.
        $matches = [];
        foreach ($index as $key => $clave) {
            if (str_ends_with((string) $key, $normalized)) {
                $matches[$clave] = true;
                if (count($matches) > 1) {
                    return null;
                }
            }
        }

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    /**
     * Autocomplete: SKU incompleto y/o texto de nombre/descripción/marca.
     *
     * @return list<array{clave: string, partNumber: string|null, nombre: string, descripcion: string, marca: string}>
     */
    public function search(string $query, int $limit = 15): array
    {
        $limit = max(1, min(50, $limit));
        $qRaw = trim($query);
        $qNorm = $this->normalize($qRaw);
        $qFold = $this->fold($qRaw);

        if (strlen($qNorm) < 2 && mb_strlen($qFold) < 2) {
            return [];
        }

        $bundle = $this->catalogBundle();

        return $this->searchInCatalog($bundle['codes'], $bundle['products'], $qNorm, $qFold, $limit);
    }

    /**
     * Autocomplete por SKU y/o descripción. Si ambos tienen texto usable,
     * solo devuelve productos que coinciden en los dos (mismo peso).
     *
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
            // Un SKU resuelto de forma exacta/variante controlada tiene prioridad.
            // La descripción puede usar vocabulario distinto (p. ej. tinta/cartucho)
            // y no debe eliminar una coincidencia inequívoca de fabricante.
            $resolved = $this->resolveCtCode($sku);
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

            // Con SKU preciso: partir de coincidencias por SKU y filtrar por texto.
            // Intersectar con top-N de descripción falla con términos genéricos ("Tinta").
            $pool = max($limit * 4, 40);
            $bySku = $this->search($sku, $pool);
            $qNorm = $this->normalize($descripcion);
            $qFold = $this->fold($descripcion);

            $out = [];
            foreach ($bySku as $hit) {
                if (! $this->hitMatchesDescription($hit, $qNorm, $qFold)) {
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

    /**
     * @param  array{clave: string, partNumber: string|null, nombre: string, descripcion: string, marca: string}  $hit
     */
    private function hitMatchesDescription(array $hit, string $qNorm, string $qFold): bool
    {
        if ($qFold === '' && $qNorm === '') {
            return true;
        }

        $hayNombre = $this->fold((string) ($hit['nombre'] ?? ''));
        $hayDesc = $this->fold((string) ($hit['descripcion'] ?? ''));
        $hayMarca = $this->fold((string) ($hit['marca'] ?? ''));
        $hay = trim($hayNombre.' '.$hayDesc.' '.$hayMarca);
        $hayNorm = $this->normalize(
            ((string) ($hit['nombre'] ?? '')).' '
            .((string) ($hit['descripcion'] ?? '')).' '
            .((string) ($hit['partNumber'] ?? '')).' '
            .((string) ($hit['clave'] ?? ''))
        );

        if ($qFold !== '' && mb_strlen($qFold) >= 2) {
            $tokens = preg_split('/\s+/', $qFold, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_values(array_filter(
                $tokens,
                static fn (string $t): bool => mb_strlen($t) >= 2,
            ));
            if ($tokens !== []) {
                foreach ($tokens as $token) {
                    if (! str_contains($hay, $token)) {
                        return false;
                    }
                }

                return true;
            }
        }

        if ($qNorm !== '' && strlen($qNorm) >= 2) {
            return str_contains($hayNorm, $qNorm);
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
     * Claves CT a probar en lookup, ordenadas por relevancia del catálogo.
     * Una coincidencia exacta sigue devolviendo una sola clave; una búsqueda
     * parcial conserva alternativas para que el conector analice las mejores.
     *
     * @return list<string>
     */
    public function candidateClaves(string $query, int $limit = 15): array
    {
        $resolved = $this->resolveCtCode($query);
        if ($resolved !== null && $resolved !== '') {
            return [$resolved];
        }

        $claves = [];
        foreach ($this->search($query, $limit) as $hit) {
            $clave = trim((string) ($hit['clave'] ?? ''));
            if ($clave === '' || in_array($clave, $claves, true)) {
                continue;
            }
            $claves[] = $clave;
            if (count($claves) >= $limit) {
                break;
            }
        }

        return $claves;
    }

    /**
     * Sufijo regional típico tras dígitos (AL, MX, ABM → 1–3 letras).
     */
    private function stripTrailingLetterSuffix(string $normalized): ?string
    {
        if (! preg_match('/^(.+\d)([A-Z]{1,3})$/', $normalized, $m)) {
            return null;
        }

        $base = $m[1];

        return $base !== '' && $base !== $normalized ? $base : null;
    }

    private function isRegionalVariantRemainder(string $remainder): bool
    {
        $len = strlen($remainder);

        return $len >= 1 && $len <= 3 && ctype_alpha($remainder);
    }

    /**
     * @param  array<string, string>  $index
     * @param  'prefix'|'query_longer'  $mode
     */
    private function uniqueRegionalVariantClave(array $index, string $normalized, string $mode): ?string
    {
        /** @var array<string, true> $matches */
        $matches = [];

        foreach ($index as $key => $clave) {
            $key = (string) $key;
            $clave = (string) $clave;
            if ($key === '' || $clave === '' || $key === $normalized) {
                continue;
            }

            $remainder = null;
            if ($mode === 'prefix' && str_starts_with($key, $normalized)) {
                $remainder = substr($key, strlen($normalized));
            } elseif ($mode === 'query_longer' && str_starts_with($normalized, $key)) {
                $remainder = substr($normalized, strlen($key));
            }

            if ($remainder === null || ! $this->isRegionalVariantRemainder($remainder)) {
                continue;
            }

            $matches[$clave] = true;
            if (count($matches) > 1) {
                return null;
            }
        }

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    /**
     * @param  array<string, string>  $codes  normalized → clave CT
     * @param  array<string, array{nombre: string, descripcion: string, marca: string}>  $products
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
                    (strlen($key) >= 4 && str_starts_with($qNorm, $key))
                    || (strlen($claveNorm) >= 4 && str_starts_with($qNorm, $claveNorm))
                ) {
                    // Query variante más largo que la ref del catálogo (t664320-al vs T664320).
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
                    $scored[$clave] = [
                        'score' => $score,
                        'partNumber' => $partNumber,
                    ];
                } elseif ($scored[$clave]['partNumber'] === null && $partNumber !== null) {
                    $scored[$clave]['partNumber'] = $partNumber;
                }
            }
        }

        if ($qFold !== '' && mb_strlen($qFold) >= 2) {
            $uniqueProducts = [];
            foreach ($products as $claveKey => $meta) {
                $canonical = $codes[$this->normalize((string) $claveKey)] ?? (string) $claveKey;
                if (! isset($uniqueProducts[$canonical])) {
                    $uniqueProducts[$canonical] = $meta;
                }
            }

            foreach ($uniqueProducts as $clave => $meta) {
                $hay = $this->fold(
                    ($meta['nombre'] ?? '').' '.($meta['descripcion'] ?? '').' '.($meta['marca'] ?? '')
                );
                if ($hay === '') {
                    continue;
                }

                $tokens = preg_split('/\s+/', $qFold, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $tokens = array_values(array_filter(
                    $tokens,
                    static fn (string $t): bool => mb_strlen($t) >= 2,
                ));
                if ($tokens === []) {
                    continue;
                }

                $matched = 0;
                foreach ($tokens as $token) {
                    if (str_contains($hay, $token)) {
                        $matched++;
                    }
                }
                if ($matched === 0) {
                    continue;
                }

                // Todas las palabras → mejor score; coincidencia parcial de frase también cuenta.
                $score = ($matched === count($tokens))
                    ? (str_contains($hay, $qFold) ? 95 : 80)
                    : (int) (40 + (40 * $matched / count($tokens)));

                if (! isset($scored[$clave]) || $score > $scored[$clave]['score']) {
                    $scored[$clave] = [
                        'score' => $score,
                        'partNumber' => $scored[$clave]['partNumber'] ?? null,
                    ];
                }
            }
        }

        uasort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $out = [];
        foreach ($scored as $clave => $info) {
            $meta = $products[$clave] ?? $products[$this->normalize($clave)] ?? null;
            $nombre = trim((string) ($meta['nombre'] ?? ''));
            $descripcion = trim((string) ($meta['descripcion'] ?? $nombre));
            $marca = trim((string) ($meta['marca'] ?? ''));

            $out[] = [
                'clave' => $clave,
                'partNumber' => $info['partNumber'] ?? $this->manufacturerSkuForClave((string) $clave),
                'nombre' => $nombre !== '' ? $nombre : $clave,
                'descripcion' => $descripcion !== '' ? $descripcion : $nombre,
                'marca' => $marca,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function fold(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return is_string($trans) ? $trans : $value;
    }

    /**
     * Nombre comercial desde catálogo FTP (campo `nombre` / `descripcion_corta`).
     */
    public function productName(string $partNumberOrClave): ?string
    {
        $meta = $this->productMeta($partNumberOrClave);

        if ($meta === null) {
            return null;
        }

        $name = trim((string) ($meta['nombre'] ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * Descripción comercial más completa disponible para mostrar/autorrellenar.
     */
    public function productDescription(string $partNumberOrClave): ?string
    {
        $meta = $this->productMeta($partNumberOrClave);
        if ($meta === null) {
            return null;
        }

        $description = trim((string) ($meta['descripcion'] ?? ''));
        if ($description !== '') {
            return $description;
        }

        $name = trim((string) ($meta['nombre'] ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * numParte / modelo preferido para una clave CT (nunca la clave si hay SKU de fabricante).
     * Prioriza referencias con sufijo regional (…AL) sobre UPC/EAN.
     */
    public function manufacturerSkuForClave(string $clave): ?string
    {
        $clave = trim($clave);
        if ($clave === '') {
            return null;
        }

        $claveNorm = $this->normalize($clave);
        if ($claveNorm === '') {
            return null;
        }

        $best = null;
        $bestScore = -1;

        foreach ($this->index() as $key => $mapped) {
            $mapped = (string) $mapped;
            if ($mapped !== $clave && $this->normalize($mapped) !== $claveNorm) {
                continue;
            }

            $keyNorm = $this->normalize((string) $key);
            if ($keyNorm === '' || $keyNorm === $claveNorm) {
                continue;
            }

            // Saltar códigos de barras.
            if (preg_match('/^\d{11,14}$/', $keyNorm) === 1) {
                continue;
            }

            $score = strlen($keyNorm);
            if (preg_match('/\d[A-Z]{1,3}$/', $keyNorm) === 1) {
                $score += 100;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $keyNorm;
            }
        }

        return $best;
    }

    /**
     * @return array{nombre: string, descripcion: string, marca: string}|null
     */
    public function productMeta(string $partNumberOrClave): ?array
    {
        $clave = $this->resolveCtCode($partNumberOrClave);
        if ($clave === null || $clave === '') {
            $clave = strtoupper(trim($partNumberOrClave));
        }

        if ($clave === '') {
            return null;
        }

        $products = $this->catalogBundle()['products'];

        return $products[$clave] ?? $products[$this->normalize($clave)] ?? null;
    }

    /**
     * @return array<string, string> normalized → clave CT
     */
    public function index(): array
    {
        return $this->catalogBundle()['codes'];
    }

    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{nombre: string, descripcion: string, marca: string}>
     */
    public function buildProducts(array $rows): array
    {
        $products = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clave = trim((string) ($row['clave'] ?? ''));
            if ($clave === '') {
                continue;
            }

            $nombre = trim((string) ($row['nombre'] ?? ''));
            $corta = trim((string) ($row['descripcion_corta'] ?? ''));
            $marca = trim((string) ($row['marca'] ?? ''));
            $label = $nombre !== '' ? $nombre : $corta;
            if ($label === '') {
                continue;
            }

            $meta = [
                'nombre' => $label,
                'descripcion' => $corta !== '' ? $corta : $label,
                'marca' => $marca,
            ];
            $products[$clave] = $meta;
            $products[$this->normalize($clave)] = $meta;
        }

        return $products;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    public function buildIndex(array $rows): array
    {
        $index = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clave = trim((string) ($row['clave'] ?? ''));
            if ($clave === '') {
                continue;
            }

            foreach (['clave', 'numParte', 'no_parte', 'modelo', 'upc', 'ean', 'sustituto'] as $field) {
                $value = trim((string) ($row[$field] ?? ''));
                $normalized = $this->normalize($value);
                if ($normalized === '') {
                    continue;
                }

                // Prefer exact clave mapping if collision.
                if (! isset($index[$normalized]) || $field === 'clave') {
                    $index[$normalized] = $clave;
                }
            }

            // Modelo a veces solo aparece en el nombre comercial (no en campo modelo).
            $this->indexSkuTokensFromText($index, $clave, (string) ($row['nombre'] ?? ''));
            $this->indexSkuTokensFromText($index, $clave, (string) ($row['descripcion_corta'] ?? ''));
        }

        foreach ($this->aliases() as $from => $to) {
            if ($from !== '' && $to !== '') {
                $index[$from] = $to;
            }
        }

        return $index;
    }

    /**
     * @return array<string, string> normalized modelo → clave CT
     */
    public function aliases(): array
    {
        $out = [];

        $configured = config('ct_part_aliases', []);
        if (is_array($configured)) {
            foreach ($configured as $from => $to) {
                $fromN = $this->normalize((string) $from);
                $toN = trim((string) $to);
                if ($fromN !== '' && $toN !== '') {
                    $out[$fromN] = $toN;
                }
            }
        }

        $envAliases = trim((string) env(self::PREFIX.'_PART_ALIASES', ''));
        if ($envAliases !== '') {
            foreach (explode(',', $envAliases) as $pair) {
                $pair = trim($pair);
                if ($pair === '' || ! str_contains($pair, ':')) {
                    continue;
                }
                [$from, $to] = array_map('trim', explode(':', $pair, 2));
                $fromN = $this->normalize($from);
                if ($fromN !== '' && $to !== '') {
                    $out[$fromN] = $to;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $index
     */
    private function indexSkuTokensFromText(array &$index, string $clave, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        if (! preg_match_all('/\b([A-Z0-9][A-Z0-9\-+\/]{3,})\b/i', $text, $matches)) {
            return;
        }

        foreach ($matches[1] as $token) {
            $normalized = $this->normalize($token);
            if (strlen($normalized) < 5 || ! preg_match('/\d/', $normalized)) {
                continue;
            }
            if (preg_match('/^\d+$/', $normalized)) {
                continue;
            }
            if (! isset($index[$normalized])) {
                $index[$normalized] = $clave;
            }
        }
    }

    public function normalize(string $value): string
    {
        $value = strtoupper(trim($value));

        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }

    /**
     * @return array{codes: array<string, string>, products: array<string, array{nombre: string, descripcion: string, marca: string}>}
     */
    private function catalogBundle(): array
    {
        $ttlMinutes = max(5, (int) env(self::PREFIX.'_CATALOG_TTL', 15));
        $cacheKey = $this->cacheKey();

        /** @var array{codes: array<string, string>, products: array<string, array{nombre: string, descripcion: string, marca: string}>}|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['codes'], $cached['products']) && $cached['codes'] !== []) {
            return $cached;
        }

        $lock = Cache::lock($cacheKey.':lock', 120);
        $owned = false;
        try {
            try {
                $owned = $lock->block(90);
            } catch (\Throwable $e) {
                Log::warning('CT catalog lock unavailable', ['error' => $e->getMessage()]);
                $owned = false;
            }

            /** @var array{codes: array<string, string>, products: array<string, array{nombre: string, descripcion: string, marca: string}>}|null $cachedAgain */
            $cachedAgain = Cache::get($cacheKey);
            if (is_array($cachedAgain) && isset($cachedAgain['codes'], $cachedAgain['products']) && $cachedAgain['codes'] !== []) {
                return $cachedAgain;
            }

            $rows = $this->downloadCatalog();
            $bundle = [
                'codes' => $this->buildIndex($rows),
                'products' => $this->buildProducts($rows),
            ];

            // No cachear vacío: un fallo FTP temporal no debe bloquear el comparador 15 min.
            if ($bundle['codes'] !== []) {
                Cache::put($cacheKey, $bundle, now()->addMinutes($ttlMinutes));
            } else {
                Log::warning('CT catalog bundle vacío; no se cachea');
            }

            return $bundle;
        } finally {
            if ($owned) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function downloadCatalog(): array
    {
        $rows = [];

        $mainPath = trim((string) env(self::PREFIX.'_FTP_PATH', '/catalogo_xml/productos.json'));
        $main = $this->downloadCatalogPath($mainPath);
        $rows = $this->mergeCatalogRows($rows, $main);

        foreach ($this->extraCatalogPaths() as $path) {
            $extra = $this->downloadCatalogPath($path);
            $rows = $this->mergeCatalogRows($rows, $extra);
        }

        foreach ($this->localCatalogPaths() as $localPath) {
            $local = $this->loadLocalCatalogFile($localPath);
            $rows = $this->mergeCatalogRows($rows, $local);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function extraCatalogPaths(): array
    {
        $raw = trim((string) env(
            self::PREFIX.'_FTP_EXTRA_PATHS',
            '/catalogo_xml/productos_especiales_PAZ0074.xml',
        ));
        if ($raw === '') {
            return [];
        }

        $paths = [];
        foreach (explode(',', $raw) as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            if (! str_starts_with($path, '/')) {
                $path = '/'.$path;
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    private function localCatalogPaths(): array
    {
        $configured = trim((string) env(self::PREFIX.'_LOCAL_CATALOG_PATHS', ''));
        $paths = [];
        if ($configured !== '') {
            foreach (explode(',', $configured) as $path) {
                $path = trim($path);
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        }

        $defaults = [
            storage_path('app/ct-catalog/productos_especiales_PAZ0074.xml'),
            base_path('docs/integraciones/catalogo_xml/productos_especiales_PAZ0074.xml'),
        ];
        foreach ($defaults as $path) {
            if (is_file($path)) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function downloadCatalogPath(string $path): array
    {
        $host = trim((string) env(self::PREFIX.'_FTP_HOST', ''));
        $user = trim((string) env(self::PREFIX.'_FTP_USER', ''));
        $password = (string) env(self::PREFIX.'_FTP_PASSWORD', '');

        if ($host === '' || $user === '' || $password === '' || $path === '') {
            return [];
        }

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $url = sprintf(
            'ftp://%s:%s@%s%s',
            rawurlencode($user),
            rawurlencode($password),
            $host,
            $path,
        );

        try {
            $context = stream_context_create([
                'ftp' => [
                    'overwrite' => true,
                    'timeout' => max(10, (int) env(self::PREFIX.'_FTP_TIMEOUT', 60)),
                ],
            ]);

            $body = @file_get_contents($url, false, $context);
            if ($body === false || $body === '') {
                Log::warning('CT catalog FTP download failed', ['host' => $host, 'path' => $path]);

                return [];
            }

            return $this->parseCatalogBody($body, $path);
        } catch (\Throwable $e) {
            Log::warning('CT catalog exception', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadLocalCatalogFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $body = @file_get_contents($path);
        if ($body === false || $body === '') {
            return [];
        }

        return $this->parseCatalogBody($body, $path);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseCatalogBody(string $body, string $pathHint = ''): array
    {
        $lower = strtolower($pathHint);
        $looksXml = str_contains($lower, '.xml')
            || str_starts_with(ltrim($body), '<?xml')
            || str_starts_with(ltrim($body), '<Articulo')
            || str_starts_with(ltrim($body), '<articulo');

        if ($looksXml) {
            return $this->parseCatalogXml($body);
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            Log::warning('CT catalog JSON inválido', ['path' => $pathHint]);

            return [];
        }

        /** @var list<array<string, mixed>> $decoded */
        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseCatalogXml(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
            if ($document === false) {
                Log::warning('CT catalog XML inválido');

                return [];
            }

            $rows = [];
            foreach ($document->Producto as $producto) {
                $row = $this->simpleXmlProductToRow($producto);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            return $rows;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function simpleXmlProductToRow(\SimpleXMLElement $producto): ?array
    {
        $clave = trim((string) ($producto->clave ?? ''));
        if ($clave === '') {
            return null;
        }

        $noParte = trim((string) ($producto->no_parte ?? ''));
        $numParte = trim((string) ($producto->numParte ?? ''));
        if ($numParte === '') {
            $numParte = $noParte;
        }

        return [
            'clave' => $clave,
            'numParte' => $numParte,
            'no_parte' => $noParte !== '' ? $noParte : $numParte,
            'modelo' => trim((string) ($producto->modelo ?? '')),
            'upc' => trim((string) ($producto->upc ?? '')),
            'ean' => trim((string) ($producto->ean ?? '')),
            'sustituto' => trim((string) ($producto->sustituto ?? '')),
            'nombre' => trim((string) ($producto->nombre ?? '')),
            'descripcion_corta' => trim((string) ($producto->descripcion_corta ?? '')),
            'marca' => trim((string) ($producto->marca ?? '')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $base
     * @param  list<array<string, mixed>>  $extra
     * @return list<array<string, mixed>>
     */
    private function mergeCatalogRows(array $base, array $extra): array
    {
        if ($extra === []) {
            return $base;
        }
        if ($base === []) {
            return $extra;
        }

        /** @var array<string, int> $byClave */
        $byClave = [];
        foreach ($base as $i => $row) {
            $clave = strtoupper(trim((string) ($row['clave'] ?? '')));
            if ($clave !== '') {
                $byClave[$clave] = $i;
            }
        }

        foreach ($extra as $row) {
            $clave = strtoupper(trim((string) ($row['clave'] ?? '')));
            if ($clave === '') {
                continue;
            }
            if (isset($byClave[$clave])) {
                // Especiales / suplemento rellenan huecos del catálogo principal.
                $base[$byClave[$clave]] = array_merge($base[$byClave[$clave]], array_filter(
                    $row,
                    static fn ($v) => $v !== null && $v !== '',
                ));
            } else {
                $byClave[$clave] = count($base);
                $base[] = $row;
            }
        }

        return $base;
    }

    private function cacheKey(): string
    {
        return 'wholesaler_ct_catalog_bundle_v4_'.md5(
            ((string) env(self::PREFIX.'_FTP_PATH', 'productos.json'))
            .'|'.((string) env(self::PREFIX.'_FTP_EXTRA_PATHS', 'productos_especiales_PAZ0074.xml'))
            .'|'.((string) env(self::PREFIX.'_LOCAL_CATALOG_PATHS', ''))
            .'|'.((string) env(self::PREFIX.'_PART_ALIASES', ''))
            .'|'.md5(json_encode(config('ct_part_aliases', [])) ?: '')
        );
    }
}
