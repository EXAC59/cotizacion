<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuoteLine extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'quote_lines';

    protected $fillable = [
        'quote_id',
        'line_order',
        'quantity',
        'product',
        'part_number',
        'cost',
        'margin_percent',
        'sale_price',
        'amount',
        'warehouse',
        'selected_wholesaler_id',
    ];

    protected function casts(): array
    {
        return [
            'line_order' => 'integer',
            'quantity' => 'decimal:4',
            'cost' => 'decimal:4',
            'margin_percent' => 'decimal:2',
            'sale_price' => 'decimal:4',
            'amount' => 'decimal:4',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(QuoteLineOffer::class, 'quote_line_id');
    }
}
