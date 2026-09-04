<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SolicitudFormatoException;
use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Services\LecturaInterpretacionService;
use App\Services\Sales\AssignToSalesService;
use App\Services\SolicitudLecturaService;
use App\Support\ViewerListScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SolicitudController extends Controller
{
    private const INTERPRETACION_VIA = 'parser';

    private const STATUSES = [
        'pendiente',
        'procesando',
        'procesada',
        'precios_listos',
        'error',
    ];

    public function __construct(
        private readonly SolicitudLecturaService $lecturaService,
        private readonly LecturaInterpretacionService $interpretacion,
        private readonly AssignToSalesService $assignToSales,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'workflow_status' => ['nullable', 'string', Rule::in(config('solicitudes.workflow_statuses', []))],
            'q' => ['nullable', 'string', 'max:120'],
            'scope' => ['nullable', 'string', Rule::in(ViewerListScope::values())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = QuoteRequest::query()
            ->with(['lines', 'client', 'creator'])
            ->orderByDesc('created_at');

        $user = $request->user();
        $user?->loadMissing('role');
        if ($user) {
            $query->forViewerList($user, $validated['scope'] ?? ViewerListScope::defaultFor($user));
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['workflow_status'])) {
            $query->where('workflow_status', $validated['workflow_status']);
        }

        if (! empty($validated['q'])) {
            $term = '%'.trim($validated['q']).'%';
            $query->where(function ($builder) use ($term) {
                $builder
                    ->where('id', 'like', $term)
                    ->orWhereHas('client', fn ($client) => $client->where('company', 'like', $term));
            });
        }

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        $requests = $query->limit(100)->get();

        return response()->json([
            'data' => $requests->map(fn (QuoteRequest $r) => $this->lecturaService->toApiArray($r))->values(),
        ]);
    }

    public function show(Request $httpRequest, string $id): JsonResponse
    {
        $request = QuoteRequest::query()->with(['lines', 'client', 'creator'])->findOrFail($id);
        $user = $httpRequest->user();
        $user?->loadMissing('role');
        if ($user?->role_slug !== 'ventas' || $request->isOwnedByUser($user)) {
            $this->lecturaService->marcarRevisada($request);
        }

        return response()->json($this->lecturaService->toApiArray($request));
    }

    public function cotizaciones(string $id): JsonResponse
    {
        QuoteRequest::query()->findOrFail($id);

        $quotes = Quote::query()
            ->with('client')
            ->where('request_id', $id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $quotes->map(fn (Quote $quote) => [
                'id' => $quote->id,
                'folio' => $quote->folio,
                'status' => $quote->status,
                'total' => (float) $quote->total,
                'createdAt' => $quote->created_at?->toIso8601String(),
                'clientName' => $quote->client?->company ?? '',
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'raw_text' => ['required', 'string', 'min:3'],
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
            'lineas' => ['nullable', 'array', 'min:1'],
            'lineas.*.quantity' => ['required_with:lineas', 'numeric', 'min:0.0001'],
            'lineas.*.product' => ['required_with:lineas', 'string', 'max:255'],
            'lineas.*.partNumber' => ['nullable', 'string', 'max:80'],
            'lineas.*.brand' => ['nullable', 'string', 'max:80'],
            'lineas.*.description' => ['nullable', 'string'],
            'lineas.*.unit' => ['nullable', 'string', 'max:20'],
        ]);

        if (! empty($validated['lineas'])) {
            $lineas = collect($validated['lineas'])->map(fn (array $line) => [
                'quantity' => (float) $line['quantity'],
                'product' => $line['product'],
                'partNumber' => $line['partNumber'] ?? '',
                'brand' => $line['brand'] ?? 'Genérico',
                'description' => $line['description'] ?? $line['product'],
                'unit' => $line['unit'] ?? 'pza',
            ])->all();
        } else {
            $result = $this->interpretacion->interpretarTexto($validated['raw_text']);
            $lineas = $result->lineas;

            if ($lineas === []) {
                return response()->json([
                    'message' => 'No se detectaron líneas en el texto.',
                ], 422);
            }
        }

        try {
            $lineas = $this->lecturaService->finalizeLineas($lineas, 'text');
        } catch (SolicitudFormatoException $e) {
            return response()->json([
                'message' => 'El texto no cumple el formato requerido.',
                'errors' => $e->errors,
            ], 422);
        }

        $quoteRequest = $this->lecturaService->crearDesdeLineas($lineas, [
            'client_id' => $validated['client_id'] ?? null,
            'source' => 'text',
            'raw_text' => $validated['raw_text'],
            'interpretacion_via' => self::INTERPRETACION_VIA,
        ]);

        return response()->json([
            'message' => 'Solicitud creada',
            'request_id' => $quoteRequest->id,
            'interpretacion_via' => self::INTERPRETACION_VIA,
            ...$this->lecturaService->toApiArray($quoteRequest),
        ], 201);
    }

    public function interpretar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'markdown' => ['required', 'string'],
            'request_id' => ['nullable', 'uuid', 'exists:quote_requests,id'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'extension' => ['nullable', 'string', 'max:10'],
            'source' => ['nullable', 'in:pdf,excel,word,text'],
        ]);

        $source = $this->resolveInterpretarSource($validated);
        $requestId = $validated['request_id'] ?? null;
        $markdown = $validated['markdown'];

        if ($source === 'text' && $requestId) {
            $rawText = QuoteRequest::query()->whereKey($requestId)->value('raw_text');
            if (is_string($rawText) && trim($rawText) !== '') {
                $markdown = $rawText;
            }
        }

        $result = $source === 'text'
            ? $this->interpretacion->interpretarTexto($markdown)
            : $this->interpretacion->interpretar($markdown);
        $parsed = $result->lineas;

        if ($parsed === []) {
            if ($requestId) {
                $this->lecturaService->actualizarDesdeN8n(
                    $requestId,
                    [],
                    'No se detectaron líneas en el documento.',
                    self::INTERPRETACION_VIA,
                );
            }

            return response()->json([
                'message' => 'No se detectaron líneas en el documento.',
            ], 422);
        }

        try {
            $lineas = $this->lecturaService->finalizeLineas($parsed, $source, $markdown);
        } catch (SolicitudFormatoException $e) {
            if ($requestId) {
                $this->lecturaService->actualizarDesdeN8n(
                    $requestId,
                    [],
                    $e->getMessage(),
                    self::INTERPRETACION_VIA,
                );
            }

            return response()->json([
                'message' => 'El archivo no cumple el formato requerido.',
                'errors' => $e->errors,
            ], 422);
        }

        if ($requestId) {
            $this->lecturaService->actualizarDesdeN8n(
                $requestId,
                $lineas,
                null,
                self::INTERPRETACION_VIA,
            );
        }

        $payload = [
            'message' => 'Interpretación completada',
            'interpretacion_via' => self::INTERPRETACION_VIA,
            'source' => $source,
            'lineas' => $lineas,
            'lineas_count' => count($lineas),
        ];

        if ($requestId) {
            $payload['request_id'] = $requestId;
        }

        return response()->json($payload);
    }

    public function updateLineas(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(
            [
                'lineas' => ['required', 'array', 'min:1'],
                'lineas.*.quantity' => ['required', 'numeric', 'min:0.0001'],
                'lineas.*.product' => ['required', 'string', 'max:255'],
                'lineas.*.partNumber' => ['nullable', 'string', 'max:80'],
                'lineas.*.brand' => ['nullable', 'string', 'max:80'],
                'lineas.*.description' => ['nullable', 'string'],
                'lineas.*.unit' => ['nullable', 'string', 'max:20'],
                'lineas.*.referenceCost' => ['nullable', 'numeric', 'min:0'],
                'lineas.*.selectedWholesalerId' => ['nullable', 'uuid'],
                'lineas.*.warehouse' => ['nullable', 'string', 'max:20'],
                'workflow_status' => ['nullable', 'string', Rule::in(['en_elaboracion', 'pendiente_envio'])],
            ],
            [
                'lineas.required' => 'Indica al menos una partida.',
                'lineas.min' => 'Indica al menos una partida.',
                'lineas.*.quantity.required' => 'Cada partida necesita una cantidad.',
                'lineas.*.quantity.min' => 'La cantidad debe ser mayor a cero.',
                'lineas.*.product.required' => 'Cada partida necesita un producto.',
                'lineas.*.product.max' => 'El producto es demasiado largo.',
            ],
            [
                'lineas' => 'partidas',
                'lineas.*.quantity' => 'cantidad',
                'lineas.*.product' => 'producto',
                'lineas.*.partNumber' => 'número de parte',
                'lineas.*.brand' => 'marca',
            ],
        );

        $quoteRequest = QuoteRequest::query()->findOrFail($id);
        $this->ensureVentasCanMutateSolicitud($request, $quoteRequest);

        $lineas = collect($validated['lineas'])->map(fn (array $line) => [
            'quantity' => (float) $line['quantity'],
            'product' => $line['product'],
            'partNumber' => $line['partNumber'] ?? '',
            'brand' => trim((string) ($line['brand'] ?? '')) !== ''
                ? trim((string) $line['brand'])
                : 'Genérico',
            'description' => $line['description'] ?? $line['product'],
            'unit' => $line['unit'] ?? 'pza',
            'referenceCost' => isset($line['referenceCost']) ? (float) $line['referenceCost'] : null,
            'selectedWholesalerId' => $line['selectedWholesalerId'] ?? null,
            'warehouse' => $line['warehouse'] ?? null,
        ])->all();

        try {
            $quoteRequest = $this->lecturaService->reemplazarLineas(
                $id,
                $lineas,
                $validated['workflow_status'] ?? 'pendiente_envio',
            );
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        $this->lecturaService->marcarRevisada($quoteRequest);

        return response()->json([
            'message' => 'Líneas actualizadas',
            ...$this->lecturaService->toApiArray($quoteRequest->fresh(['lines', 'client', 'creator.role', 'reviewer'])),
        ]);
    }

    public function asignarVentas(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipientId' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $actor = $request->user();
        if ($actor === null) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $quoteRequest = QuoteRequest::query()->findOrFail($id);
        $this->ensureVentasCanMutateSolicitud($request, $quoteRequest);
        $result = $this->assignToSales->assignSolicitud(
            $quoteRequest,
            $actor,
            isset($validated['recipientId']) ? (int) $validated['recipientId'] : null,
        );

        return response()->json([
            'message' => 'Solicitud asignada a ventas.',
            'previousFolio' => $result['previousFolio'],
            'folio' => $result['folio'],
            ...$this->lecturaService->toApiArray($result['request']),
        ]);
    }

    public function asignarCompras(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipientId' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $actor = $request->user();
        if ($actor === null) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $quoteRequest = QuoteRequest::query()->findOrFail($id);
        $this->ensureVentasCanMutateSolicitud($request, $quoteRequest);
        $result = $this->assignToSales->assignSolicitudToCompras(
            $quoteRequest,
            $actor,
            isset($validated['recipientId']) ? (int) $validated['recipientId'] : null,
        );

        return response()->json([
            'message' => 'Solicitud asignada a compras.',
            'previousFolio' => $result['previousFolio'],
            'folio' => $result['folio'],
            ...$this->lecturaService->toApiArray($result['request']),
        ]);
    }

    /**
     * @param  array{source?: string|null, file_name?: string|null, extension?: string|null}  $validated
     */
    private function resolveInterpretarSource(array $validated): string
    {
        $source = $validated['source'] ?? null;
        if ($source === 'text') {
            return 'text';
        }

        $extension = strtolower((string) ($validated['extension'] ?? ''));
        if ($extension === 'txt') {
            return 'text';
        }

        $fileName = strtolower((string) ($validated['file_name'] ?? ''));
        if (str_ends_with($fileName, '.txt') || str_contains($fileName, 'texto-libre')) {
            return 'text';
        }

        return $source ?? 'pdf';
    }

    private function ensureVentasCanMutateSolicitud(Request $request, QuoteRequest $quoteRequest): void
    {
        $user = $request->user();
        $user?->loadMissing('role');
        if ($user?->role_slug !== 'ventas') {
            return;
        }

        if (! $quoteRequest->isOwnedByUser($user)) {
            abort(403, 'Solo puedes editar solicitudes que creaste.');
        }
    }
}
