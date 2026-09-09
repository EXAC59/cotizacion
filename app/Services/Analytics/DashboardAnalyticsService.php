<?php

namespace App\Services\Analytics;

use App\Models\AppSetting;
use App\Models\ComparisonJob;
use App\Models\Quote;
use App\Models\QuoteLineOffer;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Models\Wholesaler;
use App\Services\Wholesalers\CvaCatalogIndex;
use App\Services\Wholesalers\LowStock\LowStockPollService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardAnalyticsService
{
    /** @var list<string> */
    /** Aún no terminadas por n8n/Docling (excluye procesada y precios_listos). */
    private const PENDING_REQUEST_STATUSES = ['pendiente', 'procesando'];

    /**
     * @return list<string>
     */
    private function quoteStatuses(): array
    {
        return config('quotes.statuses', [
            'en_elaboracion',
            'pendiente_envio',
            'enviada',
            'aceptada',
            'facturada',
        ]);
    }

    /**
     * @return list<string>
     */
    private function wonStatuses(): array
    {
        return config('quotes.won_statuses', ['aceptada', 'facturada']);
    }

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    public function resolvePeriod(?string $from, ?string $to): array
    {
        if ($from !== null && $to !== null) {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->endOfDay();

            if ($start->gt($end)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }

            return ['from' => $start, 'to' => $end];
        }

        $now = Carbon::now();

        return [
            'from' => $now->copy()->startOfMonth()->startOfDay(),
            'to' => $now->copy()->endOfMonth()->endOfDay(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardPayload(?string $from = null, ?string $to = null, ?User $viewer = null): array
    {
        $period = $this->resolvePeriod($from, $to);
        $sentUnsent = $this->quotesSentUnsentCounts($viewer);

        return [
            'period' => $this->periodArray($period),
            ...$this->kpiBlock($period),
            'alerts' => $this->alertsBlock($viewer),
            'quotesByStatus' => $this->quotesByStatus($period),
            'quotesSent' => $sentUnsent['sent'],
            'quotesUnsent' => $sentUnsent['unsent'],
            'recentQuotes' => $this->recentQuotes($viewer),
            'pendingRequests' => $this->pendingRequestsCount(),
            'unansweredQuoteDays' => AppSetting::current()->resolvedUnansweredQuoteDays(),
            'topRequestedProducts' => $this->topRequestedProducts($period),
            'topQuotedProducts' => $this->topQuotedProducts($period),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reportesPayload(?string $from = null, ?string $to = null): array
    {
        $period = $this->resolvePeriod($from, $to);

        return [
            'period' => $this->periodArray($period),
            ...$this->kpiBlock($period),
            'profitBySalesperson' => $this->profitBySalesperson($period),
            'profitTrend' => $this->profitTrend(),
            'topQuotedProducts' => $this->topQuotedProducts($period, 10),
            'topRequestedProducts' => $this->topRequestedProducts($period, 10),
            'topWholesalers' => $this->topWholesalers($period),
        ];
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $period
     * @return array<string, mixed>
     */
    private function kpiBlock(array $period): array
    {
        $won = $this->wonQuotesCount($period);
        $lost = $this->lostQuotesCount($period);
        $closed = $won + $lost;

        return [
            'monthlyRealizedProfit' => $this->realizedProfit($period),
            'monthlyPotentialProfit' => $this->potentialProfit($period),
            'averageTicket' => $this->averageTicket($period),
            'wonQuotes' => $won,
            'lostQuotes' => $lost,
            'winRate' => $closed > 0 ? round(($won / $closed) * 100, 1) : 0.0,
        ];
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $period
     * @return array{from: string, to: string}
     */
    private function periodArray(array $period): array
    {
        return [
            'from' => $period['from']->toDateString(),
            'to' => $period['to']->toDateString(),
        ];
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $period
     */
    private function realizedProfit(array $period): float
    {
        return $this->sumLineProfit($period, $this->wonStatuses());
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $period
     */
    private function potentialProfit(array $period): float
    {
        return $this->sumLineProfit($period, null);
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $period
     * @param  list<string>|null  $statuses
     */
    private function sumLineProfit(array $period, ?array $statuses): float
    {
        $query = DB::table('quote_lines')
            ->join('quotes', 'quotes.id', '=', 'quote_lines.quote_id')
            ->whereBetween('quotes.created_at', [$period['from'], $period['to']]);

        if ($statuses !== null) {
            $query->whereIn('quotes.status', $statuses);
        }

        $sum = $query->selectRaw(
            'COALESCE(SUM(quote_lines.quantity * (quote_lines.sale_price - quote_lines.cost)), 0) as profit'
        )->value('profit');

        return round((float) $sum, 2);
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $period
     */
    private function averageTicket(array $period): float
    {
        $avg = Quote::query()
            ->whereBetween('created_at', [$period['from'], $period['to']])
            ->where('total', '>', 0)
            ->avg('total');

        return round((float) ($avg ?? 0), 2);
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $period
     * @return list<array{userId: int|null, name: string, profit: float, quoteCount: int}>
     */
    private function profitBySalesperson(array $period): array
    {
        $rows = DB::table('quote_lines')
            ->join('quotes', 'quotes.id', '=', 'quote_lines.quote_id')
            ->leftJoin('users', 'users.id', '=', 'quotes.created_by')
            ->whereBetween('quotes.created_at', [$period['from'], $period['to']])
            ->whereIn('quotes.status', $this->wonStatuses())
            ->selectRaw("
                quotes.created_by as user_id,
                COALESCE(users.name, 'Sin asignar') as user_name,
                COALESCE(SUM(quote_lines.quantity * (quote_lines.sale_price - quote_lines.cost)), 0) as profit,
                COUNT(DISTINCT quotes.id) as quote_count
            ")
            ->groupBy('quotes.created_by', 'users.name')
            ->orderByDesc('profit')
            ->get();

        return $rows->map(fn ($row) => [
            'userId' => $row->user_id !== null ? (int) $row->user_id : null,
            'name' => (string) $row->user_name,
            'profit' => round((float) $row->profit, 2),
            'quoteCount' => (int) $row->quote_count,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function alertsBlock(?User $viewer = null): array
    {
        return [
            'lowStock' => $this->lowStockAlerts(),
            'pendingQuotes' => $this->pendingQuotes($viewer),
            'unansweredQuotes' => $this->unansweredQuotes($viewer),
            'readyForSalesQuotes' => $this->readyForSalesQuotes($viewer),
            'integrationIssues' => $this->integrationIssues(),
            'expiringQuotes' => $this->expiringQuotes($viewer),
            'stuckProcessingRequests' => $this->stuckProcessingRequests(),
            'pendingReviewRequests' => $this->pendingReviewRequests($viewer),
            'unsentRequests' => $this->unsentRequests($viewer),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Quote>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Quote>
     */
    private function constrainQuoteMaker($query, ?User $viewer)
    {
        if ($viewer?->role_slug !== 'ventas') {
            return $query;
        }

        $query->whereHas('creator.role', fn ($role) => $role->where('slug', 'ventas'));
        $query->where(function ($inner) use ($viewer) {
            $inner->where('created_by', $viewer->id)
                ->orWhere(fn ($byName) => $byName->madeByDisplayName($viewer));
        });

        return $query;
    }

    /**
     * Ventas solo ve solicitudes hechas por su cuenta (id o “Hecha por”).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<QuoteRequest>  $query
     * @return \Illuminate\Database\Eloquent\Builder<QuoteRequest>
     */
    private function constrainRequestMaker($query, ?User $viewer)
    {
        if ($viewer?->role_slug !== 'ventas') {
            return $query;
        }

        return $query->ownedByUser($viewer);
    }

    /**
     * @return array{sent: int, unsent: int}
     */
    private function quotesSentUnsentCounts(?User $viewer = null): array
    {
        $base = $this->constrainQuoteMaker(Quote::query(), $viewer);

        // Evidencia real de envío al cliente: solo sent_at (no status ni workflow_status).
        $sent = (clone $base)->whereNotNull('sent_at')->count();
        $unsent = (clone $base)->whereNull('sent_at')->count();

        return [
            'sent' => $sent,
            'unsent' => $unsent,
        ];
    }

    /**
     * Cotizaciones que siguen en elaboración y nunca llegaron a Lista / Terminada.
     *
     * @return list<array{id: string, folio: string, clientName: string, createdByName: string|null, updatedAt: string}>
     */
    private function pendingQuotes(?User $viewer = null): array
    {
        return $this->constrainQuoteMaker(Quote::query()->with(['client', 'creator']), $viewer)
            ->where('status', 'en_elaboracion')
            ->whereDoesntHave('statusEvents', fn ($query) => $query->where('to_status', 'pendiente_envio'))
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (Quote $quote) => [
                'id' => $quote->id,
                'folio' => $quote->folio,
                'clientName' => $quote->client?->company ?? '',
                'createdByName' => $quote->creator?->name,
                'updatedAt' => ($quote->updated_at ?? $quote->created_at)?->toIso8601String() ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * Solicitudes sin cotización vinculada y sin revisión externa (creadas por ventas).
     * Ventas: solo las creadas por su cuenta.
     *
     * @return list<array{id: string, fileName: string|null, clientName: string, createdAt: string}>
     */
    private function pendingReviewRequests(?User $viewer = null): array
    {
        $query = QuoteRequest::query()
            ->with('client')
            ->whereDoesntHave('quotes')
            ->whereNotIn('status', ['procesando', 'error'])
            ->whereHas('creator.role', fn ($role) => $role->where('slug', 'ventas'))
            ->where(function ($inner) {
                $inner->whereNull('reviewed_by')
                    ->orWhereColumn('reviewed_by', 'created_by');
            });

        $this->constrainRequestMaker($query, $viewer);

        return $query
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (QuoteRequest $request) => [
                'id' => $request->id,
                'fileName' => $request->file_name,
                'clientName' => $request->client?->company ?? '',
                'createdAt' => $request->created_at?->toIso8601String() ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * Solicitudes aún sin cotización vinculada (pendientes de pasar a Compras/cotizar).
     * Ventas: solo las creadas por su cuenta.
     *
     * @return list<array{id: string, fileName: string|null, clientName: string, createdByName: string|null, workflowStatus: string|null, createdAt: string}>
     */
    private function unsentRequests(?User $viewer = null): array
    {
        $query = QuoteRequest::query()
            ->with(['client', 'creator'])
            ->whereDoesntHave('quotes')
            ->whereNotIn('status', ['procesando', 'error']);

        $this->constrainRequestMaker($query, $viewer);

        return $query
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (QuoteRequest $request) => [
                'id' => $request->id,
                'fileName' => $request->file_name,
                'clientName' => $request->client?->company ?? '',
                'createdByName' => $request->creator?->name,
                'workflowStatus' => $request->workflow_status,
                'createdAt' => $request->created_at?->toIso8601String() ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * Cotizaciones Lista / Terminada listas para que ventas continúe (envío).
     *
     * @return list<array{id: string, folio: string, clientName: string, updatedAt: string}>
     */
    private function readyForSalesQuotes(?User $viewer = null): array
    {
        return $this->constrainQuoteMaker(Quote::query()->with(['client', 'creator']), $viewer)
            ->where('status', 'pendiente_envio')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (Quote $quote) => [
                'id' => $quote->id,
                'folio' => $quote->folio,
                'clientName' => $quote->client?->company ?? '',
                'createdByName' => $quote->creator?->name,
                'updatedAt' => ($quote->updated_at ?? $quote->created_at)?->toIso8601String() ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * Solicitudes en estado procesando más allá del umbral (lectura n8n atascada).
     *
     * @return list<array{id: string, fileName: string|null, clientName: string, minutesStuck: int, updatedAt: string}>
     */
    public function stuckProcessingRequests(): array
    {
        $minutes = max(1, (int) config('dashboard.stuck_request_minutes', 15));
        $cutoff = Carbon::now()->subMinutes($minutes);

        return QuoteRequest::query()
            ->with('client')
            ->where('status', 'procesando')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('updated_at')
            ->limit(20)
            ->get()
            ->map(function (QuoteRequest $request) {
                $updatedAt = $request->updated_at ?? $request->created_at ?? now();

                return [
                    'id' => $request->id,
                    'fileName' => $request->file_name,
                    'clientName' => $request->client?->company ?? '',
                    'minutesStuck' => (int) $updatedAt->diffInMinutes(now()),
                    'updatedAt' => $updatedAt->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Stock bajo del dashboard (tope 20).
     *
     * @return list<array<string, mixed>>
     */
    private function lowStockAlerts(): array
    {
        return $this->lowStockItems(20);
    }

    /**
     * Productos con stock bajo: snapshots del poller + catálogo CVA + ofertas recientes.
     *
     * @return list<array<string, mixed>>
     */
    public function lowStockItems(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        $threshold = max(0, (int) AppSetting::current()->min_stock_alert);
        if ($threshold <= 0) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $merged */
        $merged = [];

        foreach (app(LowStockPollService::class)->freshSnapshots($limit, $threshold) as $row) {
            $merged[$this->lowStockKey($row)] = $row;
        }

        foreach ($this->lowStockFromCvaCatalog($threshold, $limit) as $row) {
            $merged[$this->lowStockKey($row)] = $row;
        }

        foreach ($this->lowStockFromRecentOffers($threshold, $limit) as $row) {
            // Ofertas del comparador actualizan/completan el mismo producto+almacén.
            $merged[$this->lowStockKey($row)] = $row;
        }

        $alerts = array_values($merged);
        usort($alerts, static fn (array $a, array $b): int => ((int) $a['stock']) <=> ((int) $b['stock']));

        return array_slice($alerts, 0, $limit);
    }

    public function lowStockThreshold(): int
    {
        return max(0, (int) AppSetting::current()->min_stock_alert);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function lowStockKey(array $row): string
    {
        return strtoupper(implode('|', [
            (string) ($row['wholesalerCode'] ?? $row['wholesalerName'] ?? ''),
            (string) ($row['partNumber'] ?? ''),
            (string) ($row['warehouse'] ?? ''),
        ]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lowStockFromCvaCatalog(int $threshold, int $limit): array
    {
        $cvaName = Wholesaler::query()->where('code', 'CVA')->value('name') ?: 'Grupo CVA';

        return array_map(
            static fn (array $row): array => [
                'partNumber' => $row['partNumber'],
                'product' => $row['product'],
                'stock' => $row['stock'],
                'warehouse' => $row['warehouse'] !== '' ? $row['warehouse'] : 'Almacén CVA',
                'minimum' => $threshold,
                'wholesalerName' => (string) $cvaName,
                'wholesalerCode' => 'CVA',
            ],
            app(CvaCatalogIndex::class)->lowStockProducts($threshold, $limit),
        );
    }

    /**
     * Ofertas de cualquier mayorista guardadas al cotizar (incluye almacén).
     *
     * @return list<array<string, mixed>>
     */
    private function lowStockFromRecentOffers(int $threshold, int $limit): array
    {
        $lookback = Carbon::now()->subDays((int) config('dashboard.low_stock_offer_lookback_days', 30));

        $rows = DB::table('quote_line_offers')
            ->join('quote_lines', 'quote_lines.id', '=', 'quote_line_offers.quote_line_id')
            ->join('quotes', 'quotes.id', '=', 'quote_lines.quote_id')
            ->join('wholesalers', 'wholesalers.id', '=', 'quote_line_offers.wholesaler_id')
            ->where('quotes.created_at', '>=', $lookback)
            ->where('quote_line_offers.stock', '>', 0)
            ->where('quote_line_offers.stock', '<', $threshold)
            ->selectRaw('
                quote_lines.part_number,
                quote_lines.product,
                MIN(quote_line_offers.stock) as min_stock,
                quote_line_offers.warehouse,
                wholesalers.name as wholesaler_name,
                wholesalers.code as wholesaler_code
            ')
            ->groupBy(
                'quote_lines.part_number',
                'quote_lines.product',
                'quote_line_offers.warehouse',
                'wholesalers.name',
                'wholesalers.code',
            )
            ->orderBy('min_stock')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'partNumber' => (string) ($row->part_number ?? ''),
            'product' => (string) $row->product,
            'stock' => (int) $row->min_stock,
            'warehouse' => (string) ($row->warehouse !== '' ? $row->warehouse : 'Sin almacén'),
            'minimum' => $threshold,
            'wholesalerName' => (string) $row->wholesaler_name,
            'wholesalerCode' => (string) $row->wholesaler_code,
        ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unansweredQuotes(?User $viewer = null): array
    {
        $days = AppSetting::current()->resolvedUnansweredQuoteDays();
        $cutoff = Carbon::now()->subDays($days);

        return $this->constrainQuoteMaker(Quote::query()->with(['client', 'creator']), $viewer)
            ->whereIn('status', ['en_elaboracion', 'pendiente_envio'])
            ->where('updated_at', '<', $cutoff)
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('last_opened_at')
                    ->orWhere('last_opened_at', '<', $cutoff);
            })
            ->orderBy('updated_at')
            ->limit(10)
            ->get()
            ->map(function (Quote $quote) {
                $lastActivity = $quote->last_opened_at?->gt($quote->updated_at)
                    ? $quote->last_opened_at
                    : $quote->updated_at;

                return [
                    'id' => $quote->id,
                    'folio' => $quote->folio,
                    'clientName' => $quote->client?->company ?? '',
                    'createdByName' => $quote->creator?->name,
                    'daysWaiting' => $lastActivity ? (int) $lastActivity->diffInDays(now()) : 0,
                    'lastActivityAt' => $lastActivity?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{type: string, label: string, detail?: string}>
     */
    private function integrationIssues(): array
    {
        $issues = [];

        Wholesaler::query()
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->each(function (Wholesaler $wholesaler) use (&$issues) {
                if (! $wholesaler->isConfigured()) {
                    $issues[] = [
                        'type' => 'wholesaler',
                        'label' => $wholesaler->name,
                        'detail' => 'Mayorista activo sin credenciales de API configuradas',
                    ];
                }
            });

        if (Schema::hasTable('comparison_jobs')) {
            $lookback = Carbon::now()->subDays((int) config('dashboard.integration_error_lookback_days', 7));

            ComparisonJob::query()
                ->where('status', ComparisonJob::STATUS_ERROR)
                ->where('updated_at', '>=', $lookback)
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get()
                ->each(function (ComparisonJob $job) use (&$issues) {
                    $message = $job->error_message ?? 'Error en comparación de precios';
                    $issues[] = [
                        'type' => 'comparison',
                        'label' => $job->part_number,
                        'detail' => mb_strlen($message) > 120 ? mb_substr($message, 0, 117).'...' : $message,
                    ];
                });
        }

        return $issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expiringQuotes(): array
    {
        $windowDays = (int) config('dashboard.expiring_quote_days', 7);
        $now = Carbon::now();
        $windowEnd = $now->copy()->addDays($windowDays);

        return Quote::query()
            ->with(['client', 'creator'])
            ->whereNotIn('status', ['facturada'])
            ->get()
            ->filter(function (Quote $quote) use ($windowEnd) {
                $expiresAt = $quote->created_at?->copy()->addDays((int) $quote->validity_days);
                if ($expiresAt === null) {
                    return false;
                }

                return $expiresAt->lte($windowEnd);
            })
            ->sortBy(fn (Quote $quote) => $quote->created_at?->copy()->addDays((int) $quote->validity_days))
            ->take(10)
            ->map(function (Quote $quote) use ($now) {
                $expiresAt = $quote->created_at->copy()->addDays((int) $quote->validity_days);
                $expired = $expiresAt->lt($now);

                return [
                    'id' => $quote->id,
                    'folio' => $quote->folio,
                    'clientName' => $quote->client?->company ?? '',
                    'createdByName' => $quote->creator?->name,
                    'expiresAt' => $expiresAt->toDateString(),
                    'expired' => $expired,
                    'daysRemaining' => $expired
                        ? 0
                        : (int) $now->diffInDays($expiresAt, false),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $period
     */
    private function wonQuotesCount(array $period): int
    {
        return Quote::query()
            ->whereBetween('created_at', [$period['from'], $period['to']])
            ->whereIn('status', $this->wonStatuses())
            ->count();
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $period
     */
    private function lostQuotesCount(array $period): int
    {
        return Quote::query()
            ->whereBetween('created_at', [$period['from'], $period['to']])
            ->where('status', 'rechazada')
            ->count();
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $period
     * @return array<string, int>
     */
    private function quotesByStatus(array $period): array
    {
        $counts = Quote::query()
            ->whereBetween('created_at', [$period['from'], $period['to']])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $result = [];
        foreach ($this->quoteStatuses() as $status) {
            $result[$status] = (int) ($counts[$status] ?? 0);
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentQuotes(?User $viewer = null): array
    {
        $query = Quote::query()->with(['client', 'creator']);
        $this->constrainQuoteMaker($query, $viewer);

        return $query
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (Quote $quote) => [
                'id' => $quote->id,
                'folio' => $quote->folio,
                'clientName' => $quote->client?->company ?? '',
                'createdByName' => $quote->creator?->name,
                'status' => $quote->status,
                'total' => (float) $quote->total,
                'taxPercent' => (float) $quote->tax_percent,
                'createdAt' => $quote->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function pendingRequestsCount(): int
    {
        return QuoteRequest::query()
            ->whereIn('status', self::PENDING_REQUEST_STATUSES, 'and', false)
            ->count();
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $period
     * @return list<array{name: string, partNumber: string, count: float}>
     */
    private function topRequestedProducts(array $period, int $limit = 10): array
    {
        $groupKey = "COALESCE(NULLIF(quote_request_lines.part_number, ''), quote_request_lines.product)";

        $rows = DB::table('quote_request_lines')
            ->join('quote_requests', 'quote_requests.id', '=', 'quote_request_lines.request_id')
            ->whereBetween('quote_requests.created_at', [$period['from'], $period['to']])
            ->selectRaw("
                {$groupKey} as key_name,
                MAX(quote_request_lines.product) as product,
                MAX(quote_request_lines.part_number) as part_number,
                SUM(quote_request_lines.quantity) as total_qty
            ")
            ->groupByRaw($groupKey)
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'name' => (string) ($row->product ?: $row->key_name),
            'partNumber' => (string) ($row->part_number ?? ''),
            'count' => round((float) $row->total_qty, 2),
        ])->values()->all();
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $period
     * @return list<array{name: string, partNumber: string, count: float}>
     */
    private function topQuotedProducts(array $period, int $limit = 10): array
    {
        $groupKey = "COALESCE(NULLIF(quote_lines.part_number, ''), quote_lines.product)";

        $rows = DB::table('quote_lines')
            ->join('quotes', 'quotes.id', '=', 'quote_lines.quote_id')
            ->whereBetween('quotes.created_at', [$period['from'], $period['to']])
            ->selectRaw("
                {$groupKey} as key_name,
                MAX(quote_lines.product) as product,
                MAX(quote_lines.part_number) as part_number,
                SUM(quote_lines.quantity) as total_qty
            ")
            ->groupByRaw($groupKey)
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'name' => (string) ($row->product ?: $row->key_name),
            'partNumber' => (string) ($row->part_number ?? ''),
            'count' => round((float) $row->total_qty, 2),
        ])->values()->all();
    }

    /**
     * @return list<array{month: string, realizedProfit: float, potentialProfit: float}>
     */
    private function profitTrend(): array
    {
        $trend = [];
        $cursor = Carbon::now()->startOfMonth()->subMonths(5);

        for ($i = 0; $i < 6; $i++) {
            $period = [
                'from' => $cursor->copy()->startOfMonth()->startOfDay(),
                'to' => $cursor->copy()->endOfMonth()->endOfDay(),
            ];

            $trend[] = [
                'month' => $cursor->format('Y-m'),
                'label' => $cursor->translatedFormat('M Y'),
                'realizedProfit' => $this->realizedProfit($period),
                'potentialProfit' => $this->potentialProfit($period),
            ];

            $cursor->addMonth();
        }

        return $trend;
    }

    /**
     * @param  array{from: Carbon, to: Carbon}  $period
     * @return list<array{name: string, count: int, percent: float}>
     */
    private function topWholesalers(array $period): array
    {
        $rows = QuoteLineOffer::query()
            ->join('quote_lines', 'quote_lines.id', '=', 'quote_line_offers.quote_line_id')
            ->join('quotes', 'quotes.id', '=', 'quote_lines.quote_id')
            ->join('wholesalers', 'wholesalers.id', '=', 'quote_line_offers.wholesaler_id')
            ->whereBetween('quotes.created_at', [$period['from'], $period['to']])
            ->selectRaw('wholesalers.name, COUNT(*) as total')
            ->groupBy('wholesalers.id', 'wholesalers.name')
            ->orderByDesc('total')
            ->get();

        $grandTotal = (int) $rows->sum('total');

        return $rows->map(fn ($row) => [
            'name' => (string) $row->name,
            'count' => (int) $row->total,
            'percent' => $grandTotal > 0 ? round(((int) $row->total / $grandTotal) * 100, 1) : 0.0,
        ])->values()->all();
    }
}
