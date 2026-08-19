<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class QuoteFolioGenerator
{
    public function generate(?User $user = null): string
    {
        $basePrefix = $this->resolveBasePrefix();
        $code = $this->resolveUserCode($user ?? Auth::user());
        $pad = max(1, (int) config('quotes.folio_sequence_pad', 4));
        $folioPrefix = $code !== null
            ? "{$basePrefix}-{$code}"
            : "{$basePrefix}-".now()->format('Y');
        $maxSeq = $this->maxSequenceForPrefix($folioPrefix);

        for ($offset = 1; $offset <= 50; $offset++) {
            $folio = sprintf('%s-%0'.$pad.'d', $folioPrefix, $maxSeq + $offset);
            if (! Quote::query()->where('folio', '=', $folio, 'and')->exists()) {
                return $folio;
            }
        }

        throw new \RuntimeException('No se pudo generar un folio único.');
    }

    private function maxSequenceForPrefix(string $folioPrefix): int
    {
        $needle = "{$folioPrefix}-";
        $max = 0;

        Quote::query()
            ->where('folio', 'like', $needle.'%', 'and')
            ->pluck('folio')
            ->each(function (string $folio) use (&$max) {
                if (preg_match('/-(\d+)$/', $folio, $matches)) {
                    $max = max($max, (int) $matches[1]);
                }
            });

        return $max;
    }

    private function resolveBasePrefix(): string
    {
        $prefix = trim((string) config('quotes.folio_prefix', 'COT'));
        if ($prefix === '') {
            $prefix = 'COT';
        }

        return Str::upper($prefix);
    }

    private function resolveUserCode(mixed $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        return $user->resolveQuoteFolioCode();
    }
}
