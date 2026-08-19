<?php

namespace App\Services\Wholesalers\Connectors;

use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerOffer;

class FtpWholesalerConnector extends AbstractWholesalerConnector
{
    protected function integrationType(): string
    {
        return 'ftp';
    }

    /**
     * @return list<WholesalerOffer>
     */
    protected function fetchOffers(Wholesaler $wholesaler, string $partNumber): array
    {
        return [$this->pendingOffer($wholesaler, $partNumber)];
    }
}
