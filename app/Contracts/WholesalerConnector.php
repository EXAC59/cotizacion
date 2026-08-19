<?php

namespace App\Contracts;

use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerOffer;

interface WholesalerConnector
{
    public function supports(Wholesaler $wholesaler): bool;

    /**
     * @return list<WholesalerOffer>
     */
    public function lookup(Wholesaler $wholesaler, string $partNumber): array;
}
