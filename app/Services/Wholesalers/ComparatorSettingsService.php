<?php

namespace App\Services\Wholesalers;

use App\Models\ComparatorSetting;

class ComparatorSettingsService
{
    /**
     * @return array{
     *     weights: array<string, float>,
     *     warehouse_priority: list<string>,
     *     preferred_wholesaler_ids: list<string>,
     *     import_penalty: float,
     *     lead_day_penalty: float,
     *     min_stock_threshold: int
     * }
     */
    public function resolved(): array
    {
        $settings = ComparatorSetting::current();

        return [
            'weights' => $settings->normalizedWeights(),
            'warehouse_priority' => $settings->normalizedWarehousePriority(),
            'preferred_wholesaler_ids' => array_values($settings->preferred_wholesaler_ids ?? []),
            'import_penalty' => (float) $settings->import_penalty,
            'lead_day_penalty' => (float) $settings->lead_day_penalty,
            'min_stock_threshold' => (int) $settings->min_stock_threshold,
        ];
    }
}
