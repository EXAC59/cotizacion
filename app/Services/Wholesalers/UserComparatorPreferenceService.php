<?php

namespace App\Services\Wholesalers;

use App\Models\User;
use App\Models\UserComparatorPreference;

class UserComparatorPreferenceService
{
    public function __construct(
        private readonly WholesalerWarehouseCatalogService $warehouseCatalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forEmail(?string $email): array
    {
        if ($email === null || trim($email) === '') {
            return $this->defaults(null);
        }

        $user = User::query()->where('email', '=', trim($email))->first();
        if ($user === null) {
            return $this->defaults(null);
        }

        $pref = UserComparatorPreference::query()->where('user_id', '=', $user->id)->first();
        if ($pref === null) {
            return $this->defaults($user);
        }

        $payload = array_merge($pref->toApiArray(), $this->warehousePayload($user));
        $list = $this->resolvePreferredList([
            'preferredWarehouses' => $payload['preferredWarehouses'] ?? [],
            'preferredWarehouse' => $payload['preferredWarehouse'] ?? null,
        ]);
        $list = $this->sanitizePreferredList($list);
        $payload['preferredWarehouses'] = $list;
        $payload['preferredWarehouse'] = $list[0];

        return $this->maskAvailableWarehousesIfNeeded($user, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function upsertForEmail(?string $email, array $payload): array
    {
        if ($email === null || trim($email) === '') {
            return $this->defaults(null);
        }

        $user = User::query()->where('email', '=', trim($email))->firstOrFail();

        $list = $this->sanitizePreferredList($this->resolvePreferredList($payload));
        $primary = $list[0];

        $pref = UserComparatorPreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'preferred_warehouse' => $primary,
                'preferred_warehouses' => $list,
                'auto_apply_best' => (bool) ($payload['autoApplyBest'] ?? false),
                'preferred_wholesaler_ids' => array_values($payload['preferredWholesalerIds'] ?? []),
            ],
        );

        return $this->maskAvailableWarehousesIfNeeded($user, array_merge(
            $pref->fresh()->toApiArray(),
            $this->warehousePayload($user),
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function resolvePreferredList(array $payload): array
    {
        $defaults = CtWarehouseDirectory::defaultPreferredWarehouses();
        $raw = $payload['preferredWarehouses'] ?? null;
        if (! is_array($raw) || $raw === []) {
            $single = trim((string) ($payload['preferredWarehouse'] ?? $defaults[0]));
            $raw = $single !== '' ? [$single] : $defaults;
        }

        $list = [];
        foreach ($raw as $item) {
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }

            $nna = CtWarehouseDirectory::extractCode($text);
            if ($nna !== '' && CtWarehouseDirectory::entry($nna) !== null) {
                if (! in_array($nna, $list, true)) {
                    $list[] = $nna;
                }

                continue;
            }

            $key = strtoupper($text);
            if (self::isNonCtWarehouseKey($key)) {
                if (! in_array($key, $list, true)) {
                    $list[] = $key;
                }

                continue;
            }

            $normalized = CtWarehouseDirectory::matchKey($text);
            if ($normalized !== '' && ! in_array($normalized, $list, true)) {
                $list[] = $normalized;
            }
        }

        return $list !== [] ? $list : $defaults;
    }

    /**
     * @param  list<string>  $list
     * @return list<string>
     */
    private function sanitizePreferredList(array $list): array
    {
        if ($this->shouldUpgradeLegacyDefaults($list)) {
            return CtWarehouseDirectory::defaultPreferredWarehouses();
        }

        // Quitar plaza Monterrey (24A / MTY); CEDIS Monterrey (53A / CMT) se conserva.
        $list = array_values(array_filter(
            $list,
            static fn (string $code): bool => ! in_array(strtoupper($code), ['MTY', '24A'], true),
        ));

        $list = $this->ensureCtCedisIncluded($list);

        return $list !== [] ? $list : CtWarehouseDirectory::defaultPreferredWarehouses();
    }

    /**
     * Los CEDIS CT (D2A, 53A, 35A) siempre quedan marcados y al frente.
     *
     * @param  list<string>  $list
     * @return list<string>
     */
    private function ensureCtCedisIncluded(array $list): array
    {
        $cedis = ['D2A', '53A', '35A'];
        $rest = [];
        foreach ($list as $code) {
            $upper = strtoupper(trim($code));
            if ($upper === '' || in_array($upper, ['D2A', '53A', '35A', 'CMT'], true)) {
                continue;
            }
            if (! in_array($upper, $rest, true)) {
                $rest[] = $upper;
            }
        }

        return array_values(array_merge($cedis, $rest));
    }

    /**
     * @param  list<string>  $list
     */
    private function shouldUpgradeLegacyDefaults(array $list): bool
    {
        $normalized = array_values(array_unique(array_map(
            static fn (string $c): string => strtoupper(trim($c)),
            $list,
        )));
        sort($normalized);

        $oldCtOnly = ['01A', '35A', '53A', 'D2A'];
        sort($oldCtOnly);

        return $normalized === ['CDMX']
            || $normalized === ['MTY']
            || $normalized === ['CDMX', 'MTY']
            || $normalized === ['CMT', 'D2A', 'MTY']
            || $normalized === $oldCtOnly
            || $normalized === [];
    }

    private static function isNonCtWarehouseKey(string $key): bool
    {
        if (str_starts_with($key, 'CVA-')) {
            return true;
        }

        return in_array($key, ['SUCURSAL CVA', 'CEDIS GUADALAJARA'], true);
    }

    /**
     * @return array{availableWarehouses: list<mixed>, availableWarehousesByWholesaler: list<mixed>}
     */
    private function warehousePayload(?User $user): array
    {
        return [
            'availableWarehouses' => $this->warehouseCatalog->flatOptions($user),
            'availableWarehousesByWholesaler' => $this->warehouseCatalog->groups($user),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function maskAvailableWarehousesIfNeeded(User $user, array $payload): array
    {
        if (! app(WholesalerSalesAliasService::class)->shouldMask($user)) {
            return $payload;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(?User $user): array
    {
        $list = CtWarehouseDirectory::defaultPreferredWarehouses();

        return array_merge([
            'preferredWarehouse' => $list[0],
            'preferredWarehouses' => $list,
            'autoApplyBest' => false,
            'preferredWholesalerIds' => [],
            'updatedAt' => null,
        ], $this->warehousePayload($user));
    }
}
