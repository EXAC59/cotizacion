<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteLineOffer extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $table = 'quote_line_offers';

    protected $fillable = [
        'quote_line_id',
        'wholesaler_id',
        'cost',
        'stock',
        'warehouse',
        'lead_days',
        'is_selected',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:4',
            'stock' => 'integer',
            'lead_days' => 'integer',
            'is_selected' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function quoteLine(): BelongsTo
    {
        return $this->belongsTo(QuoteLine::class, 'quote_line_id');
    }

    public function wholesaler(): BelongsTo
    {
        return $this->belongsTo(Wholesaler::class, 'wholesaler_id');
    }
}
