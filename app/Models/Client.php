<?php

namespace App\Models;

use App\Support\MexicanRfc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'clients';

    protected $fillable = [
        'company',
        'rfc',
        'address',
        'contact_name',
        'email',
        'whatsapp',
        'payment_terms',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function quoteRequests(): HasMany
    {
        return $this->hasMany(QuoteRequest::class, 'client_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'client_id');
    }

    public static function normalizeRfc(?string $rfc): ?string
    {
        return MexicanRfc::normalize($rfc);
    }

    public function scopeWhereNormalizedRfc(Builder $query, string $normalized, ?string $ignoreId = null): Builder
    {
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->whereRaw(
            "upper(replace(replace(replace(rfc, ' ', ''), '-', ''), '.', '')) = ?",
            [$normalized],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeStats = false): array
    {
        $payload = [
            'id' => $this->id,
            'company' => $this->company,
            'rfc' => $this->rfc,
            'address' => $this->address,
            'contact' => $this->contact_name,
            'email' => $this->email,
            'whatsapp' => $this->whatsapp,
            'paymentTerms' => $this->payment_terms,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($includeStats) {
            $payload['stats'] = [
                'quotesCount' => (int) ($this->quotes_count ?? 0),
                'quotesTotal' => (float) ($this->quotes_total ?? 0),
                'lastQuoteAt' => $this->last_quote_at ?? null,
            ];
        }

        return $payload;
    }
}
