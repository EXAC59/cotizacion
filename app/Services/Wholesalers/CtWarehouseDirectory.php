<?php

namespace App\Services\Wholesalers;

/**
 * Resuelve códigos de almacén CT (13A, 24A, …) a ciudad / región comparable
 * con preferencias del usuario (CDMX, GDL, MTY).
 */
final class CtWarehouseDirectory
{
    private static ?string $preferredForLookup = null;

    /** @var list<string> */
    private static array $preferredListForLookup = [];

    /**
     * @param  string|list<string>|null  $preferredWarehouse
     */
    public static function setPreferredForLookup(string|array|null $preferredWarehouse): void
    {
        $list = self::normalizePreferredList($preferredWarehouse);
        self::$preferredListForLookup = $list;
        self::$preferredForLookup = $list[0] ?? null;
    }

    public static function preferredForLookup(): ?string
    {
        return self::$preferredForLookup;
    }

    /**
     * @return list<string>
     */
    public static function preferredListForLookup(): array
    {
        return self::$preferredListForLookup;
    }

    /**
     * @param  string|list<string>|null  $preferred
     * @return list<string>
     */
    public static function normalizePreferredList(string|array|null $preferred): array
    {
        if ($preferred === null) {
            return [];
        }

        $raw = is_array($preferred)
            ? $preferred
            : (preg_split('/[,\s]+/', (string) $preferred) ?: []);
        $out = [];
        foreach ($raw as $item) {
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }

            // Preferencia a nivel sucursal CT (35A, D2A…): conservar el código exacto.
            $nna = self::extractCode($text);
            if ($nna !== '' && self::entry($nna) !== null) {
                if (! in_array($nna, $out, true)) {
                    $out[] = $nna;
                }

                continue;
            }

            $key = self::matchKey($text);
            if ($key === '' || in_array($key, $out, true)) {
                continue;
            }
            $out[] = $key;
        }

        return $out;
    }

    /**
     * ¿El almacén candidato (código NNA o etiqueta) cae dentro de la preferencia?
     *
     * - Preferencia "35A" → solo esa sucursal
     * - Preferencia "CDMX" (legado) → todas las sucursales de esa región
     *
     * @param  list<string>  $preferredList  ya normalizada
     */
    public static function matchesPreferred(string $candidateWarehouse, array $preferredList): bool
    {
        if ($preferredList === []) {
            return true;
        }

        $code = self::extractCode($candidateWarehouse);
        if ($code === '') {
            $code = strtoupper(trim($candidateWarehouse));
        }
        $region = self::matchKey($candidateWarehouse);

        foreach ($preferredList as $pref) {
            $pref = strtoupper(trim((string) $pref));
            if ($pref === '') {
                continue;
            }

            $prefNna = self::extractCode($pref);
            if ($prefNna !== '' && self::entry($prefNna) !== null) {
                if ($code !== '' && $prefNna === $code) {
                    return true;
                }

                continue;
            }

            // Preferencia por región u otra etiqueta normalizada.
            if ($region !== '' && self::matchKey($pref) === $region) {
                return true;
            }

            if ($code !== '' && $pref === $code) {
                return true;
            }

            if ($region !== '' && $pref === $region) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefer warehouses whose region matches any preferred key (order matters); otherwise max stock.
     *
     * @param  list<array{code: string, stock: int}>  $candidates
     * @param  string|list<string>|null  $preferredWarehouse
     */
    public static function pickBestCode(array $candidates, string|array|null $preferredWarehouse = null): string
    {
        if ($candidates === []) {
            return '';
        }

        $preferredList = self::normalizePreferredList(
            $preferredWarehouse ?? (self::$preferredListForLookup !== []
                ? self::$preferredListForLookup
                : self::$preferredForLookup)
        );
        $preferredBest = '';
        $preferredStock = -1;
        $preferredRank = PHP_INT_MAX;
        $globalBest = '';
        $globalStock = -1;

        foreach ($candidates as $row) {
            $code = strtoupper((string) ($row['code'] ?? ''));
            $stock = (int) ($row['stock'] ?? 0);
            if ($code === '' || $stock <= 0) {
                continue;
            }

            if ($stock > $globalStock) {
                $globalStock = $stock;
                $globalBest = $code;
            }

            $rank = self::preferredRank($code, $preferredList);
            if ($rank === null) {
                continue;
            }
            if ($rank < $preferredRank || ($rank === $preferredRank && $stock > $preferredStock)) {
                $preferredRank = $rank;
                $preferredStock = $stock;
                $preferredBest = $code;
            }
        }

        return $preferredBest !== '' ? $preferredBest : $globalBest;
    }

    /**
     * Índice en la lista preferida (menor = más prioritario), o null si no aplica.
     *
     * @param  list<string>  $preferredList
     */
    public static function preferredRank(string $warehouseCode, array $preferredList): ?int
    {
        if ($preferredList === []) {
            return null;
        }

        $code = strtoupper(self::extractCode($warehouseCode) ?: trim($warehouseCode));
        $region = self::matchKey($warehouseCode);

        foreach ($preferredList as $index => $pref) {
            $pref = strtoupper(trim((string) $pref));
            $prefNna = self::extractCode($pref);
            if ($prefNna !== '' && self::entry($prefNna) !== null) {
                if ($code !== '' && $prefNna === $code) {
                    return (int) $index;
                }

                continue;
            }

            if ($region !== '' && (self::matchKey($pref) === $region || $pref === $region)) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * Suma existencias de almacenes CT cuya región está en la lista preferida.
     * Si no hay preferencia, suma el total nacional (todos los candidatos).
     *
     * @param  list<array{code: string, stock: int}>  $candidates
     * @param  string|list<string>|null  $preferredWarehouse
     */
    public static function stockForPreferredRegion(
        array $candidates,
        string|array|null $preferredWarehouse = null,
    ): int {
        $preferredList = self::normalizePreferredList(
            $preferredWarehouse ?? (self::$preferredListForLookup !== []
                ? self::$preferredListForLookup
                : self::$preferredForLookup)
        );
        $total = 0;

        foreach ($candidates as $row) {
            $code = strtoupper((string) ($row['code'] ?? ''));
            $stock = (int) ($row['stock'] ?? 0);
            if ($code === '' || $stock <= 0) {
                continue;
            }

            if ($preferredList === [] || self::matchesPreferred($code, $preferredList)) {
                $total += $stock;
            }
        }

        return $total;
    }

    /**
     * Stock del almacén elegido (código CT NNA).
     *
     * @param  list<array{code: string, stock: int}>  $candidates
     */
    public static function stockForCode(array $candidates, string $warehouseCode): int
    {
        $code = strtoupper(self::extractCode($warehouseCode) ?: trim($warehouseCode));
        if ($code === '') {
            return 0;
        }

        foreach ($candidates as $row) {
            if (strtoupper((string) ($row['code'] ?? '')) === $code) {
                return max(0, (int) ($row['stock'] ?? 0));
            }
        }

        return 0;
    }

    /**
     * @return array{city: string, code: string, region: string}|null
     */
    public static function entry(string $warehouseCode): ?array
    {
        $code = self::extractCode($warehouseCode);
        if ($code === '') {
            return null;
        }

        /** @var array{city?: string, code?: string, region?: string}|null $row */
        $row = config('ct_warehouses.'.$code);

        if (! is_array($row) || ($row['city'] ?? '') === '') {
            return null;
        }

        return [
            'city' => (string) $row['city'],
            'code' => (string) ($row['code'] ?? $code),
            'region' => strtoupper((string) ($row['region'] ?? $row['code'] ?? $code)),
        ];
    }

    /** Etiqueta legible: "Guadalajara (13A)". */
    public static function formatLabel(string $warehouseCode): string
    {
        $code = self::extractCode($warehouseCode);
        if ($code === '') {
            $region = self::matchKey($warehouseCode);
            if ($region !== '') {
                $mapped = self::codeForRegion($region);
                if ($mapped !== null) {
                    $code = $mapped;
                } else {
                    return $region;
                }
            } else {
                return trim($warehouseCode);
            }
        }

        $entry = self::entry($code);
        if ($entry === null) {
            return $code;
        }

        return "{$entry['city']} ({$code})";
    }

    /**
     * ¿Es un CEDIS CT (centros de distribución)?
     * Incluye D2A, 53A y 35A (Azcapotzalco). En CEDIS no se cobra flete por producto.
     */
    public static function isCedis(string $warehouse): bool
    {
        $raw = trim($warehouse);
        if ($raw === '') {
            return false;
        }

        if (str_contains(mb_strtoupper($raw), 'CEDIS')) {
            return true;
        }

        $code = self::extractCode($raw);
        if ($code !== '') {
            if (in_array($code, ['53A', 'D2A', '35A'], true)) {
                return true;
            }
            $entry = self::entry($code);
            if ($entry !== null && str_starts_with(mb_strtoupper($entry['city']), 'CEDIS')) {
                return true;
            }
        }

        $region = self::matchKey($raw);

        return in_array($region, ['CMT', 'D2A'], true);
    }

    /**
     * Sucursal / plaza CT conocida (no CEDIS).
     */
    public static function isBranch(string $warehouse): bool
    {
        $raw = trim($warehouse);
        if ($raw === '' || self::isCedis($raw)) {
            return false;
        }

        $code = self::extractCode($raw);
        if ($code !== '' && self::entry($code) !== null) {
            return true;
        }

        $region = self::matchKey($raw);

        return $region !== '' && ! in_array($region, ['CMT', 'D2A'], true);
    }

    /**
     * Etiqueta para ventas: sin nombre comercial de mayorista, pero con ciudad.
     * Ej. "Almacén CEDIS Monterrey", "Almacén Hermosillo".
     */
    public static function formatLabelMasked(string $warehouseCode): string
    {
        $code = self::extractCode($warehouseCode);
        $entry = $code !== '' ? self::entry($code) : null;

        if ($entry !== null) {
            $city = trim($entry['city']);
            $place = preg_replace('/^CEDIS\s+/iu', '', $city) ?: $city;
            if (self::isCedis($code) || str_starts_with(mb_strtoupper($city), 'CEDIS')) {
                return 'Almacén CEDIS '.$place;
            }

            return 'Almacén '.$place;
        }

        $region = self::matchKey($warehouseCode);
        if ($region === '') {
            $region = $code !== '' ? $code : strtoupper(trim($warehouseCode));
        }
        if ($region === '') {
            return 'Almacén';
        }

        $regionLabel = self::REGION_LABELS[$region] ?? $region;
        if (self::isCedis($warehouseCode) || in_array($region, ['CMT', 'D2A'], true)) {
            $place = preg_replace('/^CEDIS\s+/iu', '', $regionLabel) ?: $regionLabel;

            return 'Almacén CEDIS '.$place;
        }

        return 'Almacén '.$regionLabel;
    }

    /** Ciudad / plaza legible (sin prefijo CEDIS) para subtítulos en UI. */
    public static function cityLabel(string $warehouseCode): string
    {
        $code = self::extractCode($warehouseCode);
        $entry = $code !== '' ? self::entry($code) : null;
        if ($entry !== null) {
            $city = trim($entry['city']);

            return preg_replace('/^CEDIS\s+/iu', '', $city) ?: $city;
        }

        $region = self::matchKey($warehouseCode);

        return self::REGION_LABELS[$region] ?? ($region !== '' ? $region : '');
    }

    /**
     * Primer código NNA del catálogo cuya región coincide.
     */
    public static function codeForRegion(string $regionOrCode): ?string
    {
        $region = self::matchKey($regionOrCode);
        if ($region === '') {
            return null;
        }

        // Si ya es un código de almacén válido, úsalo.
        $asCode = self::extractCode($regionOrCode);
        if ($asCode !== '' && self::entry($asCode) !== null) {
            return $asCode;
        }

        /** @var array<string, array{city?: string, code?: string, region?: string}> $catalog */
        $catalog = config('ct_warehouses', []);
        foreach ($catalog as $nna => $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowRegion = strtoupper((string) ($row['region'] ?? ''));
            if ($rowRegion === $region) {
                return strtoupper((string) $nna);
            }
        }

        // Región que también es código (D2A).
        if (self::entry($region) !== null) {
            return $region;
        }

        return null;
    }

    /**
     * Etiqueta a mostrar cuando la región preferida no tiene stock:
     * no caer al almacén nacional con más existencia.
     *
     * @param  string|list<string>|null  $preferredWarehouse
     */
    public static function labelForPreferredOrBest(
        string $bestWarehouseCode,
        int $regionStock,
        string|array|null $preferredWarehouse = null,
    ): string {
        $preferredList = self::normalizePreferredList(
            $preferredWarehouse ?? (self::$preferredListForLookup !== []
                ? self::$preferredListForLookup
                : self::$preferredForLookup)
        );

        if ($preferredList !== [] && $regionStock <= 0) {
            $code = self::codeForRegion($preferredList[0]) ?? $preferredList[0];

            return self::formatLabel($code);
        }

        if ($bestWarehouseCode !== '') {
            return self::formatLabel($bestWarehouseCode);
        }

        return '';
    }

    /**
     * Clave para scoring / preferencia: CDMX, GDL, MTY o el texto ya normalizado.
     */
    public static function matchKey(string $warehouse): string
    {
        $raw = trim($warehouse);
        if ($raw === '') {
            return '';
        }

        $code = self::extractCode($raw);
        if ($code !== '') {
            $entry = self::entry($code);
            if ($entry !== null) {
                return $entry['region'];
            }

            return $code;
        }

        return strtoupper($raw);
    }

    public static function extractCode(string $warehouse): string
    {
        $raw = trim($warehouse);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/\b([0-9]{2}A|D2A)\b/i', $raw, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/^([0-9]{2}A|D2A)$/i', $raw) === 1) {
            return strtoupper($raw);
        }

        return '';
    }

    private const REGION_LABELS = [
        'CDMX' => 'Ciudad de México',
        'MTY' => 'Monterrey',
        'GDL' => 'Guadalajara',
        'HMO' => 'Hermosillo',
        'CMT' => 'CEDIS Monterrey',
        'D2A' => 'CEDIS Hermosillo',
        'QRO' => 'Querétaro',
        'PUE' => 'Puebla',
        'SLP' => 'San Luis Potosí',
        'AGS' => 'Aguascalientes',
        'LEO' => 'León',
        'MID' => 'Mérida',
        'CUN' => 'Cancún',
        'VER' => 'Veracruz',
    ];

    /** Orden de hubs: CEDIS CT primero, luego plazas. */
    private const HUB_ORDER = [
        'CMT', 'D2A', 'CDMX', 'HMO', 'GDL', 'QRO', 'PUE', 'SLP', 'AGS', 'LEO', 'VER', 'MID', 'CUN', 'MTY',
    ];

    /**
     * Preferidos por defecto: CEDIS CT (D2A, 53A, 35A) + plaza Hermosillo + CEDIS CVA.
     * Sin plaza Monterrey CT (24A/MTY).
     *
     * @return list<string>
     */
    public static function defaultPreferredWarehouses(): array
    {
        return [
            'D2A',   // CEDIS Hermosillo (CT)
            '53A',   // CEDIS Monterrey (CT)
            '35A',   // CEDIS Azcapotzalco (CT)
            '01A',   // Hermosillo plaza (CT)
            'CVA-46', // CEDIS Guadalajara (CVA)
            'CVA-51', // CEDIS CDMX Centro Sur (CVA)
            'CVA-54', // CEDIS Monterrey (CVA)
        ];
    }

    /**
     * Cada sucursal CT del catálogo (una fila por almacén físico).
     *
     * @return list<array{value: string, label: string, city: string, code: string, region: string}>
     */
    public static function branchOptions(): array
    {
        /** @var array<string, array{city?: string, code?: string, region?: string}> $catalog */
        $catalog = config('ct_warehouses', []);
        $rows = [];

        foreach ($catalog as $nna => $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = strtoupper((string) $nna);
            $city = trim((string) ($row['city'] ?? $code));
            $region = strtoupper((string) ($row['region'] ?? self::matchKey($code)));
            $rows[] = [
                'value' => $code,
                'label' => self::formatLabel($code),
                'city' => $city,
                'code' => $code,
                'region' => $region !== '' ? $region : $code,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $aCedis = self::isCedis($a['value']) || self::isCedis($a['city'] ?? '') ? 0 : 1;
            $bCedis = self::isCedis($b['value']) || self::isCedis($b['city'] ?? '') ? 0 : 1;
            if ($aCedis !== $bCedis) {
                return $aCedis <=> $bCedis;
            }

            $hubOrder = array_flip(self::HUB_ORDER);
            $ra = $hubOrder[$a['region']] ?? 1000;
            $rb = $hubOrder[$b['region']] ?? 1000;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return strcasecmp($a['city'], $b['city']);
        });

        return $rows;
    }

    /**
     * Regiones únicas de toda la República (catálogo CT).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function regionOptions(): array
    {
        /** @var array<string, array{city?: string, code?: string, region?: string}> $catalog */
        $catalog = config('ct_warehouses', []);
        $byRegion = [];

        foreach ($catalog as $row) {
            if (! is_array($row)) {
                continue;
            }
            $region = strtoupper((string) ($row['region'] ?? ''));
            if ($region === '') {
                continue;
            }
            if (! isset($byRegion[$region])) {
                $city = (string) ($row['city'] ?? $region);
                $byRegion[$region] = self::REGION_LABELS[$region] ?? $city;
            }
        }

        $ordered = [];
        foreach (self::HUB_ORDER as $hub) {
            if (isset($byRegion[$hub])) {
                $ordered[$hub] = $byRegion[$hub];
                unset($byRegion[$hub]);
            }
        }

        asort($byRegion, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($byRegion as $region => $label) {
            $ordered[$region] = $label;
        }

        $options = [];
        foreach ($ordered as $value => $cityLabel) {
            $isCedis = $value === 'CMT' || $value === 'D2A';
            $options[] = [
                'value' => $value,
                'label' => $isCedis
                    ? "{$cityLabel} ({$value})"
                    : "{$value} — {$cityLabel}",
            ];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function defaultPriorityCodes(): array
    {
        return array_values(array_map(
            static fn (array $o): string => $o['value'],
            self::regionOptions(),
        ));
    }

    public static function defaultPriorityCsv(): string
    {
        return implode(', ', self::defaultPriorityCodes());
    }
}
