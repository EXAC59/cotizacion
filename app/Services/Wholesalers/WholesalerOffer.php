<?php

namespace App\Services\Wholesalers;

readonly class WholesalerOffer
{
    public function __construct(
        public string $wholesalerId,
        public string $wholesalerCode,
        public string $wholesalerName,
        public string $partNumber,
        public float $cost,
        public int $stock,
        public string $warehouse = '',
        public int $leadDays = 0,
        public string $description = '',
        public ?string $error = null,
        public ?bool $hasFreight = null,
        public ?string $freightNote = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'wholesalerId' => $this->wholesalerId,
            'wholesalerCode' => $this->wholesalerCode,
            'wholesalerName' => $this->wholesalerName,
            'partNumber' => $this->partNumber,
            'cost' => $this->cost,
            'stock' => $this->stock,
            'warehouse' => $this->warehouse,
            'leadDays' => $this->leadDays,
            'description' => $this->description,
            'error' => $this->error,
            'hasFreight' => $this->hasFreight,
            'freightNote' => $this->freightNote,
        ];
    }
}
