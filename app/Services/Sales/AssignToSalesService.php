<?php

namespace App\Services\Sales;

use App\Models\User;

/**
 * Helpers de rol para flags API (creador es ventas/compras).
 * El flujo de reasignación manual Compras↔Ventas fue retirado.
 */
class AssignToSalesService
{
    public function isAssignedToCompras(?User $creator): bool
    {
        return $this->creatorHasRole($creator, 'gerente_compras');
    }

    public function isAssignedToSales(?User $creator): bool
    {
        return $this->creatorHasRole($creator, 'ventas');
    }

    private function creatorHasRole(?User $creator, string $roleSlug): bool
    {
        if ($creator === null) {
            return false;
        }
        $creator->loadMissing('role');

        return $creator->role_slug === $roleSlug;
    }
}
