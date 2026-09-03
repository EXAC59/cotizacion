<?php

namespace App\Services;

use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SolicitudFolioGenerator
{
    public function generate(?User $user = null): string
    {
        $basePrefix = $this->resolveBasePrefix();
        $code = $this->resolveUserCode($user ?? Auth::user());
        $pad = max(1, (int) config('solicitudes.folio_sequence_pad', 4));
        $folioPrefix = $code !== null
            ? "{$basePrefix}-{$code}"
            : "{$basePrefix}-".now()->format('Y');
        $maxSeq = $this->maxSequenceForPrefix($folioPrefix);

        for ($offset = 1; $offset <= 50; $offset++) {
            $folio = sprintf('%s-%0'.$pad.'d', $folioPrefix, $maxSeq + $offset);
            if (! QuoteRequest::query()->where('folio', '=', $folio, 'and')->exists()) {
                return $folio;
            }
        }

        throw new \RuntimeException('No se pudo generar un folio único de solicitud.');
    }

    private function maxSequenceForPrefix(string $folioPrefix): int
    {
        $needle = "{$folioPrefix}-";
        $max = 0;

        QuoteRequest::query()
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
        $prefix = Str::upper(trim((string) config('solicitudes.folio_prefix', 'SOL')) ?: 'SOL');

        return $prefix;
    }

    private function resolveUserCode(mixed $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        $explicit = $user->resolveQuoteFolioCode();
        if ($explicit !== null) {
            return $explicit;
        }

        $first = trim(Str::of((string) $user->name)->squish()->explode(' ')->first() ?? '');
        if ($first === '') {
            return null;
        }

        return Str::upper(Str::ascii((string) preg_replace('/[^A-Z0-9]+/i', '', $first))) ?: null;
    }
}
