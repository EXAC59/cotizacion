<?php

namespace App\Services\Wholesalers;

use App\Models\User;

/**
 * Enmascara nombres/códigos de mayoristas para roles de ventas (BODEGAxx).
 */
class WholesalerSalesAliasService
{
    public function shouldMask(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $user->loadMissing('role');
        $slug = (string) ($user->role_slug ?? '');
        $roles = config('wholesaler_sales_aliases.roles', ['ventas']);

        return is_array($roles) && in_array($slug, $roles, true);
    }

    public function aliasForCode(?string $code): string
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return (string) config('wholesaler_sales_aliases.fallback_prefix', 'BODEGA');
        }

        /** @var array<string, string> $aliases */
        $aliases = config('wholesaler_sales_aliases.aliases', []);
        if (isset($aliases[$code]) && is_string($aliases[$code]) && $aliases[$code] !== '') {
            return $aliases[$code];
        }

        $prefix = (string) config('wholesaler_sales_aliases.fallback_prefix', 'BODEGA');

        // Determinístico para codes nuevos sin reasignar los fijos.
        $n = (crc32($code) % 70) + 30; // BODEGA30–BODEGA99

        return sprintf('%s%02d', $prefix, $n);
    }

    public function aliasForName(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $needle = mb_strtolower($name);
        foreach (config('wholesalers.catalog', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $entryName = mb_strtolower(trim((string) ($entry['name'] ?? '')));
            $code = strtoupper(trim((string) ($entry['code'] ?? '')));
            if ($code === '' || $entryName === '') {
                continue;
            }
            if ($entryName === $needle || str_contains($needle, $entryName) || str_contains($entryName, $needle)) {
                return $this->aliasForCode($code);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $offer
     * @return array<string, mixed>
     */
    public function maskOffer(array $offer): array
    {
        $code = (string) ($offer['wholesalerCode'] ?? '');
        $alias = $code !== ''
            ? $this->aliasForCode($code)
            : ($this->aliasForName((string) ($offer['wholesalerName'] ?? ''))
                ?? (string) config('wholesaler_sales_aliases.fallback_prefix', 'BODEGA'));

        $offer['wholesalerName'] = $alias;
        if ($code !== '') {
            $offer['wholesalerCode'] = $alias;
        }

        if (isset($offer['warehouse']) && is_string($offer['warehouse']) && $offer['warehouse'] !== '') {
            $wh = $offer['warehouse'];
            $originalCode = strtoupper($code);
            if ($originalCode === 'CVA' || CvaWarehouseDirectory::preferenceValueFromName($wh) !== null) {
                $offer['warehouse'] = CvaWarehouseDirectory::formatLabelMasked($wh);
            } else {
                $offer['warehouse'] = CtWarehouseDirectory::formatLabelMasked($wh);
            }
        }

        return $offer;
    }

    /**
     * @param  list<array<string, mixed>>|array<string, mixed>|null  $offers
     * @return list<array<string, mixed>>|array<string, mixed>|null
     */
    public function maskOffers(mixed $offers): mixed
    {
        if (! is_array($offers)) {
            return $offers;
        }

        if ($offers === []) {
            return [];
        }

        // Lista de ofertas vs un solo "best"
        $isList = array_is_list($offers);
        if (! $isList && isset($offers['wholesalerId'])) {
            return $this->maskOffer($offers);
        }

        return array_map(
            fn (mixed $row): mixed => is_array($row) ? $this->maskOffer($row) : $row,
            $offers,
        );
    }

    public function maskErrorMessage(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $out = $message;
        foreach (config('wholesalers.catalog', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            $code = strtoupper(trim((string) ($entry['code'] ?? '')));
            if ($name === '' || $code === '') {
                continue;
            }
            $alias = $this->aliasForCode($code);
            $out = str_ireplace($name, $alias, $out);
            $out = preg_replace('/\b'.preg_quote($code, '/').'\b/i', $alias, $out) ?? $out;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload  resultado compare / job API
     * @return array<string, mixed>
     */
    public function maskComparatorPayload(array $payload): array
    {
        if (isset($payload['offers']) && is_array($payload['offers'])) {
            $payload['offers'] = $this->maskOffers($payload['offers']);
        }
        if (isset($payload['best']) && is_array($payload['best'])) {
            $payload['best'] = $this->maskOffer($payload['best']);
        }
        if (isset($payload['notFound']) && is_array($payload['notFound'])) {
            $payload['notFound'] = array_map(function (mixed $row): mixed {
                if (! is_array($row)) {
                    return $row;
                }
                $originalCode = strtoupper((string) ($row['wholesalerCode'] ?? ''));
                $code = $originalCode;
                $alias = $code !== ''
                    ? $this->aliasForCode($code)
                    : ($this->aliasForName((string) ($row['wholesalerName'] ?? ''))
                        ?? (string) config('wholesaler_sales_aliases.fallback_prefix', 'BODEGA'));
                $row['wholesalerName'] = $alias;
                if ($code !== '') {
                    $row['wholesalerCode'] = $alias;
                }
                if (isset($row['message']) && is_string($row['message'])) {
                    $row['message'] = $this->maskErrorMessage($row['message']);
                }
                if (isset($row['warehouses']) && is_array($row['warehouses'])) {
                    $row['warehouses'] = array_map(function (mixed $wh) use ($originalCode): mixed {
                        if (! is_array($wh)) {
                            return $wh;
                        }
                        $label = trim((string) ($wh['label'] ?? $wh['code'] ?? ''));
                        if ($label === '') {
                            return $wh;
                        }
                        $whCode = strtoupper((string) ($wh['code'] ?? ''));
                        if ($originalCode === 'CVA' || str_starts_with($whCode, 'CVA-')) {
                            $wh['label'] = CvaWarehouseDirectory::formatLabelMasked($label);
                        } else {
                            $wh['label'] = CtWarehouseDirectory::formatLabelMasked($label);
                        }

                        return $wh;
                    }, $row['warehouses']);
                }

                return $row;
            }, $payload['notFound']);
        }
        if (isset($payload['notFoundSummary']) && is_string($payload['notFoundSummary'])) {
            $payload['notFoundSummary'] = $this->maskErrorMessage($payload['notFoundSummary']);
        }
        if (isset($payload['errorMessage'])) {
            $payload['errorMessage'] = $this->maskErrorMessage(
                is_string($payload['errorMessage']) ? $payload['errorMessage'] : null,
            );
        }
        if (isset($payload['lookupError']) && is_string($payload['lookupError'])) {
            $payload['lookupError'] = $this->maskErrorMessage($payload['lookupError']);
        }

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $results  compareBatch
     * @return list<array<string, mixed>>
     */
    public function maskBatchResults(array $results): array
    {
        return array_map(
            fn (mixed $row): mixed => is_array($row) ? $this->maskComparatorPayload($row) : $row,
            $results,
        );
    }
}
