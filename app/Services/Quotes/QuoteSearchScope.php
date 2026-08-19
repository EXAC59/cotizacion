<?php

namespace App\Services\Quotes;

use Illuminate\Database\Eloquent\Builder;

class QuoteSearchScope
{
    public static function apply(Builder $query, string $search): void
    {
        $raw = trim($search);
        if ($raw === '') {
            return;
        }

        $term = '%'.mb_strtolower($raw).'%';
        $folioParts = self::parseFolioParts($raw);

        $query->where(function (Builder $builder) use ($term, $folioParts) {
            $builder->whereRaw('LOWER(folio) LIKE ?', [$term])
                ->orWhereHas('client', function (Builder $clientQuery) use ($term) {
                    $clientQuery->where(function (Builder $client) use ($term) {
                        $client->whereRaw('LOWER(company) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(rfc) LIKE ?', [$term]);
                    });
                });

            if ($folioParts !== null && $folioParts['text'] !== '') {
                self::applyFlexibleFolioMatch($builder, $folioParts['text'], $folioParts['number']);
            }
        });
    }

    /**
     * @return array{text: string, number: int}|null
     */
    public static function parseFolioParts(string $raw): ?array
    {
        $normalized = mb_strtolower(trim($raw));
        $normalized = preg_replace('/[^a-z0-9-]+/', '-', $normalized) ?? $normalized;

        if (! preg_match('/^(.*?)(\d+)$/', $normalized, $matches)) {
            return null;
        }

        $text = self::extractFolioTextPart($matches[1]);
        $number = (int) $matches[2];

        if ($text === '' && $number === 0) {
            return null;
        }

        return ['text' => $text, 'number' => $number];
    }

    private static function extractFolioTextPart(string $beforeNumber): string
    {
        $beforeNumber = trim($beforeNumber, '-');
        if ($beforeNumber === '') {
            return '';
        }

        $segments = array_values(array_filter(
            explode('-', $beforeNumber),
            fn (string $segment) => preg_match('/[a-z]/', $segment)
        ));

        if ($segments === []) {
            return '';
        }

        return (string) end($segments);
    }

    /**
     * @return list<string>
     */
    public static function paddedSuffixes(int $number, int $maxPad = 6): array
    {
        $suffixes = [];
        $base = (string) $number;

        for ($pad = strlen($base); $pad <= $maxPad; $pad++) {
            $suffixes[] = str_pad($base, $pad, '0', STR_PAD_LEFT);
        }

        return array_values(array_unique($suffixes));
    }

    private static function applyFlexibleFolioMatch(Builder $builder, string $text, int $number): void
    {
        $textTerm = '%'.mb_strtolower($text).'%';
        $suffixes = self::paddedSuffixes($number);

        $builder->orWhere(function (Builder $folio) use ($textTerm, $suffixes) {
            $folio->whereRaw('LOWER(folio) LIKE ?', [$textTerm])
                ->where(function (Builder $suffixQuery) use ($suffixes) {
                    foreach ($suffixes as $suffix) {
                        $suffixQuery->orWhereRaw('LOWER(folio) LIKE ?', ['%-'.$suffix])
                            ->orWhereRaw('LOWER(folio) LIKE ?', ['%'.$suffix]);
                    }
                });
        });
    }
}
