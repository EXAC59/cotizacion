<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'quotes';

    protected $fillable = [
        'folio',
        'client_id',
        'request_id',
        'created_by',
        'status',
        'validity_days',
        'global_margin_percent',
        'tax_percent',
        'notes',
        'customer_observations',
        'subtotal',
        'tax_amount',
        'total',
        'sent_at',
        'response_received_at',
        'last_opened_at',
        'invoice_number',
        'follow_up_status',
        'follow_up_invoice',
        'follow_up_comments',
        'follow_up_remind_at',
        'follow_up_at',
        'follow_up_by',
        'locked_by',
        'locked_at',
        'involucrado',
    ];

    protected function casts(): array
    {
        return [
            'validity_days' => 'integer',
            'global_margin_percent' => 'decimal:2',
            'tax_percent' => 'decimal:2',
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'sent_at' => 'datetime',
            'response_received_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'follow_up_remind_at' => 'datetime',
            'follow_up_at' => 'datetime',
            'locked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class, 'quote_id')->orderBy('line_order');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(QuoteStatusEvent::class, 'quote_id')->orderBy('created_at');
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(QuoteInternalNote::class, 'quote_id')->orderByDesc('created_at');
    }

    public function followUpByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follow_up_by');
    }

    public function followUpEvents(): HasMany
    {
        return $this->hasMany(QuoteFollowUpEvent::class, 'quote_id')->orderByDesc('created_at');
    }

    public function salesNotifications(): HasMany
    {
        return $this->hasMany(SalesNotification::class, 'quote_id')->orderByDesc('created_at');
    }
}
