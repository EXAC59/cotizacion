<?php

namespace App\Services\Wholesalers\LowStock\Sources;

use App\Contracts\WholesalerLowStockSource;
use App\Models\Wholesaler;
use App\Services\Wholesalers\CvaCatalogIndex;

/**
 * Stock bajo desde el catálogo CVA ya sincronizado (sin llamar a la API en cada poll).
 */
class CvaCatalogLowStockSource implements WholesalerLowStockSource
{
    public function __construct(private readonly CvaCatalogIndex $catalog) {}

    public function wholesalerCode(): string
    {
        return 'CVA';
    }

    public function supports(Wholesaler $wholesaler): bool
    {
        return strtoupper($wholesaler->code) === 'CVA';
    }

    public function requiresCredentials(): bool
    {
        return false;
    }

    public function collect(Wholesaler $wholesaler, int $threshold, int $limit): array
    {
        return array_map(
            static function (array $row): array {
                return [
                    'partNumber' => $row['partNumber'],
                    'product' => $row['product'],
                    'stock' => $row['stock'],
                    'warehouse' => $row['warehouse'] !== '' ? $row['warehouse'] : 'Almacén CVA',
                ];
            },
            $this->catalog->lowStockProducts($threshold, $limit),
        );
    }
}
