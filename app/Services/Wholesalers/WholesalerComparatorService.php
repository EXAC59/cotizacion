<?php

namespace App\Services\Wholesalers;

class WholesalerComparatorService
{
    public function __construct(
        private readonly WholesalerLookupService $lookup,
        private readonly WholesalerDemoOfferService $demoOffers,
        private readonly ComparatorSettingsService $settingsService,
        private readonly WholesalerPerformanceService $performanceService,
    ) {}

    /**
     * @param  list<string>|null  $wholesalerIds
     * @return array{
     *     partNumber: string,
     *     quantity: float,
     *     preferredWarehouse: string|null,
     *     offers: list<array<string, mixed>>,
     *     best: array<string, mixed>|null,
     *     demoMode: bool,
     *     lookupError: string|null,
     *     notFound: list<array{wholesalerId: string, wholesalerCode: string, wholesalerName: string, reason: string, message: string, warehouses?: list<array{code: string, label: string, reason: string}>}>
     * }
     */
    public function compare(
        string $partNumber,
        float $quantity = 1,
        ?string $preferredWarehouse = null,
        ?array $wholesalerIds = null,
    ): array {
        $partNumber = SkuNormalizer::forLookup($partNumber);
        $quantity = max(1, $quantity);
        $preferredWarehouse = $preferredWarehouse !== null && $preferredWarehouse !== ''
            ? strtoupper(trim($preferredWarehouse))
            : null;

        $rawOffers = array_map(
            static function (array $offer) use ($partNumber): array {
                $supplierPartNumber = trim((string) ($offer['partNumber'] ?? ''));

                return [
                    ...$offer,
                    // partNumber conserva el SKU solicitado. La referencia
                    // propia del mayorista nunca sobrescribe la partida.
                    'partNumber' => $partNumber,
                    'supplierPartNumber' => $supplierPartNumber !== ''
                        && SkuLookupPolicy::key($supplierPartNumber) !== SkuLookupPolicy::key($partNumber)
                            ? $supplierPartNumber
                            : null,
                ];
            },
            $this->lookup->lookupByPartNumber($partNumber, $wholesalerIds, true, $preferredWarehouse),
        );
        $usable = array_values(array_filter(
            $rawOffers,
            static fn (array $o): bool => empty($o['error']) && (int) ($o['stock'] ?? 0) >= $quantity,
        ));

        $notFound = $this->buildNotFoundEntries($rawOffers, $partNumber, $preferredWarehouse, $quantity);

        $demoMode = false;
        if ($usable === []) {
            $usable = $this->demoOffers->buildDemoOffers($partNumber, $quantity);
            $demoMode = $usable !== [];
        }

        $ranked = $this->rankOffers($usable, $quantity, $preferredWarehouse);
        $best = $ranked[0] ?? null;

        $lookupError = null;
        if ($ranked === [] && ! $demoMode) {
            $lookupError = $this->friendlyNotFoundSummary($partNumber, $notFound);
        }

        return [
            'partNumber' => $partNumber,
            'quantity' => $quantity,
            'preferredWarehouse' => $preferredWarehouse,
            'offers' => array_map(fn (ComparedOffer $o) => $o->toArray(), $ranked),
            'best' => $best?->toArray(),
            'demoMode' => $demoMode,
            'lookupError' => $lookupError,
            'notFound' => $notFound,
        ];
    }

    /**
     * @param  list<array{partNumber: string, quantity?: float, preferredWarehouse?: string|null}>  $lines
     * @return list<array<string, mixed>>
     */
    public function compareBatch(array $lines, ?string $defaultWarehouse = null): array
    {
        $results = [];
        foreach ($lines as $line) {
            $partNumber = SkuNormalizer::forLookup((string) ($line['partNumber'] ?? ''));
            if ($partNumber === '') {
                continue;
            }

            $results[] = $this->compare(
                $partNumber,
                (float) ($line['quantity'] ?? 1),
                $line['preferredWarehouse'] ?? $defaultWarehouse,
            );
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     * @return list<ComparedOffer>
     */
    public function rankRawOffers(array $offers, float $quantity, ?string $preferredWarehouse): array
    {
        $preferredWarehouse = $preferredWarehouse !== null && $preferredWarehouse !== ''
            ? strtoupper(trim($preferredWarehouse))
            : null;

        return $this->rankOffers($offers, max(1, $quantity), $preferredWarehouse);
    }

    /**
     * Orden fijo: stock usable (>= qty) → menor unitCost → mayor stock → menor leadDays.
     * Los pesos de configuración no deciden el ganador (se conservan en settings).
     *
     * @param  list<array<string, mixed>>  $offers
     * @return list<ComparedOffer>
     */
    private function rankOffers(array $offers, float $quantity, ?string $preferredWarehouse): array
    {
        $offers = array_values(array_filter(
            $offers,
            static fn (array $o): bool => empty($o['error']) && (int) ($o['stock'] ?? 0) >= $quantity,
        ));

        if ($offers === []) {
            return [];
        }

        $normalized = array_map(fn (array $o) => OfferNormalizer::enrich($o), $offers);

        $rows = [];
        foreach ($normalized as $offer) {
            $unitCost = (float) ($offer['unitCost'] ?? $offer['cost'] ?? 0);
            $stock = (int) ($offer['stock'] ?? 0);
            $leadDays = (int) ($offer['leadDays'] ?? 0);
            // Desempeño ya no decide el orden; campo conservado para compat UI.
            $performanceScore = 0.5;

            $rows[] = [
                'offer' => [
                    ...$offer,
                    'performanceScore' => $performanceScore,
                ],
                'unitCost' => $unitCost,
                'stock' => $stock,
                'leadDays' => $leadDays,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $byCost = $a['unitCost'] <=> $b['unitCost'];
            if ($byCost !== 0) {
                return $byCost;
            }
            $byStock = $b['stock'] <=> $a['stock'];
            if ($byStock !== 0) {
                return $byStock;
            }

            return $a['leadDays'] <=> $b['leadDays'];
        });

        $count = count($rows);
        $minCost = (float) min(array_column($rows, 'unitCost'));
        $maxStock = (int) max(array_column($rows, 'stock'));

        $ranked = [];
        foreach ($rows as $index => $item) {
            $reasons = $this->buildSimpleReasons(
                $item['unitCost'],
                $minCost,
                $item['stock'],
                $maxStock,
                $quantity,
                (string) ($item['offer']['availabilityType'] ?? 'local'),
            );

            // Score decreciente por posición (compat con UI que ordena por score).
            $score = max(0.0, 1.0 - ($index / max(1, $count)));

            $ranked[] = new ComparedOffer(
                offer: $item['offer'],
                score: round($score, 4),
                rank: $index + 1,
                isBest: $index === 0,
                reasons: $reasons,
            );
        }

        return $ranked;
    }

    /**
     * @return list<string>
     */
    private function buildSimpleReasons(
        float $unitCost,
        float $minCost,
        int $stock,
        int $maxStock,
        float $quantity,
        string $availability,
    ): array {
        $reasons = [];

        if (abs($unitCost - $minCost) < 0.0001) {
            $reasons[] = 'Menor costo';
        }
        if ($stock === $maxStock && $maxStock > 0) {
            $reasons[] = 'Mayor existencia';
        }
        if ($stock >= $quantity) {
            $reasons[] = 'Stock suficiente';
        }
        if ($availability === 'local') {
            $reasons[] = 'Stock local';
        } else {
            $reasons[] = 'Importación';
        }

        return $reasons;
    }

    /**
     * @param  list<string>  $preferredList
     * @param  list<string>  $warehousePriority
     */
    private function warehouseScore(
        string $warehouseRaw,
        string $warehouseKey,
        array $preferredList,
        array $warehousePriority,
    ): float {
        $candidate = $warehouseRaw !== '' ? $warehouseRaw : $warehouseKey;
        if ($preferredList !== [] && (
            CtWarehouseDirectory::matchesPreferred($candidate, $preferredList)
            || CvaWarehouseDirectory::matchesPreferred($candidate, $preferredList)
        )) {
            return 1.0;
        }

        // Preferencia de almacén CT no debe castigar ofertas CVA (u otros) sin match.
        if ($preferredList !== [] && $warehouseKey !== '') {
            return 0.5;
        }

        $index = array_search($warehouseKey, $warehousePriority, true);
        if ($index === false) {
            return $warehouseKey === '' ? 0.3 : 0.5;
        }

        return max(0.4, 1.0 - ($index * 0.12));
    }

    /**
     * @param  list<array<string, mixed>>  $rawOffers
     * @return list<array{wholesalerId: string, wholesalerCode: string, wholesalerName: string, reason: string, message: string, warehouses: list<array{code: string, label: string, reason: string}>}>
     */
    private function buildNotFoundEntries(
        array $rawOffers,
        string $partNumber,
        ?string $preferredWarehouse = null,
        float $quantity = 1,
    ): array {
        $preferredList = $this->parsePreferredKeys($preferredWarehouse);
        $quantity = max(1, $quantity);

        /** @var array<string, array{wholesalerId: string, wholesalerCode: string, wholesalerName: string, reason: string, message: string, warehouses: list<array{code: string, label: string, reason: string}>}> $byWholesaler */
        $byWholesaler = [];

        foreach ($rawOffers as $raw) {
            $id = (string) ($raw['wholesalerId'] ?? '');
            if ($id === '') {
                continue;
            }

            $err = trim((string) ($raw['error'] ?? ''));
            $stock = (int) ($raw['stock'] ?? 0);
            if ($err === '' && $stock >= $quantity) {
                // Hubo oferta usable de este mayorista → no marcar como no encontrado.
                unset($byWholesaler[$id]);

                continue;
            }

            if (isset($byWholesaler[$id])) {
                continue;
            }

            $code = strtoupper((string) ($raw['wholesalerCode'] ?? ''));
            $name = trim((string) ($raw['wholesalerName'] ?? $code));
            if ($name === '') {
                $name = 'Mayorista';
            }

            $reason = $err !== '' ? 'not_found' : 'no_stock';
            $warehouses = $this->warehousesForNotFound(
                $code,
                $preferredList,
                $reason,
                trim((string) ($raw['warehouse'] ?? '')),
            );

            $message = $warehouses === []
                ? ($reason === 'no_stock' ? "{$name}: sin stock" : "{$name}: no encontrado")
                : "{$name}: sin existencias en almacenes preferidos";

            $byWholesaler[$id] = [
                'wholesalerId' => $id,
                'wholesalerCode' => $code,
                'wholesalerName' => $name,
                'reason' => $reason,
                'message' => $message,
                'warehouses' => $warehouses,
            ];
        }

        return array_values($byWholesaler);
    }

    /**
     * @param  list<string>  $preferredList
     * @return list<array{code: string, label: string, reason: string}>
     */
    private function warehousesForNotFound(
        string $wholesalerCode,
        array $preferredList,
        string $reason,
        string $rawWarehouse = '',
    ): array {
        $code = strtoupper($wholesalerCode);
        $out = [];
        $seen = [];

        foreach ($preferredList as $pref) {
            $pref = strtoupper(trim((string) $pref));
            if ($pref === '') {
                continue;
            }

            if ($code === 'CVA') {
                if (! str_starts_with($pref, 'CVA-')) {
                    continue;
                }
                if (isset($seen[$pref])) {
                    continue;
                }
                $seen[$pref] = true;
                $out[] = [
                    'code' => $pref,
                    'label' => $this->cvaWarehouseLabel($pref),
                    'reason' => $reason,
                ];

                continue;
            }

            if ($code === 'CT') {
                if (str_starts_with($pref, 'CVA-')) {
                    continue;
                }
                $nna = CtWarehouseDirectory::extractCode($pref);
                if ($nna !== '' && CtWarehouseDirectory::entry($nna) !== null) {
                    if (isset($seen[$nna])) {
                        continue;
                    }
                    $seen[$nna] = true;
                    $out[] = [
                        'code' => $nna,
                        'label' => CtWarehouseDirectory::formatLabel($nna),
                        'reason' => $reason,
                    ];

                    continue;
                }

                $region = CtWarehouseDirectory::matchKey($pref);
                if ($region === '' || isset($seen[$region])) {
                    continue;
                }
                $seen[$region] = true;
                $out[] = [
                    'code' => $region,
                    'label' => "Zona {$region}",
                    'reason' => $reason,
                ];
            }
        }

        if ($out === [] && $rawWarehouse !== '') {
            $out[] = [
                'code' => strtoupper($rawWarehouse),
                'label' => $rawWarehouse,
                'reason' => $reason,
            ];
        }

        return $out;
    }

    /**
     * Conserva códigos CT (35A…) y CVA (CVA-46…) de la preferencia del usuario.
     *
     * @return list<string>
     */
    private function parsePreferredKeys(?string $preferredWarehouse): array
    {
        $raw = $preferredWarehouse !== null && trim($preferredWarehouse) !== ''
            ? $preferredWarehouse
            : implode(',', CtWarehouseDirectory::defaultPreferredWarehouses());

        $parts = preg_split('/[,\s]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $item) {
            $text = strtoupper(trim((string) $item));
            if ($text === '' || in_array($text, $out, true)) {
                continue;
            }

            if (preg_match('/^CVA-\d+$/', $text) === 1) {
                $out[] = $text;

                continue;
            }

            $nna = CtWarehouseDirectory::extractCode($text);
            if ($nna !== '' && CtWarehouseDirectory::entry($nna) !== null) {
                $out[] = $nna;

                continue;
            }

            $region = CtWarehouseDirectory::matchKey($text);
            if ($region !== '' && ! in_array($region, $out, true)) {
                $out[] = $region;
            }
        }

        return $out !== [] ? $out : CtWarehouseDirectory::defaultPreferredWarehouses();
    }

    private function cvaWarehouseLabel(string $preferenceValue): string
    {
        foreach (CvaWarehouseDirectory::branchOptions() as $row) {
            if (strtoupper($row['value']) === strtoupper($preferenceValue)) {
                return $row['label'];
            }
        }

        return $preferenceValue;
    }

    /**
     * @param  list<array{wholesalerName?: string, message?: string}>  $notFound
     */
    private function friendlyNotFoundSummary(string $partNumber, array $notFound): string
    {
        if ($notFound === []) {
            return "Sin existencias de «{$partNumber}».";
        }

        $names = [];
        foreach ($notFound as $row) {
            $name = trim((string) ($row['wholesalerName'] ?? ''));
            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        if ($names === []) {
            return "Sin existencias de «{$partNumber}».";
        }

        return 'Sin existencias en: '.implode(', ', $names).'.';
    }
}
