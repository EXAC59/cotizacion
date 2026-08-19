<?php

namespace App\Services\Wholesalers\Connectors;

use App\Contracts\WholesalerConnector;
use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerOffer;

abstract class AbstractWholesalerConnector implements WholesalerConnector
{
    abstract protected function integrationType(): string;

    public function supports(Wholesaler $wholesaler): bool
    {
        return $wholesaler->integration === $this->integrationType();
    }

    /**
     * @return list<WholesalerOffer>
     */
    public function lookup(Wholesaler $wholesaler, string $partNumber): array
    {
        if (! $wholesaler->isConfigured()) {
            return [$this->pendingOffer($wholesaler, $partNumber)];
        }

        return $this->fetchOffers($wholesaler, $partNumber);
    }

    /**
     * @return list<WholesalerOffer>
     */
    abstract protected function fetchOffers(Wholesaler $wholesaler, string $partNumber): array;

    protected function pendingOffer(Wholesaler $wholesaler, string $partNumber): WholesalerOffer
    {
        $prefix = $wholesaler->config_json['env_prefix'] ?? 'WHOLESALER_'.$wholesaler->code;

        return new WholesalerOffer(
            wholesalerId: $wholesaler->id,
            wholesalerCode: $wholesaler->code,
            wholesalerName: $wholesaler->name,
            partNumber: $partNumber,
            cost: 0,
            stock: 0,
            error: "Integración pendiente: configura {$prefix}_BASE_URL o credenciales en .env",
        );
    }
}
