<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuoteRequest extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'quote_requests';

    protected $fillable = [
        'client_id',
        'created_by',
        'folio',
        'source',
        'status',
        'workflow_status',
        'reviewed_by',
        'reviewed_at',
        'file_name',
        'file_path',
        'raw_text',
        'n8n_workflow_id',
        'error_message',
        'interpretacion_via',
        'pricing_uploaded_at',
        'involucrado',
    ];

    protected function casts(): array
    {
        return [
            'pricing_uploaded_at' => 'datetime',
            'reviewed_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteRequestLine::class, 'request_id')->orderBy('line_order');
    }
}
