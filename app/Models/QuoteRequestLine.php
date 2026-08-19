<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static \Illuminate\Database\Eloquent\Builder<QuoteRequestLine> query()
 */
class QuoteRequestLine extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'quote_request_lines';

    public $timestamps = false;

    protected $fillable = [
        'request_id',
        'line_order',
        'quantity',
        'product',
        'part_number',
        'brand',
        'description',
        'unit',
        'reference_cost',
        'selected_wholesaler_id',
        'warehouse',
        'comparator_offers_json',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'line_order' => 'integer',
            'reference_cost' => 'decimal:2',
            'comparator_offers_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class, 'request_id');
    }
}
