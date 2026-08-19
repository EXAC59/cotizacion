<?php

namespace App\Services\Wholesalers\LowStock;

use App\Contracts\WholesalerLowStockSource;
use App\Models\AppSetting;
use App\Models\Wholesaler;
use App\Models\WholesalerStockSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class LowStockPollService
{
    /**
     * Ejecuta las fuentes registradas para mayoristas activos/configurados.
     *
     * @param  list<string>|null  $onlyCodes  Filtrar por codes (p. ej. ['CVA']).
     * @return array{threshold: int, wholesalers: list<array{code: string, saved: int, error: string|null}>}
     */
    public function poll(?array $onlyCodes = null): array
    {
        $threshold = max(0, (int) AppSetting::current()->min_stock_alert);
        $summary = [
            'threshold' => $threshold,
            'wholesalers' => [],
        ];

        if ($threshold <= 0 || ! Schema::hasTable('wholesaler_stock_snapshots')) {
            return $summary;
        }

        $limit = max(1, min(200, (int) config('low_stock.per_wholesaler_limit', 100)));
        $sources = $this->resolveSources($onlyCodes);

        foreach ($sources as $source) {
            $code = strtoupper($source->wholesalerCode());
            $wholesaler = Wholesaler::query()
                ->where('code', $code)
                ->where('active', true)
                ->first();

            if ($wholesaler === null || ! $source->supports($wholesaler)) {
                $summary['wholesalers'][] = [
                    'code' => $code,
                    'saved' => 0,
                    'error' => 'Mayorista inactivo o no encontrado',
                ];
                continue;
            }

            if ($source->requiresCredentials() && ! $wholesaler->isConfigured()) {
                $summary['wholesalers'][] = [
                    'code' => $code,
                    'saved' => 0,
                    'error' => 'Sin credenciales configuradas',
                ];
                continue;
            }

            try {
                $rows = $source->collect($wholesaler, $threshold, $limit);
                $saved = $this->replaceSnapshots($wholesaler, $rows, $source::class);
                $summary['wholesalers'][] = [
                    'code' => $code,
                    'saved' => $saved,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                Log::error('Low-stock poll failed', [
                    'code' => $code,
                    'error' => $e->getMessage(),
                ]);
                $summary['wholesalers'][] = [
                    'code' => $code,
                    'saved' => 0,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function freshSnapshots(int $limit = 100, ?int $threshold = null): array
    {
        if (! Schema::hasTable('wholesaler_stock_snapshots')) {
            return [];
        }

        $threshold ??= max(0, (int) AppSetting::current()->min_stock_alert);
        if ($threshold <= 0) {
            return [];
        }

        $ttlHours = max(1, (int) config('low_stock.snapshot_ttl_hours', 12));
        $since = Carbon::now()->subHours($ttlHours);

        $rows = WholesalerStockSnapshot::query()
            ->with('wholesaler:id,code,name')
            ->where('polled_at', '>=', $since)
            ->where('stock', '>', 0)
            ->where('stock', '<', $threshold)
            ->orderBy('stock')
            ->limit(max(1, min(200, $limit)))
            ->get();

        return $rows->map(static function (WholesalerStockSnapshot $row) use ($threshold): array {
            return [
                'partNumber' => (string) $row->part_number,
                'product' => (string) $row->product_name,
                'stock' => (int) $row->stock,
                'warehouse' => (string) ($row->warehouse !== '' ? $row->warehouse : 'Sin almacén'),
                'minimum' => $threshold,
                'wholesalerName' => (string) ($row->wholesaler?->name ?? ''),
                'wholesalerCode' => (string) ($row->wholesaler?->code ?? ''),
            ];
        })->values()->all();
    }

    /**
     * @param  list<array{partNumber: string, product: string, stock: int, warehouse: string}>  $rows
     */
    private function replaceSnapshots(Wholesaler $wholesaler, array $rows, string $sourceClass): int
    {
        $now = Carbon::now();
        $source = class_basename($sourceClass);

        WholesalerStockSnapshot::query()
            ->where('wholesaler_id', $wholesaler->id)
            ->delete();

        /** @var array<string, array{part_number: string, product_name: string, stock: int, warehouse: string}> $unique */
        $unique = [];
        foreach ($rows as $row) {
            $part = mb_substr(trim((string) ($row['partNumber'] ?? '')), 0, 80);
            if ($part === '') {
                continue;
            }
            $warehouse = mb_substr(trim((string) ($row['warehouse'] ?? '')), 0, 120);
            $stock = max(0, (int) ($row['stock'] ?? 0));
            $key = strtoupper($part).'|'.strtoupper($warehouse);
            if (isset($unique[$key]) && $unique[$key]['stock'] <= $stock) {
                continue;
            }
            $unique[$key] = [
                'part_number' => $part,
                'product_name' => mb_substr(trim((string) ($row['product'] ?? $part)), 0, 255),
                'stock' => $stock,
                'warehouse' => $warehouse,
            ];
        }

        $saved = 0;
        foreach ($unique as $item) {
            WholesalerStockSnapshot::query()->create([
                'wholesaler_id' => $wholesaler->id,
                'part_number' => $item['part_number'],
                'product_name' => $item['product_name'],
                'stock' => $item['stock'],
                'warehouse' => $item['warehouse'],
                'source' => mb_substr($source, 0, 40),
                'polled_at' => $now,
            ]);
            $saved++;
        }

        return $saved;
    }

    /**
     * @param  list<string>|null  $onlyCodes
     * @return list<WholesalerLowStockSource>
     */
    private function resolveSources(?array $onlyCodes): array
    {
        /** @var array<string, class-string<WholesalerLowStockSource>> $map */
        $map = config('low_stock.sources', []);
        $filter = null;
        if ($onlyCodes !== null) {
            $filter = array_map(
                static fn (string $c): string => strtoupper(trim($c)),
                $onlyCodes,
            );
        }

        $out = [];
        foreach ($map as $code => $class) {
            $code = strtoupper((string) $code);
            if ($filter !== null && ! in_array($code, $filter, true)) {
                continue;
            }
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            $instance = app($class);
            if ($instance instanceof WholesalerLowStockSource) {
                $out[] = $instance;
            }
        }

        return $out;
    }
}
