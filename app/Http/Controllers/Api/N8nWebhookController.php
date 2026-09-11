<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SolicitudFormatoException;
use App\Http\Controllers\Controller;
use App\Models\ComparisonJob;
use App\Models\QuoteRequest;
use App\Services\LecturaLineParser;
use App\Services\Quotes\SolicitudToQuoteService;
use App\Services\SolicitudLecturaService;
use App\Services\SolicitudLineasValidator;
use App\Services\Wholesalers\ComparatorJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class N8nWebhookController extends Controller
{
    public function __construct(
        private readonly SolicitudLecturaService $lecturaService,
        private readonly SolicitudLineasValidator $lineasValidator,
        private readonly SolicitudToQuoteService $solicitudToQuote,
    ) {}

    /**
     * Recibe datos procesados por un flujo n8n (lectura de documentos, OCR, etc.).
     */
    public function recibirLectura(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fuente' => ['nullable', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', 'max:100'],
            'contenido' => ['required', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $metadata = $validated['metadata'] ?? [];
        $contenido = $validated['contenido'];
        $requestId = $metadata['request_id'] ?? null;

        $rawLineas = $contenido['lineas'] ?? [];
        $lineas = [];

        if (is_array($rawLineas)) {
            foreach ($rawLineas as $row) {
                if (is_array($row)) {
                    $lineas[] = LecturaLineParser::normalizeLine([
                        'quantity' => $row['quantity'] ?? 1,
                        'product' => $row['product'] ?? '',
                        'partNumber' => $row['partNumber'] ?? $row['part_number'] ?? '',
                        'brand' => $row['brand'] ?? 'Genérico',
                        'description' => $row['description'] ?? $row['product'] ?? '',
                        'unit' => $row['unit'] ?? 'pza',
                    ]);
                }
            }
        }

        $interpretacionVia = 'parser';
        $error = $metadata['error'] ?? null;
        $source = $metadata['source'] ?? 'pdf';

        if ($error === null && $lineas !== []) {
            try {
                $lineas = $this->lineasValidator->validate('', $source, $lineas);
            } catch (SolicitudFormatoException $e) {
                $error = $e->getMessage();
                $lineas = [];
            }
        }

        Log::info('Lectura recibida desde n8n', [
            'fuente' => $validated['fuente'] ?? null,
            'request_id' => $requestId,
            'lineas_count' => count($lineas),
        ]);

        if (! $requestId && $lineas !== []) {
            Log::warning('Callback n8n sin request_id: líneas no vinculadas a solicitud', [
                'lineas_count' => count($lineas),
            ]);
        }

        if ($requestId) {
            if ($error !== null || $lineas === []) {
                $existing = QuoteRequest::query()->withCount('lines')->find($requestId);
                if (
                    $existing
                    && $existing->status === 'procesada'
                    && $existing->lines_count > 0
                ) {
                    return response()->json([
                        'message' => 'Lectura ya registrada; se ignoró callback de error tardío',
                        'received_at' => now()->toIso8601String(),
                        'request_id' => $existing->id,
                        'solicitud' => $this->lecturaService->toApiArray($existing->load(['lines', 'client'])),
                    ]);
                }

                $quoteRequest = $this->lecturaService->actualizarDesdeN8n(
                    (string) $requestId,
                    [],
                    $error ?? 'No se detectaron líneas en el documento.',
                    $interpretacionVia,
                );
            } else {
                $quoteRequest = $this->lecturaService->actualizarDesdeN8n(
                    (string) $requestId,
                    $lineas,
                    null,
                    $interpretacionVia,
                );
                $ensured = $this->solicitudToQuote->ensureQuoteForRequest(
                    $quoteRequest->fresh(['lines', 'client']),
                    null,
                );

                return response()->json([
                    'message' => 'Lectura registrada correctamente',
                    'received_at' => now()->toIso8601String(),
                    'request_id' => $quoteRequest->id,
                    'quote_id' => $ensured['quote']?->id,
                    'quote_folio' => $ensured['quote']?->folio,
                    'quote_created' => $ensured['created'],
                    'solicitud' => $this->lecturaService->toApiArray($quoteRequest),
                ], 201);
            }

            return response()->json([
                'message' => 'Lectura registrada correctamente',
                'received_at' => now()->toIso8601String(),
                'request_id' => $quoteRequest->id,
                'solicitud' => $this->lecturaService->toApiArray($quoteRequest),
            ], 201);
        }

        return response()->json([
            'message' => 'Lectura registrada (sin request_id; no persistida en solicitud)',
            'received_at' => now()->toIso8601String(),
            'payload' => $validated,
        ], 201);
    }

    /**
     * Callback n8n — comparador de precios (ofertas agregadas por mayorista).
     */
    public function recibirComparador(Request $request, ComparatorJobService $jobs): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => ['required', 'uuid'],
            'offers' => ['present', 'array'],
            'offers.*.wholesalerId' => ['nullable', 'string'],
            'offers.*.wholesalerCode' => ['nullable', 'string'],
            'offers.*.wholesalerName' => ['nullable', 'string'],
            'offers.*.partNumber' => ['nullable', 'string'],
            'offers.*.cost' => ['nullable', 'numeric'],
            'offers.*.stock' => ['nullable', 'integer'],
            'offers.*.warehouse' => ['nullable', 'string'],
            'offers.*.leadDays' => ['nullable', 'integer'],
            'demo_mode' => ['nullable', 'boolean'],
            'n8n_execution_id' => ['nullable', 'string', 'max:100'],
            'error' => ['nullable', 'string'],
        ]);

        $job = ComparisonJob::query()->find($validated['job_id']);

        if ($job === null) {
            // n8n a veces reintenta o se dispara con job de prueba; no tumbar el workflow.
            Log::info('Callback comparador n8n ignorado: job no existe', [
                'job_id' => $validated['job_id'],
            ]);

            return response()->json([
                'message' => 'Job no encontrado; callback ignorado',
                'ignored' => true,
            ]);
        }

        if (! empty($validated['error'])) {
            if ($job->status !== ComparisonJob::STATUS_DONE) {
                $jobs->markError($job, (string) $validated['error']);
            }

            return response()->json([
                'message' => 'Comparación marcada con error',
                'job' => $job->fresh()->toApiArray(),
            ], 422);
        }

        Log::info('Comparador recibido desde n8n', [
            'job_id' => $job->id,
            'offers_count' => count($validated['offers']),
            'previous_status' => $job->status,
        ]);

        // Si Laravel ya resolvió local (CT), n8n puede enriquecer/re-rankear.
        $completed = $jobs->completeFromN8n(
            $job,
            $validated['offers'],
            $validated['n8n_execution_id'] ?? null,
            (bool) ($validated['demo_mode'] ?? false),
        );

        return response()->json([
            'message' => 'Comparación completada',
            'job' => $completed->toApiArray(),
        ], 201);
    }
}
