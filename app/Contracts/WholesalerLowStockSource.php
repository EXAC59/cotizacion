<?php

namespace App\Contracts;

use App\Models\Wholesaler;

/**
 * Fuente de productos con stock bajo para un mayorista.
 * Al integrar uno nuevo, implementar esta interfaz y registrarla en config/low_stock.php.
 */
interface WholesalerLowStockSource
{
    public function wholesalerCode(): string;

    public function supports(Wholesaler $wholesaler): bool;

    /** Si es false, puede correr solo con mayorista activo (p. ej. catálogo CVA en disco). */
    public function requiresCredentials(): bool;

    /**
     * @return list<array{partNumber: string, product: string, stock: int, warehouse: string}>
     */
    public function collect(Wholesaler $wholesaler, int $threshold, int $limit): array;
}
