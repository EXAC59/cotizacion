<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static \Illuminate\Database\Eloquent\Builder<WholesalerStockSnapshot> query()
 */
class WholesalerStockSnapshot extends Model
{
    protected $table = 'wholesaler_stock_snapshots';

    protected $fillable = [
        'wholesaler_id',
        'part_number',
        'product_name',
        'stock',
        'warehouse',
        'source',
        'polled_at',
    ];

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'polled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function wholesaler(): BelongsTo
    {
        return $this->belongsTo(Wholesaler::class);
    }
}
