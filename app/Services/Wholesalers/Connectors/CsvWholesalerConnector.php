<?php

namespace App\Services\Wholesalers\Connectors;

use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerOffer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CsvWholesalerConnector extends AbstractWholesalerConnector
{
    protected function integrationType(): string
    {
        return 'csv';
    }

    /**
     * @return list<WholesalerOffer>
     */
    protected function fetchOffers(Wholesaler $wholesaler, string $partNumber): array
    {
        $prefix = (string) ($wholesaler->config_json['env_prefix'] ?? '');
        $catalog = $this->loadCatalog($prefix);

        if ($catalog === null) {
            return [$this->pendingOffer($wholesaler, $partNumber)];
        }

        $needle = $this->normalizeSku($partNumber);
        $row = $catalog[$needle] ?? null;

        if ($row === null) {
            return [new WholesalerOffer(
                wholesalerId: $wholesaler->id,
                wholesalerCode: $wholesaler->code,
                wholesalerName: $wholesaler->name,
                partNumber: $partNumber,
                cost: 0,
                stock: 0,
                error: 'SKU no encontrado en catálogo CSV',
            )];
        }

        return [new WholesalerOffer(
            wholesalerId: $wholesaler->id,
            wholesalerCode: $wholesaler->code,
            wholesalerName: $wholesaler->name,
            partNumber: $partNumber,
            cost: (float) ($row['cost'] ?? 0),
            stock: (int) ($row['stock'] ?? 0),
            warehouse: (string) ($row['warehouse'] ?? ''),
            leadDays: (int) ($row['lead_days'] ?? 0),
            description: isset($row['description']) ? (string) $row['description'] : '',
        )];
    }

    /**
     * @return array<string, array{cost: float, stock: int, warehouse: string, lead_days: int, description?: string}>|null
     */
    private function loadCatalog(string $prefix): ?array
    {
        if ($prefix === '') {
            return null;
        }

        $ttl = max(1, (int) env("{$prefix}_CSV_TTL", 15));
        $cacheKey = 'wholesaler.csv.'.Str::lower($prefix);

        return Cache::remember($cacheKey, now()->addMinutes($ttl), function () use ($prefix) {
            $raw = $this->readCsvContents($prefix);
            if ($raw === null || trim($raw) === '') {
                return null;
            }

            return $this->parseCatalog($raw);
        });
    }

    private function readCsvContents(string $prefix): ?string
    {
        $path = trim((string) env("{$prefix}_CSV_PATH", ''));
        $url = trim((string) env("{$prefix}_BASE_URL", ''));
        $apiKey = trim((string) env("{$prefix}_API_KEY", ''));

        if ($path !== '') {
            $resolved = $this->resolveLocalPath($path);
            if ($resolved === null || ! is_readable($resolved)) {
                Log::warning('Wholesaler CSV path no legible', ['prefix' => $prefix, 'path' => $path]);

                return null;
            }

            $contents = file_get_contents($resolved);

            return $contents === false ? null : $contents;
        }

        if ($url === '') {
            return null;
        }

        try {
            $request = Http::timeout(max(5, (int) env("{$prefix}_TIMEOUT", 30)))->accept('text/csv,text/plain,*/*');
            if ($apiKey !== '') {
                $authHeader = trim((string) env("{$prefix}_AUTH_HEADER", 'Authorization'));
                if ($authHeader === '' || strcasecmp($authHeader, 'Authorization') === 0) {
                    $request = $request->withToken($apiKey);
                } else {
                    $request = $request->withHeaders([$authHeader => $apiKey]);
                }
            }

            $response = $request->get($url);
            if ($response->failed()) {
                Log::warning('Wholesaler CSV download failed', [
                    'prefix' => $prefix,
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::warning('Wholesaler CSV download exception', [
                'prefix' => $prefix,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveLocalPath(string $path): ?string
    {
        if (Str::startsWith($path, ['/', '\\']) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        $candidates = [
            storage_path($path),
            storage_path('app/'.$path),
            base_path($path),
        ];

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{cost: float, stock: int, warehouse: string, lead_days: int, description?: string}>
     */
    private function parseCatalog(string $raw): array
    {
        $lines = preg_split('/\R/', $raw) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line) => trim($line) !== ''));
        if ($lines === []) {
            return [];
        }

        $delimiter = str_contains($lines[0], ';') && ! str_contains($lines[0], ',') ? ';' : ',';
        $header = str_getcsv(array_shift($lines), $delimiter);
        $map = $this->headerMap($header);

        $catalog = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line, $delimiter);
            if ($cols === [null] || $cols === false) {
                continue;
            }

            $sku = $this->normalizeSku((string) ($cols[$map['sku']] ?? ''));
            if ($sku === '') {
                continue;
            }

            $cost = (float) str_replace([',', '$', ' '], ['', '', ''], (string) ($cols[$map['cost']] ?? '0'));
            $stock = (int) preg_replace('/\D+/', '', (string) ($cols[$map['stock']] ?? '0'));
            $warehouse = trim((string) ($cols[$map['warehouse']] ?? ''));
            $leadDays = (int) ($cols[$map['lead_days']] ?? 0);
            $description = isset($map['description'])
                ? trim((string) ($cols[$map['description']] ?? ''))
                : '';

            $entry = [
                'cost' => $cost,
                'stock' => $stock,
                'warehouse' => $warehouse,
                'lead_days' => $leadDays,
            ];
            if ($description !== '') {
                $entry['description'] = $description;
            }

            $catalog[$sku] = $entry;
        }

        return $catalog;
    }

    /**
     * @param  list<string|null>  $header
     * @return array{sku: int, cost: int, stock: int, warehouse: int, lead_days: int, description?: int}
     */
    private function headerMap(array $header): array
    {
        $normalized = [];
        foreach ($header as $i => $col) {
            $key = Str::lower(trim((string) $col));
            $key = str_replace([' ', '-', '.'], '_', $key);
            $normalized[$key] = $i;
        }

        $sku = $normalized['part_number']
            ?? $normalized['partnumber']
            ?? $normalized['sku']
            ?? $normalized['no_parte']
            ?? $normalized['noparte']
            ?? $normalized['codigo']
            ?? 0;

        $cost = $normalized['cost']
            ?? $normalized['precio']
            ?? $normalized['price']
            ?? $normalized['costo']
            ?? 1;

        $stock = $normalized['stock']
            ?? $normalized['existencia']
            ?? $normalized['qty']
            ?? $normalized['quantity']
            ?? 2;

        $warehouse = $normalized['warehouse']
            ?? $normalized['almacen']
            ?? $normalized['location']
            ?? 3;

        $lead = $normalized['lead_days']
            ?? $normalized['leadDays']
            ?? $normalized['dias']
            ?? 4;

        $map = [
            'sku' => $sku,
            'cost' => $cost,
            'stock' => $stock,
            'warehouse' => $warehouse,
            'lead_days' => $lead,
        ];

        if (isset($normalized['description']) || isset($normalized['producto']) || isset($normalized['descripcion'])) {
            $map['description'] = $normalized['description']
                ?? $normalized['producto']
                ?? $normalized['descripcion'];
        }

        return $map;
    }

    private function normalizeSku(string $sku): string
    {
        return Str::upper(preg_replace('/\s+/', '', trim($sku)) ?? '');
    }
}
