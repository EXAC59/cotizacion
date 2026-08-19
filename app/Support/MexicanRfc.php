<?php

namespace App\Support;

class MexicanRfc
{
    private const PATTERN = '/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u';

    public static function normalize(?string $rfc): ?string
    {
        $clean = strtoupper(preg_replace('/[\s\-.]/', '', trim((string) $rfc)) ?? '');

        return $clean !== '' ? $clean : null;
    }

    public static function isValid(?string $rfc): bool
    {
        $normalized = self::normalize($rfc);

        if ($normalized === null) {
            return true;
        }

        return (bool) preg_match(self::PATTERN, $normalized);
    }
}
