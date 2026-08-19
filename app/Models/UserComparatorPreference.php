<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static \Illuminate\Database\Eloquent\Builder<UserComparatorPreference> query()
 */
class UserComparatorPreference extends Model
{
    protected $table = 'user_comparator_preferences';

    protected $fillable = [
        'user_id',
        'preferred_warehouse',
        'preferred_warehouses',
        'auto_apply_best',
        'preferred_wholesaler_ids',
    ];

    protected function casts(): array
    {
        return [
            'auto_apply_best' => 'boolean',
            'preferred_wholesaler_ids' => 'array',
            'preferred_warehouses' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return list<string>
     */
    public function preferredWarehousesList(): array
    {
        $fromJson = is_array($this->preferred_warehouses) ? $this->preferred_warehouses : [];
        $list = [];
        foreach ($fromJson as $item) {
            $key = strtoupper(trim((string) $item));
            if ($key !== '' && ! in_array($key, $list, true)) {
                $list[] = $key;
            }
        }

        $primary = strtoupper(trim((string) $this->preferred_warehouse));
        if ($primary !== '' && ! in_array($primary, $list, true)) {
            array_unshift($list, $primary);
        } elseif ($primary !== '' && ($list[0] ?? null) !== $primary) {
            $list = array_values(array_unique(array_merge([$primary], $list)));
        }

        return $list !== [] ? $list : ['CDMX'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $list = $this->preferredWarehousesList();

        return [
            'preferredWarehouse' => $list[0],
            'preferredWarehouses' => $list,
            'autoApplyBest' => (bool) $this->auto_apply_best,
            'preferredWholesalerIds' => array_values($this->preferred_wholesaler_ids ?? []),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
