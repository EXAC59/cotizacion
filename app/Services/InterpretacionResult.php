<?php

namespace App\Services;

readonly class InterpretacionResult
{
    /**
     * @param  list<array{quantity: int|float, product: string, partNumber: string, brand: string, description: string, unit: string}>  $lineas
     */
    public function __construct(
        public array $lineas,
        public string $via,
    ) {}
}
