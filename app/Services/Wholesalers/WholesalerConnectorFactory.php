<?php

namespace App\Services\Wholesalers;

use App\Contracts\WholesalerConnector;
use App\Models\Wholesaler;
use App\Services\Wholesalers\Connectors\ApiWholesalerConnector;
use App\Services\Wholesalers\Connectors\CtInternacionalConnector;
use App\Services\Wholesalers\Connectors\CvaConnector;
use App\Services\Wholesalers\Connectors\CsvWholesalerConnector;
use App\Services\Wholesalers\Connectors\FtpWholesalerConnector;
use App\Services\Wholesalers\Connectors\ScrapingWholesalerConnector;
use App\Services\Wholesalers\Connectors\XmlWholesalerConnector;

class WholesalerConnectorFactory
{
    /** @var list<WholesalerConnector> */
    private array $connectors;

    public function __construct()
    {
        $this->connectors = [
            new CtInternacionalConnector,
            new CvaConnector,
            new ApiWholesalerConnector,
            new XmlWholesalerConnector,
            new CsvWholesalerConnector,
            new FtpWholesalerConnector,
            new ScrapingWholesalerConnector,
        ];
    }

    public function for(Wholesaler $wholesaler): WholesalerConnector
    {
        foreach ($this->connectors as $connector) {
            if ($connector->supports($wholesaler)) {
                return $connector;
            }
        }

        return new ApiWholesalerConnector;
    }
}
