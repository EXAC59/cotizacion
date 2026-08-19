<?php

namespace App\Services\Wholesalers;

use App\Models\Wholesaler;
use Illuminate\Support\Facades\DB;

class WholesalerDemoOfferService
{
    /**
     * Genera ofertas simuladas para mayoristas activos (sin API keys).
     * Solo usa filas reales de `wholesalers` — no inventa Ingram/Exel ficticios.
     *
     * @return list<array<string, mixed>>
     */
    public function buildDemoOffers(string $partNumber, float $quantity = 1): array
    {
        if (! config('quote_comparator.demo_offers', false)) {
            return [];
        }

        $wholesalers = Wholesaler::query()->where('active', '=', true)->orderBy('name', 'asc')->get();
        if ($wholesalers->isEmpty()) {
            return [];
        }

        $wholesalerRows = $wholesalers->map(fn (Wholesaler $w): array => [
            'id' => (string) $w->id,
            'code' => (string) $w->code,
            'name' => (string) $w->name,
        ])->all();

        $baseCost = $this->resolveBaseCost($partNumber);
        $inventory = $this->inventoryByPart($partNumber);
        $variants = config('quote_comparator.demo_price_variants', [1.0]);
        $warehouses = config('quote_comparator.demo_warehouses', ['CDMX', 'MTY', 'GDL']);

        $offers = [];
        foreach ($wholesalerRows as $index => $wholesaler) {
            $variant = $variants[$index % count($variants)];
            $warehouse = $warehouses[$index % count($warehouses)];
            $stock = max(0, (int) ($inventory['stock'] ?? 12) - ($index * 2));
            $leadDays = $index % 3;
            $isImport = $index % 3 === 2;
            $availabilityType = $isImport ? 'import' : 'local';
            if ($isImport) {
                $leadDays = max($leadDays, 5);
            }

            $cost = round($baseCost * $variant, 2);

            $offers[] = OfferNormalizer::enrich([
                'wholesalerId' => $wholesaler['id'],
                'wholesalerCode' => $wholesaler['code'],
                'wholesalerName' => $wholesaler['name'],
                'partNumber' => $partNumber,
                'cost' => $cost,
                'stock' => $stock,
                'warehouse' => $inventory['warehouse'] ?? $warehouse,
                'leadDays' => $leadDays,
                'availabilityType' => $availabilityType,
                'packSize' => 1,
            ]);
        }

        return $offers;
    }

    private function resolveBaseCost(string $partNumber): float
    {
        $catalog = config('quote_comparator.demo_base_costs', []);
        if (isset($catalog[$partNumber])) {
            return (float) $catalog[$partNumber];
        }

        $hash = crc32(strtoupper($partNumber));

        return 1500 + ($hash % 8000);
    }

    /**
     * @return array{stock?: int, warehouse?: string}
     */
    private function inventoryByPart(string $partNumber): array
    {
        if (! DB::getSchemaBuilder()->hasTable('inventory_items')) {
            return [];
        }

        $row = DB::table('inventory_items')
            ->where('part_number', $partNumber)
            ->orderByDesc('stock')
            ->first();

        if ($row === null) {
            return [];
        }

        return [
            'stock' => (int) $row->stock,
            'warehouse' => (string) $row->warehouse,
        ];
    }
}
