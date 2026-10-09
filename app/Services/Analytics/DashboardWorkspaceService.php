<?php

namespace App\Services\Analytics;

use App\Models\Quote;
use App\Models\User;
use App\Services\Quotes\QuoteSearchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardWorkspaceService
{
    public const PENDING_STATUSES = ['solicitud_cotizaciones', 'en_elaboracion', 'pendiente_envio'];

    public function payload(User $viewer, array $filters): array
    {
        $base = Quote::query()->forViewerList($viewer, $filters['scope'] ?? 'all');
        $recent = ! empty($filters['recent']);
        if ($recent) {
            $base->where('created_at', '>=', now()->subDays(7));
        }
        if (! empty($filters['search'])) {
            QuoteSearchScope::apply($base, $filters['search']);
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (! empty($filters[$key])) {
                $base->whereDate('created_at', $operator, $filters[$key]);
            }
        }

        $counts = [];
        foreach (['pending', 'unread', 'progress', 'ready', 'sent', 'unsent'] as $focus) {
            $counts[$focus] = $this->applyFocus(clone $base, $focus, $viewer)->count();
        }
        $counts['all'] = (clone $base)->count();

        $query = $this->applyFocus(clone $base, $filters['focus'] ?? 'all', $viewer);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (($filters['delivery'] ?? 'all') === 'sent') {
            $query->whereNotNull('sent_at');
        } elseif (($filters['delivery'] ?? 'all') === 'unsent') {
            $query->whereNull('sent_at');
        }
        if (! $recent && ($filters['sort'] ?? 'date') === 'status') {
            $query->orderByRaw("CASE status WHEN 'solicitud_cotizaciones' THEN 0 WHEN 'en_elaboracion' THEN 1 WHEN 'pendiente_envio' THEN 2 WHEN 'enviada' THEN 3 WHEN 'modificacion' THEN 4 WHEN 'aceptada' THEN 5 ELSE 6 END");
        }
        $query->orderBy('created_at', ! $recent && ($filters['sort'] ?? 'date') === 'oldest' ? 'asc' : 'desc')
            ->orderBy('id');
        $page = $query->with(['client', 'creator', 'lockedByUser'])
            ->paginate($recent ? 10 : ($filters['per_page'] ?? 15), ['quotes.*'], 'page', $filters['page'] ?? 1);
        $views = DB::table('quote_user_views')->where('user_id', $viewer->id)
            ->whereIn('quote_id', $page->getCollection()->pluck('id'))->pluck('last_opened_at', 'quote_id');

        return [
            'counts' => $counts,
            'data' => $page->getCollection()->map(fn (Quote $q) => [
                'id' => $q->id,
                'folio' => $q->folio,
                'clientName' => $q->client?->company ?? '',
                'createdByName' => $q->creator?->name,
                'involucrado' => $q->involucrado,
                'status' => $q->status,
                'total' => (float) $q->total,
                'taxPercent' => (float) $q->tax_percent,
                'createdAt' => $q->created_at?->toIso8601String(),
                'elaborationDate' => $q->elaboration_date?->toDateString(),
                'sentAt' => $q->sent_at?->toIso8601String(),
                'viewedAt' => $views[$q->id] ?? null,
                'editLock' => $q->lockedByUser && $q->locked_at?->gt(now()->subSeconds(max(30, (int) config('quotes.lock_ttl_seconds', 120)))) ? [
                    'userId' => (string) $q->locked_by,
                    'userName' => $q->lockedByUser->name,
                    'userEmail' => $q->lockedByUser->email,
                    'lockedAt' => $q->locked_at->toIso8601String(),
                    'isOwn' => (int) $q->locked_by === (int) $viewer->id,
                ] : null,
            ])->values()->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
                'perPage' => $page->perPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    private function applyFocus(Builder $query, string $focus, User $viewer): Builder
    {
        return match ($focus) {
            'pending' => $query->whereNull('sent_at')->whereIn('status', self::PENDING_STATUSES),
            'progress' => $query->where('status', 'en_elaboracion')->whereNull('sent_at'),
            'ready' => $query->where('status', 'pendiente_envio')->whereNull('sent_at'),
            'sent' => $query->whereNotNull('sent_at'),
            'unsent' => $query->whereNull('sent_at'),
            'unread' => $query->whereNotExists(function ($views) use ($viewer) {
                $views->selectRaw('1')->from('quote_user_views')
                    ->whereColumn('quote_user_views.quote_id', 'quotes.id')->where('user_id', $viewer->id);
            }),
            default => $query,
        };
    }
}
