<?php

namespace App\Services\Wholesalers;

/**
 * Sucursales / CEDIS Grupo CVA (catálogo API catalogo_clientes/sucursales).
 */
final class CvaWarehouseDirectory
{
    /**
     * @return list<array{value: string, label: string, city: string, code: string, region: string}>
     */
    public static function branchOptions(): array
    {
        /** @var array<string, array{name?: string, cp?: string, region?: string, kind?: string}> $catalog */
        $catalog = config('cva_warehouses', []);
        $rows = [];

        foreach ($catalog as $clave => $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = (string) $clave;
            $name = trim((string) ($row['name'] ?? $code));
            $region = strtoupper((string) ($row['region'] ?? $code));
            $value = self::preferenceValue($code);
            $kind = (string) ($row['kind'] ?? 'sucursal');
            $label = $kind === 'cedis'
                ? "{$name} ({$value})"
                : "{$name} ({$value})";

            $rows[] = [
                'value' => $value,
                'label' => $label,
                'city' => $name,
                'code' => $code,
                'region' => $region !== '' ? $region : $value,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $ka = str_starts_with($a['city'], 'CEDIS') ? '0' : '1';
            $kb = str_starts_with($b['city'], 'CEDIS') ? '0' : '1';
            if ($ka !== $kb) {
                return $ka <=> $kb;
            }

            return strcasecmp($a['city'], $b['city']);
        });

        return $rows;
    }

    public static function preferenceValue(string $clave): string
    {
        return 'CVA-'.trim($clave);
    }

    /**
     * Resuelve un nombre de inventario CVA (p. ej. "GUADALAJARA", "CEDIS GUADALAJARA")
     * al value de preferencia CVA-{clave}.
     */
    public static function preferenceValueFromName(string $warehouseName): ?string
    {
        $normalized = self::normalizeName($warehouseName);
        if ($normalized === '') {
            return null;
        }

        // Preferencias ya en formato CVA-xx
        if (preg_match('/^CVA-(\d+)$/i', $normalized, $m) === 1) {
            return self::preferenceValue($m[1]);
        }

        /** @var array<string, array{name?: string, region?: string}> $catalog */
        $catalog = config('cva_warehouses', []);
        foreach ($catalog as $clave => $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = self::normalizeName((string) ($row['name'] ?? ''));
            if ($name !== '' && $name === $normalized) {
                return self::preferenceValue((string) $clave);
            }
        }

        // Legado UI
        if ($normalized === 'SUCURSAL CVA') {
            return self::preferenceValue('24'); // CDMX genérico
        }
        if ($normalized === 'CEDIS GUADALAJARA' || $normalized === 'CEDIS GDL') {
            return self::preferenceValue('46');
        }

        // Nombres de inventario API CVA (centro de distribución = CEDIS)
        if (
            $normalized === 'CENTRO DE DISTRIBUCION GUADALAJARA'
            || $normalized === 'CENTRO DE DISTRIBUCION GDL'
        ) {
            return self::preferenceValue('46');
        }
        if (
            $normalized === 'CENTRO DE DISTRIBUCION MEXICO'
            || $normalized === 'CENTRO DE DISTRIBUCION CDMX'
            || $normalized === 'CENTRO DE DISTRIBUCION CDMX CENTRO SUR'
            || $normalized === 'CEDIS CDMX CENTRO SUR'
            || $normalized === 'CEDIS CDMX'
        ) {
            return self::preferenceValue('51');
        }
        if (
            $normalized === 'CENTRO DE DISTRIBUCION MONTERREY'
            || $normalized === 'CENTRO DE DISTRIBUCION MTY'
        ) {
            return self::preferenceValue('54');
        }

        return null;
    }

    /**
     * Etiqueta para ventas: ciudad visible, sin revelar “Grupo CVA”.
     * Ej. "Almacén CEDIS Guadalajara", "Almacén Querétaro".
     */
    public static function formatLabelMasked(string $warehouseNameOrValue): string
    {
        $value = self::preferenceValueFromName($warehouseNameOrValue);
        if ($value === null) {
            $raw = trim($warehouseNameOrValue);

            return $raw !== '' ? 'Almacén '.$raw : 'Almacén';
        }

        $clave = substr($value, 4);
        /** @var array{name?: string, region?: string, kind?: string}|null $row */
        $row = config('cva_warehouses.'.$clave);
        $name = is_array($row) ? trim((string) ($row['name'] ?? '')) : '';
        if ($name === '') {
            return 'Almacén '.$value;
        }

        $place = preg_replace('/^CEDIS\s+/iu', '', $name) ?: $name;
        $kind = is_array($row) ? strtolower((string) ($row['kind'] ?? 'sucursal')) : 'sucursal';
        if ($kind === 'cedis' || str_starts_with(self::normalizeName($name), 'CEDIS')) {
            return 'Almacén CEDIS '.$place;
        }

        return 'Almacén '.$place;
    }

    /** Ciudad / plaza legible (sin prefijo CEDIS) para subtítulos. */
    public static function cityLabel(string $warehouseNameOrValue): string
    {
        $value = self::preferenceValueFromName($warehouseNameOrValue);
        if ($value === null) {
            return trim($warehouseNameOrValue);
        }

        $clave = substr($value, 4);
        /** @var array{name?: string}|null $row */
        $row = config('cva_warehouses.'.$clave);
        $name = is_array($row) ? trim((string) ($row['name'] ?? '')) : '';
        if ($name === '') {
            return $value;
        }

        return preg_replace('/^CEDIS\s+/iu', '', $name) ?: $name;
    }

    /**
     * CEDIS CVA (kind=cedis en config): sin cobro de flete por producto.
     * La API a veces llama al almacén «CENTRO DE DISTRIBUCION …» en lugar de «CEDIS …».
     */
    public static function isCedis(string $warehouseNameOrValue): bool
    {
        $raw = trim($warehouseNameOrValue);
        if ($raw === '') {
            return false;
        }

        $normalized = self::normalizeName($raw);
        if (
            str_contains($normalized, 'CEDIS')
            || str_contains($normalized, 'CENTRO DE DISTRIBUCION')
        ) {
            return true;
        }

        $value = self::preferenceValueFromName($raw);
        if ($value === null) {
            return false;
        }

        $clave = substr($value, 4);
        /** @var array{kind?: string}|null $row */
        $row = config('cva_warehouses.'.$clave);

        return is_array($row) && strtolower((string) ($row['kind'] ?? '')) === 'cedis';
    }

    /**
     * Sucursal CVA del catálogo (no CEDIS).
     */
    public static function isBranch(string $warehouseNameOrValue): bool
    {
        $raw = trim($warehouseNameOrValue);
        if ($raw === '' || self::isCedis($raw)) {
            return false;
        }

        $value = self::preferenceValueFromName($raw);
        if ($value === null) {
            return false;
        }

        $clave = substr($value, 4);
        /** @var array{kind?: string}|null $row */
        $row = config('cva_warehouses.'.$clave);

        return is_array($row) && strtolower((string) ($row['kind'] ?? 'sucursal')) !== 'cedis';
    }

    /**
     * @param  list<string>  $preferredList
     */
    public static function matchesPreferred(string $warehouseNameOrValue, array $preferredList): bool
    {
        if ($preferredList === []) {
            return true;
        }

        $value = self::preferenceValueFromName($warehouseNameOrValue);
        $normalizedOffer = self::normalizeName($warehouseNameOrValue);

        foreach ($preferredList as $pref) {
            $pref = strtoupper(trim((string) $pref));
            if ($pref === '') {
                continue;
            }

            if ($value !== null && strtoupper($value) === $pref) {
                return true;
            }

            // Legado texto exacto
            if ($normalizedOffer !== '' && $normalizedOffer === self::normalizeName($pref)) {
                return true;
            }

            // Preferencia por región CVA (CEDIS_GDL, CDMX…)
            if ($value !== null) {
                $clave = substr($value, 4);
                /** @var array{region?: string}|null $row */
                $row = config('cva_warehouses.'.$clave);
                $region = is_array($row) ? strtoupper((string) ($row['region'] ?? '')) : '';
                if ($region !== '' && ($pref === $region || $pref === 'CVA_'.$region)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function normalizeName(string $name): string
    {
        $raw = trim($name);
        if ($raw === '') {
            return '';
        }

        $upper = mb_strtoupper($raw);
        $upper = strtr($upper, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);

        return preg_replace('/\s+/', ' ', $upper) ?? $upper;
    }
}
