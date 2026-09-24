<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use App\Models\User;

class QuoteActivityService
{
    public function record(Quote $quote, ?User $actor): Quote
    {
        $values = [
            'last_activity_at' => now(),
            'last_activity_by' => $actor?->id,
        ];

        if ($actor?->role_slug === 'ventas') {
            $values['involucrado'] = $this->appendParticipant($quote->involucrado, $actor->name);
        }

        $quote->forceFill($values)->saveQuietly();

        return $quote->fresh();
    }

    public function recordSentQuoteModification(Quote $quote, ?User $actor): Quote
    {
        $name = trim((string) ($actor?->name ?? 'Usuario'));
        $notice = "Aviso: {$name} modificó una cotización enviada; pasó a Modificación.";
        $lines = collect(preg_split('/\r?\n/', trim((string) $quote->involucrado)) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values();

        if (! $lines->contains($notice)) {
            $lines->push($notice);
        }

        $quote->forceFill([
            'involucrado' => $lines
                ->map(fn (string $line, int $index) => ($index + 1).'. '.$line)
                ->implode("\n"),
            'last_activity_at' => now(),
            'last_activity_by' => $actor?->id,
        ])->saveQuietly();

        return $quote->fresh();
    }

    private function appendParticipant(?string $current, string $name): string
    {
        $names = collect(preg_split('/\r?\n/', trim((string) $current)) ?: [])
            ->map(fn (string $line) => trim((string) preg_replace('/^\d+\.\s*/u', '', $line)))
            ->filter()
            ->values();

        $normalized = Quote::normalizeDisplayName($name);
        if (! $names->contains(fn (string $existing) => Quote::normalizeDisplayName($existing) === $normalized)) {
            $names->push(trim($name));
        }

        return $names
            ->map(fn (string $participant, int $index) => ($index + 1).'. '.$participant)
            ->implode("\n");
    }
}
