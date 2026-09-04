<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\QuoteLockedException;
use App\Http\Controllers\Controller;
use App\Jobs\SendQuoteEmailJob;
use App\Models\Quote;
use App\Models\QuoteInternalNote;
use App\Models\User;
use App\Services\Quotes\QuoteFolioGenerator;
use App\Services\Quotes\QuoteLockService;
use App\Services\Quotes\QuotePdfService;
use App\Services\Quotes\QuotePersistenceService;
use App\Services\Quotes\QuoteProfitCalculator;
use App\Services\Quotes\QuoteSearchScope;
use App\Services\Quotes\QuoteStatusHistoryService;
use App\Services\Sales\AssignToSalesService;
use App\Services\Sales\QuoteFollowUpService;
use App\Services\Sales\SalesNotificationService;
use App\Services\Wholesalers\WholesalerSalesAliasService;
use App\Support\ViewerListScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CotizacionController extends Controller
{
    public function __construct(
        private readonly QuoteLockService $quoteLockService,
        private readonly QuoteStatusHistoryService $statusHistory,
        private readonly WholesalerSalesAliasService $wholesalerAliases,
        private readonly QuoteFollowUpService $followUps,
        private readonly SalesNotificationService $salesNotifications,
        private readonly AssignToSalesService $assignToSales,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in($this->quoteStatuses())],
            'scope' => ['nullable', 'string', Rule::in(ViewerListScope::values())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = Quote::query()
            ->with(['client', 'creator', 'lockedByUser'])
            ->withCount('lines')
            ->orderByDesc('created_at');

        $user = $request->user();
        $user?->loadMissing('role');
        if ($user) {
            $scope = $validated['scope'] ?? ViewerListScope::defaultFor($user);
            $query->forViewerList($user, $scope);
        }

        if (! empty($validated['search'])) {
            QuoteSearchScope::apply($query, $validated['search']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        $quotes = $query->limit(200)->get();

        return response()->json([
            'data' => $quotes->map(fn (Quote $q) => $this->toSummaryArray($q))->values(),
        ]);
    }

    public function proximoFolio(Request $request, QuoteFolioGenerator $folioGenerator): JsonResponse
    {
        return response()->json([
            'folio' => $folioGenerator->generate($request->user()),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $quote = Quote::query()->with(['lines.offers.wholesaler', 'client', 'internalNotes.user'])->findOrFail($id);

        return response()->json($this->toApiArray($quote));
    }

    public function calcular(Request $request, QuoteProfitCalculator $calculator): JsonResponse
    {
        $validated = $request->validate([
            'globalMarginPercent' => ['nullable', 'numeric', 'min:0'],
            'taxPercent' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.marginPercent' => ['nullable', 'numeric'],
            'lines.*.salePrice' => ['nullable', 'numeric'],
            'lines.*.usesGlobalMargin' => ['nullable', 'boolean'],
        ]);

        return response()->json($calculator->recalcQuotePayload($validated));
    }

    public function store(Request $request, QuotePersistenceService $persistence): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'string', 'max:80'],
            'folio' => ['nullable', 'string', 'max:30'],
            'clientId' => ['required', 'uuid', 'exists:clients,id'],
            'requestId' => ['nullable', 'uuid', 'exists:quote_requests,id'],
            'status' => ['nullable', 'string', Rule::in($this->quoteStatuses())],
            'invoiceNumber' => ['nullable', 'string', 'max:80'],
            'validityDays' => ['nullable', 'integer', 'min:1', 'max:365'],
            'globalMarginPercent' => ['nullable', 'numeric', 'min:0'],
            'taxPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
            'customerObservations' => ['nullable', 'string', 'max:4000'],
            'sentAt' => ['nullable', 'date'],
            'createdByEmail' => ['nullable', 'email', 'max:255'],
            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['nullable', 'string', 'max:80'],
            'lines.*.lineOrder' => ['nullable', 'integer', 'min:0'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.product' => ['required', 'string', 'max:255'],
            'lines.*.partNumber' => ['nullable', 'string', 'max:80'],
            'lines.*.cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.marginPercent' => ['nullable', 'numeric', 'min:0'],
            'lines.*.salePrice' => ['nullable', 'numeric', 'min:0'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.warehouse' => ['nullable', 'string', 'max:80'],
            'lines.*.usesGlobalMargin' => ['nullable', 'boolean'],
            'lines.*.selectedWholesalerId' => ['nullable', 'uuid', 'exists:wholesalers,id'],
            'lines.*.offers' => ['nullable', 'array'],
            'lines.*.offers.*.wholesalerId' => ['required_with:lines.*.offers', 'uuid', 'exists:wholesalers,id'],
            'lines.*.offers.*.cost' => ['nullable', 'numeric'],
            'lines.*.offers.*.stock' => ['nullable', 'integer'],
            'lines.*.offers.*.warehouse' => ['nullable', 'string'],
            'lines.*.offers.*.leadDays' => ['nullable', 'integer'],
            'lines.*.offers.*.isSelected' => ['nullable', 'boolean'],
        ]);

        if (! empty($validated['id']) && Str::isUuid($validated['id'])) {
            $existing = Quote::query()->find($validated['id']);
            if ($existing) {
                $this->ensureVentasCanMutateQuote($request, $existing);
            }
        }

        if (($validated['status'] ?? null) === 'facturada' && trim((string) ($validated['invoiceNumber'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'invoiceNumber' => ['Indica el número de factura o ticket para marcar como facturada.'],
            ]);
        }

        try {
            $quote = $persistence->save($validated);
        } catch (QuoteLockedException $e) {
            return response()->json($e->payload(), 423);
        } catch (\Throwable $e) {
            if (
                $e instanceof QueryException
                && (str_contains($e->getMessage(), 'folio') || $e->getCode() === '23000')
            ) {
                return response()->json(['message' => 'El folio ya existe. Usa otro folio.'], 422);
            }

            throw $e;
        }

        return response()->json($this->toApiArray($quote), 201);
    }

    public function pdf(string $id, QuotePdfService $pdfService, Request $request)
    {
        if (! Str::isUuid($id)) {
            return response()->json([
                'message' => 'Cotización no encontrada. Usa el UUID de la cotización guardada (ej. desde /spa/cotizaciones), no el texto {id}.',
            ], 404);
        }

        try {
            $signer = $request->user();
            $pdf = $pdfService->render(
                $id,
                $signer instanceof User ? $signer : null,
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'No se puede generar el PDF.',
            ], 422);
        }

        $quote = Quote::query()->findOrFail($id);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $quote->folio).'.pdf';

        // PDF es solo vista/descarga: no marca «enviada». Eso lo hace el correo.
        if ($request->boolean('download')) {
            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }

    public function enviar(string $id, Request $request): JsonResponse
    {
        if (! Str::isUuid($id)) {
            abort(404, 'Cotización no encontrada.');
        }

        $quote = Quote::query()->with(['client', 'lines'])->find($id);

        if ($quote === null) {
            abort(404, 'Cotización no encontrada.');
        }

        if ($quote->lines->isEmpty()) {
            return response()->json([
                'message' => 'La cotización no tiene partidas para enviar.',
            ], 422);
        }

        $validated = $request->validate([
            'to' => ['nullable', 'email', 'max:255'],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->ensureVentasCanMutateQuote($request, $quote);

        $toEmail = trim($validated['to'] ?? $quote->client?->email ?? '');

        if ($toEmail === '') {
            return response()->json([
                'message' => 'El cliente no tiene correo electrónico. Indica un destinatario.',
            ], 422);
        }

        SendQuoteEmailJob::dispatch(
            $id,
            $toEmail,
            $validated['subject'] ?? null,
            $validated['message'] ?? null,
            $request->user()?->id,
        );

        return response()->json([
            'queued' => true,
            'message' => 'Cotización encolada para envío por correo.',
        ], 202);
    }

    public function agregarNotaInterna(string $id, Request $request): JsonResponse
    {
        if (! Str::isUuid($id)) {
            abort(404, 'Cotización no encontrada.');
        }

        $quote = Quote::query()->findOrFail($id);
        $this->ensureVentasCanMutateQuote($request, $quote);
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $note = QuoteInternalNote::query()->create([
            'quote_id' => $quote->id,
            'user_id' => $request->user()?->id,
            'body' => trim($validated['body']),
            'created_at' => now(),
        ])->load('user');

        return response()->json([
            'id' => $note->id,
            'body' => $note->body,
            'userName' => $note->user?->name,
            'createdAt' => $note->created_at?->toIso8601String(),
        ], 201);
    }

    public function bloquear(string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            abort(404, 'Cotización no encontrada.');
        }

        $quote = Quote::query()->findOrFail($id);
        $this->ensureVentasCanMutateQuote(request(), $quote);

        try {
            $this->quoteLockService->acquire($quote);
        } catch (QuoteLockedException $e) {
            return response()->json($e->payload(), 423);
        }

        $quote = $quote->fresh(['lockedByUser']);
        $statusChanged = false;
        // Solo borradores: al abrir, Solicitud / Lista → En elaboración.
        // Enviada/aceptada/facturada NO pasan a modificación solo por abrir.
        if ($quote !== null && in_array($quote->status, config('quotes.elaboracion_from_statuses', ['solicitud_cotizaciones', 'pendiente_envio']), true)) {
            $fromStatus = $quote->status;
            $quote->update(['status' => 'en_elaboracion']);
            $quote = $quote->fresh(['lockedByUser']);
            $this->statusHistory->record($quote, $fromStatus, 'en_elaboracion');
            $statusChanged = true;
        }

        return response()->json([
            'locked' => true,
            'status' => $quote?->status,
            'statusChanged' => $statusChanged,
            'statusHistory' => $quote ? $this->statusHistory->timelineForQuote($quote) : [],
            'editLock' => $this->quoteLockService->lockPayload($quote),
            'heartbeatSeconds' => (int) config('quotes.lock_heartbeat_seconds', 60),
        ]);
    }

    public function liberar(string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            abort(404, 'Cotización no encontrada.');
        }

        $quote = Quote::query()->findOrFail($id);
        $this->quoteLockService->release($quote);

        return response()->json(['locked' => false]);
    }

    public function asignarVentas(string $id, Request $request): JsonResponse
    {
        if (! Str::isUuid($id)) {
            abort(404, 'Cotización no encontrada.');
        }

        $validated = $request->validate([
            'recipientId' => ['nullable', 'integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'min:3', 'max:1000'],
        ]);

        $actor = $request->user();
        if ($actor === null) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $quote = Quote::query()->findOrFail($id);
        $this->ensureVentasCanMutateQuote($request, $quote);
        $result = $this->assignToSales->assignQuote(
            $quote,
            $actor,
            isset($validated['recipientId']) ? (int) $validated['recipientId'] : null,
            $validated['message'] ?? null,
        );

        return response()->json([
            'message' => 'Cotización asignada a ventas.',
            'previousFolio' => $result['previousFolio'],
            'folio' => $result['folio'],
            ...$this->toApiArray($result['quote']),
        ]);
    }

    public function asignarCompras(string $id, Request $request): JsonResponse
    {
        if (! Str::isUuid($id)) {
            abort(404, 'Cotización no encontrada.');
        }

        $validated = $request->validate([
            'recipientId' => ['nullable', 'integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'min:3', 'max:1000'],
        ]);

        $actor = $request->user();
        if ($actor === null) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $quote = Quote::query()->findOrFail($id);
        $this->ensureVentasCanMutateQuote($request, $quote);
        $result = $this->assignToSales->assignQuoteToCompras(
            $quote,
            $actor,
            isset($validated['recipientId']) ? (int) $validated['recipientId'] : null,
            $validated['message'] ?? null,
        );

        return response()->json([
            'message' => 'Cotización asignada a compras.',
            'previousFolio' => $result['previousFolio'],
            'folio' => $result['folio'],
            ...$this->toApiArray($result['quote']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toSummaryArray(Quote $quote): array
    {
        $quote->loadMissing(['client', 'creator']);

        return [
            'id' => $quote->id,
            'folio' => $quote->folio,
            'clientId' => $quote->client_id,
            'clientName' => $quote->client?->company ?? '',
            'status' => $quote->status,
            'taxPercent' => (float) $quote->tax_percent,
            'total' => (float) $quote->total,
            'linesCount' => (int) ($quote->lines_count ?? $quote->lines()->count()),
            'createdAt' => $quote->created_at?->toIso8601String(),
            'sentAt' => $quote->sent_at?->toIso8601String(),
            'responseReceivedAt' => $quote->response_received_at?->toIso8601String(),
            'invoiceNumber' => $quote->invoice_number,
            'createdByName' => $quote->creator?->name,
            'ownedByViewer' => $this->viewerOwnsQuote($quote),
            'assignedToSales' => $this->assignToSales->isAssignedToSales($quote->creator),
            'assignedToCompras' => $this->assignToSales->isAssignedToCompras($quote->creator),
            'involucrado' => $quote->involucrado,
            'followUp' => $this->followUps->followUpPayload($quote),
            'eligibility' => $this->salesNotifications->eligibility($quote),
            'editLock' => $this->quoteLockService->lockPayload($quote),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toApiArray(Quote $quote): array
    {
        $quote->loadMissing(['lines.offers.wholesaler', 'client', 'creator', 'internalNotes.user']);
        $mask = $this->wholesalerAliases->shouldMask(request()->user());

        return [
            'id' => $quote->id,
            'folio' => $quote->folio,
            'clientId' => $quote->client_id,
            'clientName' => $quote->client?->company ?? '',
            'requestId' => $quote->request_id,
            'status' => $quote->status,
            'validityDays' => (int) $quote->validity_days,
            'globalMarginPercent' => (float) $quote->global_margin_percent,
            'taxPercent' => (float) $quote->tax_percent,
            'notes' => $quote->notes,
            'customerObservations' => $quote->customer_observations ?? '',
            'internalNotes' => $quote->internalNotes->map(fn (QuoteInternalNote $note) => [
                'id' => $note->id,
                'body' => $note->body,
                'userName' => $note->user?->name,
                'createdAt' => $note->created_at?->toIso8601String(),
            ])->values(),
            'subtotal' => (float) $quote->subtotal,
            'taxAmount' => (float) $quote->tax_amount,
            'total' => (float) $quote->total,
            'createdAt' => $quote->created_at?->toIso8601String(),
            'sentAt' => $quote->sent_at?->toIso8601String(),
            'responseReceivedAt' => $quote->response_received_at?->toIso8601String(),
            'invoiceNumber' => $quote->invoice_number,
            'createdByName' => $quote->creator?->name,
            'ownedByViewer' => $this->viewerOwnsQuote($quote),
            'assignedToSales' => $this->assignToSales->isAssignedToSales($quote->creator),
            'assignedToCompras' => $this->assignToSales->isAssignedToCompras($quote->creator),
            'involucrado' => $quote->involucrado,
            'editLock' => $this->quoteLockService->lockPayload($quote),
            'statusHistory' => $this->statusHistory->timelineForQuote($quote),
            'followUp' => $this->followUps->followUpPayload($quote),
            'followUpHistory' => $this->followUps->historyForQuote($quote),
            'eligibility' => $this->salesNotifications->eligibility($quote),
            'lines' => $quote->lines->map(fn ($line) => [
                'id' => $line->id,
                'quantity' => (float) $line->quantity,
                'product' => $line->product,
                'partNumber' => $line->part_number,
                'cost' => (float) $line->cost,
                'marginPercent' => (float) $line->margin_percent,
                'salePrice' => (float) $line->sale_price,
                'amount' => (float) $line->amount,
                'warehouse' => $line->warehouse,
                'selectedWholesalerId' => $line->selected_wholesaler_id,
                'offers' => $line->offers->map(function ($offer) use ($mask) {
                    $name = $offer->wholesaler?->name;
                    $code = $offer->wholesaler?->code;
                    if ($mask) {
                        $name = $this->wholesalerAliases->aliasForCode($code);
                    }

                    return [
                        'wholesalerId' => $offer->wholesaler_id,
                        'wholesalerName' => $name,
                        'wholesalerCode' => $mask ? $name : $code,
                        'cost' => (float) $offer->cost,
                        'stock' => (int) $offer->stock,
                        'warehouse' => $offer->warehouse,
                        'leadDays' => (int) $offer->lead_days,
                        'isSelected' => (bool) $offer->is_selected,
                    ];
                })->values(),
            ])->values(),
        ];
    }

    private function viewerOwnsQuote(Quote $quote): bool
    {
        $user = request()->user();
        $user?->loadMissing('role');
        if ($user === null) {
            return true;
        }
        if ($user->role_slug !== 'ventas') {
            return true;
        }

        return $quote->isVisibleToSalesperson($user);
    }

    private function ensureVentasCanMutateQuote(Request $request, Quote $quote): void
    {
        $user = $request->user();
        $user?->loadMissing('role');
        if ($user?->role_slug !== 'ventas') {
            return;
        }

        if (! $quote->isVisibleToSalesperson($user)) {
            abort(403, 'Solo puedes editar cotizaciones que creaste o que compras te envió.');
        }
    }

    /**
     * @return list<string>
     */
    private function quoteStatuses(): array
    {
        return config('quotes.statuses', []);
    }
}
