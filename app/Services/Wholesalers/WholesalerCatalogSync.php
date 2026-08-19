<?php

namespace App\Services\Wholesalers;

use App\Models\Wholesaler;
use Illuminate\Support\Str;

class WholesalerCatalogSync
{
    /**
     * @return array{created: int, updated: int, total: int}
     */
    public function sync(): array
    {
        $created = 0;
        $updated = 0;

        foreach (config('wholesalers.catalog', []) as $entry) {
            $code = strtoupper((string) ($entry['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $existing = Wholesaler::query()->where('code', $code)->first();
            $payload = [
                'name' => (string) ($entry['name'] ?? $code),
                'integration' => (string) ($entry['integration'] ?? 'api'),
                'config_json' => [
                    'env_prefix' => (string) ($entry['env_prefix'] ?? "WHOLESALER_{$code}"),
                ],
            ];

            if ($existing === null) {
                Wholesaler::query()->create([
                    'id' => (string) Str::uuid(),
                    'code' => $code,
                    'active' => (bool) ($entry['active'] ?? false),
                    ...$payload,
                ]);
                $created++;

                continue;
            }

            $existing->update($payload);
            $updated++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'total' => Wholesaler::query()->count(),
        ];
    }
}
