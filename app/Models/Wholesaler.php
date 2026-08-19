<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static \Illuminate\Database\Eloquent\Builder<Wholesaler> query()
 */
class Wholesaler extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'wholesalers';

    protected $fillable = [
        'code',
        'name',
        'integration',
        'active',
        'config_json',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'config_json' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function isConfigured(): bool
    {
        $prefix = $this->config_json['env_prefix'] ?? null;
        if (! $prefix) {
            return false;
        }

        $baseUrl = env("{$prefix}_BASE_URL");
        $apiKey = env("{$prefix}_API_KEY");
        $csvPath = env("{$prefix}_CSV_PATH");

        if ($this->integration === 'csv') {
            return filled($csvPath) || filled($baseUrl);
        }

        if ($this->code === 'CT') {
            $email = env("{$prefix}_EMAIL");
            $cliente = env("{$prefix}_CLIENTE");
            $rfc = env("{$prefix}_RFC");

            return filled($apiKey) || (filled($email) && filled($cliente) && filled($rfc));
        }

        if ($this->code === 'CVA') {
            $user = env("{$prefix}_USER");
            $password = env("{$prefix}_PASSWORD");

            return filled($user) && filled($password);
        }

        return filled($baseUrl) || filled($apiKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'integration' => $this->integration,
            'active' => $this->active,
            'configured' => $this->isConfigured(),
            'config' => [
                'envPrefix' => $this->config_json['env_prefix'] ?? null,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
