<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use App\Models\QuoteStatusEvent;
use Illuminate\Support\Facades\Auth;

class QuoteStatusHistoryService
{
    public function record(Quote $quote, ?string $fromStatus, string $toStatus): void
    {
        if ($fromStatus === $toStatus) {
            return;
        }

        QuoteStatusEvent::query()->create([
            'quote_id' => $quote->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'user_id' => Auth::id(),
            'created_at' => now(),
        ]);
    }

    /**
     * @return list<array{fromStatus: string|null, toStatus: string, userName: string|null, createdAt: string}>
     */
    public function timelineForQuote(Quote $quote): array
    {
        return QuoteStatusEvent::query()
            ->with('user')
            ->where('quote_id', '=', $quote->id, 'and')
            ->orderBy('created_at')
            ->get()
            ->map(fn (QuoteStatusEvent $event) => [
                'fromStatus' => $event->from_status,
                'toStatus' => $event->to_status,
                'userName' => $event->user?->name,
                'createdAt' => $event->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
