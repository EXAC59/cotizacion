<?php

namespace App\Services;

use App\Exceptions\SolicitudFormatoException;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SolicitudLecturaService
{
    public function __construct(
        private readonly SolicitudLineasValidator $lineasValidator,
        private readonly MarcaResolverService $marcaResolver,
        private readonly LecturaInterpretacionService $interpretacion,
        private readonly SolicitudFolioGenerator $folioGenerator,
    ) {}

    /**
     * @param  list<array{quantity: int|float, product: string, partNumber: string, brand: string, description?: string, unit?: string}>  $lineas
     * @param  array<string, mixed>  $meta
     */
    public function crearDesdeLineas(array $lineas, array $meta): QuoteRequest
    {
        return DB::transaction(function () use ($lineas, $meta) {
            $request = $this->crearSolicitudBase($meta, $meta['status'] ?? 'procesada');
            $this->guardarLineas($request, $lineas);

            return $request->load('lines');
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function crearProcesando(array $meta): QuoteRequest
    {
        return $this->crearSolicitudBase($meta, 'procesando');
    }

    /**
     * @param  list<array{quantity: int|float, product: string, partNumber: string, brand: string, description?: string, unit?: string}>  $lineas
     */
    public function reemplazarLineas(
        string $requestId,
        array $lineas,
        ?string $workflowStatus = null,
    ): QuoteRequest {
        return DB::transaction(function () use ($requestId, $lineas) {
            $request = QuoteRequest::query()->findOrFail($requestId);

            if (! in_array($request->status, ['procesada', 'precios_listos'], true)) {
                abort(422, 'Solo se pueden editar líneas de solicitudes procesadas.');
            }

            QuoteRequestLine::query()->where('request_id', '=', $request->id)->delete();
            $this->guardarLineas($request, $lineas);

            return $request->fresh(['lines', 'client']);
        });
    }

    public function actualizarDesdeN8n(
        string $requestId,
        array $lineas,
        ?string $error = null,
        ?string $interpretacionVia = null,
    ): QuoteRequest {
        return DB::transaction(function () use ($requestId, $lineas, $error, $interpretacionVia) {
            $request = QuoteRequest::query()->findOrFail($requestId);

            QuoteRequestLine::query()->where('request_id', '=', $request->id)->delete();

            if ($error !== null) {
                $request->update([
                    'status' => 'error',
                    'error_message' => $error,
                    'interpretacion_via' => $interpretacionVia,
                ]);

                return $request->fresh('lines');
            }

            $this->guardarLineas($request, $lineas);

            $request->update([
                'status' => 'procesada',
                'error_message' => null,
                'interpretacion_via' => $interpretacionVia,
            ]);

            return $request->fresh('lines');
        });
    }

    /**
     * @deprecated La revisión externa de solicitudes se retiró del producto.
     */
    public function marcarRevisada(QuoteRequest $request): void
    {
        // No-op.
    }

    /**
     * @deprecated La revisión externa de solicitudes se retiró del producto.
     */
    public function hasExternalReview(QuoteRequest $request): bool
    {
        return false;
    }

    /**
     * @deprecated La revisión externa de solicitudes se retiró del producto.
     */
    public function needsExternalReview(QuoteRequest $request): bool
    {
        return false;
    }

    /**
     * @deprecated El producto ya no usa workflow_status de solicitudes.
     */
    public function marcarLista(QuoteRequest $request): void
    {
        // No-op: el flujo de elaboración/lista/enviada en solicitudes se retiró.
    }

    /**
     * @deprecated Crear cotización no marca la solicitud como enviada.
     */
    public function marcarEnviada(QuoteRequest $request): void
    {
        // No-op: el envío real es quotes.sent_at vía /enviar.
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(QuoteRequest $request): array
    {
        $request->loadMissing(['lines', 'client', 'creator.role']);

        $latestQuote = $request->relationLoaded('quotes')
            ? $request->quotes->sortByDesc(fn ($quote) => $quote->created_at?->getTimestamp() ?? 0)->first()
            : $request->quotes()->orderByDesc('created_at')->first(['id', 'folio', 'request_id', 'created_at']);

        return [
            'id' => $request->id,
            'folio' => $request->folio,
            'client_id' => $request->client_id,
            'client_name' => $request->client?->company,
            'created_by' => $request->created_by,
            'created_by_name' => $request->creator?->name,
            'assigned_to_sales' => $request->creator !== null
                && $request->creator->role_slug === 'ventas',
            'assigned_to_compras' => $request->creator !== null
                && $request->creator->role_slug === 'gerente_compras',
            'needs_external_review' => false,
            'reviewed_by' => null,
            'reviewed_by_name' => null,
            'reviewed_at' => null,
            'source' => $request->source,
            'status' => $request->status,
            'workflow_status' => $request->workflow_status ?? 'en_elaboracion',
            'workflow_status_label' => config('solicitudes.workflow_labels')[$request->workflow_status ?? 'en_elaboracion'] ?? $request->workflow_status,
            'file_name' => $request->file_name,
            'raw_text' => $request->raw_text,
            'interpretacion_via' => $request->interpretacion_via,
            'error_message' => $request->error_message,
            'involucrado' => $request->involucrado,
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
            'lectura_at' => $this->resolveLecturaAt($request),
            'quote_id' => $latestQuote?->id,
            'quote_folio' => $latestQuote?->folio,
            'lineas' => $request->lines->map(fn (QuoteRequestLine $line) => [
                'id' => $line->id,
                'quantity' => (float) $line->quantity,
                'product' => $line->product,
                'partNumber' => $line->part_number,
                'brand' => $line->brand,
                'description' => $line->description,
                'unit' => $line->unit,
                'referenceCost' => $line->reference_cost !== null ? (float) $line->reference_cost : null,
                'selectedWholesalerId' => $line->selected_wholesaler_id,
                'warehouse' => $line->warehouse,
            ])->values()->all(),
            'lineas_count' => $request->lines->count(),
        ];
    }

    public static function sourceFromExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'xlsx', 'xls', 'csv' => 'excel',
            'doc', 'docx' => 'word',
            default => 'pdf',
        };
    }

    private function resolveLecturaAt(QuoteRequest $request): ?string
    {
        if (in_array($request->status, ['procesada', 'precios_listos'], true)) {
            return ($request->updated_at ?? $request->created_at)?->toIso8601String();
        }

        return null;
    }

    /**
     * Valida (Excel), resuelve marcas y normaliza líneas parseadas.
     *
     * @param  list<array<string, mixed>>  $lineas
     * @return list<array{quantity: int|float, product: string, partNumber: string, brand: string, description: string, unit: string}>
     *
     * @throws SolicitudFormatoException
     */
    public function finalizeLineas(array $lineas, string $source, ?string $markdown = null): array
    {
        if ($source === 'excel' && $markdown !== null && $markdown !== '') {
            $lineas = $this->lineasValidator->validate($markdown, $source, $lineas);
        }

        $resolved = $this->marcaResolver->resolveLines($lineas);

        return array_map(
            fn (array $line) => LecturaLineParser::normalizeLine([
                'quantity' => $line['quantity'],
                'product' => $line['product'],
                'partNumber' => $line['partNumber'],
                'brand' => $line['brand'],
                'description' => $line['description'] ?? $line['product'],
                'unit' => $line['unit'] ?? 'pza',
            ]),
            $resolved,
        );
    }

    /**
     * Interpreta markdown y aplica finalizeLineas.
     *
     * @return list<array{quantity: int|float, product: string, partNumber: string, brand: string, description: string, unit: string}>
     *
     * @throws SolicitudFormatoException
     */
    public function parseMarkdownToLineas(string $markdown, string $source): array
    {
        $lineas = $this->interpretacion->interpretar($markdown)->lineas;

        if ($lineas === []) {
            return [];
        }

        return $this->finalizeLineas($lineas, $source, $markdown);
    }

    /**
     * @return list<array{quantity: int|float, product: string, partNumber: string, brand: string, description: string, unit: string}>
     *
     * @throws SolicitudFormatoException
     */
    public function parsePlainTextToLineas(string $text): array
    {
        $lineas = $this->interpretacion->interpretarTexto($text)->lineas;

        if ($lineas === []) {
            return [];
        }

        return $this->finalizeLineas($lineas, 'text');
    }

    /**
     * @param  list<array{quantity: int|float, product: string, partNumber: string, brand: string, description?: string, unit?: string}>  $lineas
     */
    public function completarReprocesamiento(string $requestId, array $lineas, ?string $interpretacionVia = null): QuoteRequest
    {
        return $this->actualizarDesdeN8n($requestId, $lineas, null, $interpretacionVia);
    }

    /**
     * Marca solicitud en error tras fallo de reprocesamiento.
     */
    public function fallarReprocesamiento(string $requestId, string $error, ?string $interpretacionVia = null): QuoteRequest
    {
        return $this->actualizarDesdeN8n($requestId, [], $error, $interpretacionVia);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function crearSolicitudBase(array $meta, string $status): QuoteRequest
    {
        return QuoteRequest::query()->create([
            'client_id' => $meta['client_id'] ?? null,
            'created_by' => $meta['created_by'] ?? auth()->id(),
            'folio' => $this->folioGenerator->generate(),
            'source' => $meta['source'] ?? 'pdf',
            'status' => $status,
            'workflow_status' => $meta['workflow_status'] ?? config('solicitudes.default_workflow_status', 'en_elaboracion'),
            'file_name' => $meta['file_name'] ?? null,
            'file_path' => $meta['file_path'] ?? null,
            'raw_text' => $meta['raw_text'] ?? null,
            'interpretacion_via' => $meta['interpretacion_via'] ?? 'parser',
            'n8n_workflow_id' => $meta['n8n_workflow_id'] ?? null,
        ]);
    }

    /**
     * @param  list<array{quantity: int|float, product: string, partNumber: string, brand: string, description?: string, unit?: string}>  $lineas
     */
    private function guardarLineas(QuoteRequest $request, array $lineas): void
    {
        foreach ($lineas as $index => $linea) {
            QuoteRequestLine::query()->create([
                'request_id' => $request->id,
                'line_order' => $index,
                'quantity' => max(1, (float) ($linea['quantity'] ?? 1)),
                'product' => Str::limit((string) ($linea['product'] ?? ''), 255, ''),
                'part_number' => Str::limit((string) ($linea['partNumber'] ?? ''), 80, ''),
                'brand' => Str::limit((string) ($linea['brand'] ?? ''), 80, ''),
                'description' => (string) ($linea['description'] ?? $linea['product'] ?? ''),
                'unit' => Str::limit((string) ($linea['unit'] ?? 'pza'), 20, ''),
                'reference_cost' => isset($linea['referenceCost']) ? (float) $linea['referenceCost'] : null,
                'selected_wholesaler_id' => $linea['selectedWholesalerId'] ?? null,
                'warehouse' => isset($linea['warehouse']) ? Str::limit((string) $linea['warehouse'], 20, '') : null,
                'comparator_offers_json' => $linea['comparatorOffers'] ?? null,
            ]);
        }
    }
}
