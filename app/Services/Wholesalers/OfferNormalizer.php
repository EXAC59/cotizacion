<?php

namespace App\Services\Wholesalers;

class OfferNormalizer
{
    /**
     * @param  array<string, mixed>  $offer
     * @return array<string, mixed>
     */
    public static function enrich(array $offer): array
    {
        $packSize = max(1.0, (float) ($offer['packSize'] ?? $offer['pack_size'] ?? 1));
        $cost = (float) ($offer['cost'] ?? 0);
        $leadDays = max(0, (int) ($offer['leadDays'] ?? $offer['lead_days'] ?? 0));
        $stock = (int) ($offer['stock'] ?? 0);

        $availability = strtolower((string) ($offer['availabilityType'] ?? $offer['availability_type'] ?? ''));
        if (! in_array($availability, ['local', 'import'], true)) {
            $availability = ($leadDays >= 3 || $stock <= 0) ? 'import' : 'local';
        }

        $etaAt = $offer['etaAt'] ?? $offer['eta_at'] ?? null;
        if ($etaAt === null && $leadDays > 0) {
            $etaAt = now()->addDays($leadDays)->toIso8601String();
        }

        [$hasFreight, $freightNote] = self::resolveFreight($offer);

        return [
            ...$offer,
            'packSize' => $packSize,
            'unitCost' => round($cost / $packSize, 4),
            'availabilityType' => $availability,
            'etaAt' => $etaAt,
            'leadDays' => $leadDays,
            'hasFreight' => $hasFreight,
            'freightNote' => $freightNote,
        ];
    }

    /**
     * Interpreta flete:
     * 1) CEDIS / centro de distribución → sin flete (regla de negocio).
     * 2) Sucursal/plaza → con flete (o el dato de API si viene).
     * 3) Campos API del mayorista.
     * null = sin información.
     *
     * @param  array<string, mixed>  $offer
     * @return array{0: bool|null, 1: string|null}
     */
    public static function resolveFreight(array $offer): array
    {
        $warehouse = trim((string) ($offer['warehouse'] ?? ''));
        if ($warehouse !== '' && self::isCedisWarehouse($offer, $warehouse)) {
            return [false, 'Sin flete'];
        }

        $fromApi = self::resolveFreightFromApi($offer);

        if ($warehouse !== '' && self::isBranchWarehouse($offer, $warehouse)) {
            if ($fromApi[0] !== null) {
                return [$fromApi[0], $fromApi[0] ? 'Con flete' : 'Sin flete'];
            }

            return [true, 'Con flete'];
        }

        if ($fromApi[0] !== null) {
            return [$fromApi[0], $fromApi[0] ? 'Con flete' : 'Sin flete'];
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $offer
     * @return array{0: bool|null, 1: string|null}
     */
    private static function resolveFreightFromApi(array $offer): array
    {
        if (array_key_exists('hasFreight', $offer) && is_bool($offer['hasFreight'])) {
            $note = isset($offer['freightNote']) && is_string($offer['freightNote'])
                ? trim($offer['freightNote'])
                : null;

            return [$offer['hasFreight'], $note !== '' ? $note : null];
        }

        if (array_key_exists('has_freight', $offer) && is_bool($offer['has_freight'])) {
            return [$offer['has_freight'], null];
        }

        foreach (['cobro_flete', 'cobra_flete', 'flete_cobrado', 'incluye_flete'] as $key) {
            if (! array_key_exists($key, $offer)) {
                continue;
            }
            $parsed = self::boolish($offer[$key]);
            if ($parsed !== null) {
                return [$parsed, null];
            }
        }

        $tipo = strtoupper(trim((string) ($offer['tipo_flete'] ?? $offer['tipoFlete'] ?? '')));
        if ($tipo === 'SF' || $tipo === 'SIN_FLETE' || $tipo === 'SIN FLETE') {
            return [false, 'Sin flete'];
        }
        if ($tipo === 'FF' || $tipo === 'FS') {
            return [true, 'Con flete'];
        }

        foreach (['freightCost', 'freight_cost', 'costo_flete', 'flete'] as $key) {
            if (! array_key_exists($key, $offer)) {
                continue;
            }
            $val = $offer[$key];
            if (is_numeric($val)) {
                $num = (float) $val;

                return [$num > 0, $num > 0 ? 'Con flete' : 'Sin flete'];
            }
            if (is_bool($val)) {
                return [$val, null];
            }
            if (is_string($val) && trim($val) !== '') {
                $parsed = self::boolish($val);
                if ($parsed !== null) {
                    return [$parsed, null];
                }
            }
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private static function isCedisWarehouse(array $offer, string $warehouse): bool
    {
        $code = strtoupper((string) ($offer['wholesalerCode'] ?? ''));

        if ($code === 'CVA' || CvaWarehouseDirectory::preferenceValueFromName($warehouse) !== null) {
            return CvaWarehouseDirectory::isCedis($warehouse);
        }

        if ($code === 'CT' || CtWarehouseDirectory::extractCode($warehouse) !== '') {
            return CtWarehouseDirectory::isCedis($warehouse);
        }

        return str_contains(mb_strtoupper($warehouse), 'CEDIS');
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private static function isBranchWarehouse(array $offer, string $warehouse): bool
    {
        $code = strtoupper((string) ($offer['wholesalerCode'] ?? ''));

        if ($code === 'CVA' || CvaWarehouseDirectory::preferenceValueFromName($warehouse) !== null) {
            return CvaWarehouseDirectory::isBranch($warehouse);
        }

        if ($code === 'CT' || CtWarehouseDirectory::extractCode($warehouse) !== '') {
            return CtWarehouseDirectory::isBranch($warehouse);
        }

        return ! str_contains(mb_strtoupper($warehouse), 'CEDIS') && $warehouse !== '';
    }

    private static function boolish(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((float) $value) > 0;
        }
        if (! is_string($value)) {
            return null;
        }
        $v = strtolower(trim($value));
        if (in_array($v, ['1', 'true', 'si', 'sí', 'yes', 'y', 'ff', 'fs'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'no', 'n', 'sf'], true)) {
            return false;
        }

        return null;
    }
}
