<?php

namespace App\Services\Wholesalers\LowStock\Sources;

use App\Contracts\WholesalerLowStockSource;
use App\Models\Wholesaler;
use App\Services\Wholesalers\Connectors\CtInternacionalConnector;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * CT no publica catálogo con stock: consulta SKUs recientes (cotizaciones / comparador).
 */
class CtWatchedSkusLowStockSource implements WholesalerLowStockSource
{
    public function __construct(private readonly CtInternacionalConnector $connector) {}

    public function wholesalerCode(): string
    {
        return 'CT';
    }

    public function supports(Wholesaler $wholesaler): bool
    {
        return strtoupper($wholesaler->code) === 'CT';
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function collect(Wholesaler $wholesaler, int $threshold, int $limit): array
    {
        if (! $wholesaler->isConfigured()) {
            return [];
        }

        $skus = $this->watchedSkus();
        if ($skus === []) {
            return [];
        }

        $usleep = max(0, (int) config('low_stock.api_lookup_usleep', 150_000));
        $out = [];

        foreach ($skus as $sku => $product) {
            if (count($out) >= $limit) {
                break;
            }

            try {
                $offers = $this->connector->lookup($wholesaler, $sku);
            } catch (\Throwable $e) {
                Log::warning('CT low-stock poll lookup failed', [
                    'sku' => $sku,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($offers as $offer) {
                if ($offer->error !== null) {
                    continue;
                }
                $stock = (int) $offer->stock;
                if ($stock <= 0 || $stock >= $threshold) {
                    continue;
                }
                $out[] = [
                    'partNumber' => $offer->partNumber !== '' ? $offer->partNumber : $sku,
                    'product' => $offer->description !== '' ? $offer->description : $product,
                    'stock' => $stock,
                    'warehouse' => $offer->warehouse !== '' ? $offer->warehouse : 'Sin almacén',
                ];
            }

            if ($usleep > 0) {
                usleep($usleep);
            }
        }

        usort($out, static fn (array $a, array $b): int => $a['stock'] <=> $b['stock']);

        return array_slice($out, 0, $limit);
    }

    /**
     * @return array<string, string> sku => product label
     */
    private function watchedSkus(): array
    {
        $lookbackDays = max(1, (int) config('low_stock.watched_sku_lookback_days', 30));
        $limit = max(1, min(200, (int) config('low_stock.watched_sku_limit', 40)));
        $since = Carbon::now()->subDays($lookbackDays);
        /** @var array<string, string> $skus */
        $skus = [];

        if (Schema::hasTable('quote_lines') && Schema::hasTable('quotes')) {
            $rows = DB::table('quote_lines')
                ->join('quotes', 'quotes.id', '=', 'quote_lines.quote_id')
                ->where('quotes.created_at', '>=', $since)
                ->whereNotNull('quote_lines.part_number')
                ->where('quote_lines.part_number', '!=', '')
                ->orderByDesc('quotes.created_at')
                ->limit($limit * 3)
                ->get(['quote_lines.part_number', 'quote_lines.product']);

            foreach ($rows as $row) {
                $sku = trim((string) $row->part_number);
                if ($sku === '' || isset($skus[$sku])) {
                    continue;
                }
                $skus[$sku] = trim((string) ($row->product ?? '')) ?: $sku;
                if (count($skus) >= $limit) {
                    return $skus;
                }
            }
        }

        if (Schema::hasTable('comparison_jobs') && count($skus) < $limit) {
            $jobs = DB::table('comparison_jobs')
                ->where('created_at', '>=', $since)
                ->whereNotNull('part_number')
                ->where('part_number', '!=', '')
                ->orderByDesc('created_at')
                ->limit($limit * 2)
                ->get(['part_number']);

            foreach ($jobs as $job) {
                $sku = trim((string) $job->part_number);
                if ($sku === '' || isset($skus[$sku])) {
                    continue;
                }
                $skus[$sku] = $sku;
                if (count($skus) >= $limit) {
                    break;
                }
            }
        }

        return $skus;
    }
}
