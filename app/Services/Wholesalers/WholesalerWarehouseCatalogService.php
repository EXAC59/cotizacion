<?php

namespace App\Services\Wholesalers;

use App\Models\User;
use App\Models\Wholesaler;

/**
 * Catálogo de almacenes / sucursales agrupados por mayorista (para preferencias UI).
 */
class WholesalerWarehouseCatalogService
{
    public function __construct(
        private readonly WholesalerSalesAliasService $aliases,
    ) {}

    /**
     * @return list<array{
     *   wholesalerCode: string,
     *   wholesalerName: string,
     *   warehouses: list<array{value: string, label: string, city: string, code: string, region: string}>
     * }>
     */
    public function groups(?User $viewer = null): array
    {
        $groups = [
            $this->ctGroup(),
            $this->cvaGroup(),
        ];

        if ($viewer !== null && $this->aliases->shouldMask($viewer)) {
            return array_map(function (array $group) use ($viewer): array {
                $code = (string) ($group['wholesalerCode'] ?? '');
                $group['wholesalerName'] = $this->aliases->aliasForCode($code);

                $group['warehouses'] = array_map(static function (array $row) use ($code): array {
                    $value = (string) ($row['value'] ?? '');
                    if ($code === 'CT' && preg_match('/^(?:[0-9]{2}A|D2A)$/i', $value) === 1) {
                        $row['label'] = CtWarehouseDirectory::formatLabelMasked($value);
                        $row['city'] = CtWarehouseDirectory::cityLabel($value);
                    }
                    if ($code === 'CVA') {
                        $row['label'] = CvaWarehouseDirectory::formatLabelMasked($value);
                        $row['city'] = CvaWarehouseDirectory::cityLabel($value);
                    }

                    return $row;
                }, $group['warehouses']);

                return $group;
            }, $groups);
        }

        return $groups;
    }

    /**
     * Lista plana (compat) = todos los values seleccionables.
     *
     * @return list<array{value: string, label: string}>
     */
    public function flatOptions(?User $viewer = null): array
    {
        $out = [];
        foreach ($this->groups($viewer) as $group) {
            foreach ($group['warehouses'] as $row) {
                $out[] = [
                    'value' => (string) $row['value'],
                    'label' => (string) $row['label'],
                ];
            }
        }

        return $out;
    }

    /**
     * @return array{
     *   wholesalerCode: string,
     *   wholesalerName: string,
     *   warehouses: list<array{value: string, label: string, city: string, code: string, region: string}>
     * }
     */
    private function ctGroup(): array
    {
        $name = Wholesaler::query()->where('code', '=', 'CT')->value('name')
            ?? 'CT Internacional';

        return [
            'wholesalerCode' => 'CT',
            'wholesalerName' => (string) $name,
            'warehouses' => CtWarehouseDirectory::branchOptions(),
        ];
    }

    /**
     * @return array{
     *   wholesalerCode: string,
     *   wholesalerName: string,
     *   warehouses: list<array{value: string, label: string, city: string, code: string, region: string}>
     * }
     */
    private function cvaGroup(): array
    {
        $name = Wholesaler::query()->where('code', '=', 'CVA')->value('name')
            ?? 'Grupo CVA';

        return [
            'wholesalerCode' => 'CVA',
            'wholesalerName' => (string) $name,
            'warehouses' => CvaWarehouseDirectory::branchOptions(),
        ];
    }
}
