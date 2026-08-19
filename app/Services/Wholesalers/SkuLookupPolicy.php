<?php

namespace App\Services\Wholesalers;

/**
 * Regla común para búsquedas de SKU en catálogos y APIs de mayoristas.
 *
 * El catálogo aporta claves internas confiables y la API confirma precio/stock.
 * Una respuesta sólo es válida si alguno de sus identificadores coincide con
 * la clave exacta que se consultó.
 */
final class SkuLookupPolicy
{
    /**
     * @param  list<string>  $catalogCandidates
     * @return list<string>
     */
    public static function candidates(
        string $requestedSku,
        array $catalogCandidates,
        bool $includeRequested = true,
    ): array {
        $candidates = [];
        $seen = [];

        foreach ($catalogCandidates as $candidate) {
            self::pushCandidate($candidates, $seen, $candidate);
        }

        if ($includeRequested) {
            self::pushCandidate($candidates, $seen, $requestedSku);
        }

        return $candidates;
    }

    /**
     * @param  list<string|null>  $responseIdentifiers
     */
    public static function responseMatchesCandidate(string $candidate, array $responseIdentifiers): bool
    {
        $expected = self::key($candidate);
        if ($expected === '') {
            return false;
        }

        foreach ($responseIdentifiers as $identifier) {
            if (self::key((string) $identifier) === $expected) {
                return true;
            }
        }

        return false;
    }

    public static function key(string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', SkuNormalizer::forLookup($value)) ?? '';
    }

    /**
     * @param  list<string>  $candidates
     * @param  array<string, true>  $seen
     */
    private static function pushCandidate(array &$candidates, array &$seen, string $candidate): void
    {
        $candidate = SkuNormalizer::forLookup($candidate);
        $key = self::key($candidate);
        if ($key === '' || isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $candidates[] = $candidate;
    }
}
