<?php

namespace App\Services\Wholesalers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WholesalerPerformanceService
{
    /**
     * @param  list<string>  $wholesalerIds
     * @return array<string, float> wholesaler_id => score 0..1
     */
    public function scoresForWholesalers(array $wholesalerIds): array
    {
        if ($wholesalerIds === []) {
            return [];
        }

        sort($wholesalerIds);
        $cacheKey = 'wholesaler_performance:'.md5(implode(',', $wholesalerIds));

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($wholesalerIds) {
            $totals = DB::table('quote_line_offers')
                ->whereIn('wholesaler_id', $wholesalerIds)
                ->selectRaw('wholesaler_id, COUNT(*) as total')
                ->groupBy('wholesaler_id')
                ->pluck('total', 'wholesaler_id');

            $won = DB::table('quote_line_offers')
                ->join('quote_lines', 'quote_lines.id', '=', 'quote_line_offers.quote_line_id')
                ->join('quotes', 'quotes.id', '=', 'quote_lines.quote_id')
                ->whereIn('quote_line_offers.wholesaler_id', $wholesalerIds)
                ->whereIn('quotes.status', config('quotes.won_statuses', ['aceptada', 'facturada']))
                ->where('quote_line_offers.is_selected', true)
                ->selectRaw('quote_line_offers.wholesaler_id as wholesaler_id, COUNT(*) as won')
                ->groupBy('quote_line_offers.wholesaler_id')
                ->pluck('won', 'wholesaler_id');

            $scores = [];
            foreach ($wholesalerIds as $id) {
                $total = (int) ($totals[$id] ?? 0);
                $wonCount = (int) ($won[$id] ?? 0);

                if ($total === 0) {
                    $scores[$id] = 0.5;

                    continue;
                }

                $ratio = $wonCount / max(1, $total);
                $volumeBoost = min(0.15, $total / 100);
                $scores[$id] = min(1.0, max(0.0, ($ratio * 0.85) + 0.15 + $volumeBoost));
            }

            return $scores;
        });
    }
}
