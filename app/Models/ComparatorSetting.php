<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComparatorSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $keyType = 'int';

    protected $table = 'comparator_settings';

    protected $fillable = [
        'id',
        'weights',
        'warehouse_priority',
        'preferred_wholesaler_ids',
        'import_penalty',
        'lead_day_penalty',
        'min_stock_threshold',
    ];

    protected function casts(): array
    {
        return [
            'weights' => 'array',
            'warehouse_priority' => 'array',
            'preferred_wholesaler_ids' => 'array',
            'import_penalty' => 'decimal:4',
            'lead_day_penalty' => 'decimal:4',
            'min_stock_threshold' => 'integer',
        ];
    }

    public static function current(): self
    {
        $settings = static::query()->find(1);

        if ($settings !== null) {
            return $settings;
        }

        return static::query()->create([
            'id' => 1,
            'weights' => config('quote_comparator.weights'),
            'warehouse_priority' => config('quote_comparator.warehouse_priority'),
            'preferred_wholesaler_ids' => [],
            'import_penalty' => config('quote_comparator.import_penalty', 0.08),
            'lead_day_penalty' => config('quote_comparator.lead_day_penalty', 0.005),
            'min_stock_threshold' => config('quote_comparator.min_stock_threshold', 1),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'weights' => $this->normalizedWeights(),
            'warehousePriority' => $this->normalizedWarehousePriority(),
            'availableWarehouses' => \App\Services\Wholesalers\CtWarehouseDirectory::regionOptions(),
            'preferredWholesalerIds' => array_values($this->preferred_wholesaler_ids ?? []),
            'importPenalty' => (float) $this->import_penalty,
            'leadDayPenalty' => (float) $this->lead_day_penalty,
            'minStockThreshold' => (int) $this->min_stock_threshold,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, float>
     */
    public function normalizedWeights(): array
    {
        $defaults = config('quote_comparator.weights', []);
        $stored = is_array($this->weights) ? $this->weights : [];

        return [
            'price' => (float) ($stored['price'] ?? $defaults['price'] ?? 0.45),
            'stock' => (float) ($stored['stock'] ?? $defaults['stock'] ?? 0.25),
            'warehouse' => (float) ($stored['warehouse'] ?? $defaults['warehouse'] ?? 0.15),
            'performance' => (float) ($stored['performance'] ?? $defaults['performance'] ?? 0.10),
            'preferred' => (float) ($stored['preferred'] ?? $defaults['preferred'] ?? 0.05),
        ];
    }

    /**
     * @return list<string>
     */
    public function normalizedWarehousePriority(): array
    {
        $stored = $this->warehouse_priority;
        if (is_array($stored) && $stored !== []) {
            return array_values(array_map(fn ($w) => strtoupper((string) $w), $stored));
        }

        return array_map('strtoupper', config('quote_comparator.warehouse_priority', []));
    }
}
