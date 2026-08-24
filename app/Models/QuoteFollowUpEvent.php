<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteFollowUpEvent extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'quote_id',
        'user_id',
        'from_status',
        'to_status',
        'remind_at',
        'invoice',
        'comments',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
