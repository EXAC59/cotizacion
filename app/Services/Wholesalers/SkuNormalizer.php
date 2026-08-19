<?php

namespace App\Services\Wholesalers;

final class SkuNormalizer
{
    public static function forLookup(string $sku): string
    {
        return mb_strtoupper(trim($sku));
    }
}
