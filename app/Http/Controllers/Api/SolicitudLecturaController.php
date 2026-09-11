<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DoclingConversionException;
use App\Exceptions\N8nWebhookException;
use App\Exceptions\SolicitudFormatoException;
use App\Exceptions\XlsConversionException;
use App\Http\Controllers\Controller;
use App\Models\QuoteRequest;
use App\Services\DoclingClient;
use App\Services\DoclingMarkdownExtractor;
use App\Services\LecturaArchivoPreparer;
use App\Services\LecturaInterpretacionService;
use App\Services\N8nClient;
use App\Services\Quotes\SolicitudToQuoteService;
use App\Services\SolicitudLecturaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SolicitudLecturaController extends Controller
{
    private const INTERPRETACION_VIA = 'parser';

    public function __construct(
        private readonly DoclingClient $docling,
        private readonly N8nClient $n8n,
        private readonly LecturaInterpretacionService $interpretacion,
        private readonly SolicitudLecturaService $lecturaService,
        private readonly LecturaArchivoPreparer $archivoPreparer,
        private readonly SolicitudToQuoteService $solicitudToQuote,
    ) {}

    /**
     * Sube PDF/Excel/Word, interpreta con parser y persiste en BD.
     * via=n8n envía a webhook y devuelve 202 con request_id.
     */
    public function procesar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf,xlsx,xls,docx,doc', 'max:20480'],
            'via' => ['nullable', 'in:docling,n8n'],
            'do_ocr' => ['nullable', 'boolean'],
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
        ]);

        $via = $validated['via'] ?? 'docling';
        $file = $request->file('archivo');
        $extension = strtolower($file->getClientOriginalExtension());
        $doOcr = $validated['do_ocr'] ?? ! in_array($extension, ['xlsx', 'xls', 'docx', 'doc'], true);
        $source = SolicitudLecturaService::sourceFromExtension($extension);

        $storedPath = $file->store('solicitudes', 'local');
        if (! is_string($storedPath) || $storedPath === '') {
            return response()->json([
                'message' => 'No se pudo guardar el archivo en el servidor. Revisa permisos de storage.',
                'modo' => $via,
                'archivo' => $file->getClientOriginalName(),
            ], 500);
        }
        $absolutePath = Storage::disk('local')->path($storedPath);
        if (! is_file($absolutePath)) {
            return response()->json([
                'message' => 'El archivo se guardó pero no es legible en disco. Revisa permisos de storage.',
                'modo' => $via,
                'archivo' => $file->getClientOriginalName(),
            ], 500);
        }

        try {
            $prepared = $this->archivoPreparer->resolve($absolutePath);
        } catch (XlsConversionException $e) {
            Storage::disk('local')->delete($storedPath);

            return response()->json([
                'message' => $e->userMessage(),
                'modo' => $via,
                'archivo' => $file->getClientOriginalName(),
            ], 422);
        }

        $doclingPath = $prepared['path'];
        $convertedFromXls = $prepared['converted_from_xls'];
        $tempPath = $prepared['temp_path'];
        $processingExtension = $convertedFromXls ? 'xlsx' : $extension;

        try {
            return $this->procesarArchivo(
                $via,
                $file->getClientOriginalName(),
                $storedPath,
                $doclingPath,
                $validated,
                $source,
                $doOcr,
                $processingExtension,
                $convertedFromXls,
            );
        } finally {
            $this->archivoPreparer->cleanup($tempPath);
        }
    }

    /**
     * Convierte texto libre a .txt y lo procesa con el mismo pipeline n8n/Docling que un archivo.
     */
    public function procesarTexto(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'raw_text' => ['required', 'string', 'min:3'],
            'via' => ['nullable', 'in:docling,n8n'],
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
        ]);

        $via = $validated['via'] ?? 'n8n';
        $storedPath = 'solicitudes/'.Str::uuid().'.txt';
        Storage::disk('local')->put($storedPath, $validated['raw_text']);
        $absolutePath = Storage::disk('local')->path($storedPath);

        return $this->procesarArchivo(
            $via,
            'texto-libre.txt',
            $storedPath,
            $absolutePath,
            $validated,
            'text',
            false,
            'txt',
            false,
            $validated['raw_text'],
        );
    }

    public function reprocesar(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'do_ocr' => ['nullable', 'boolean'],
        ]);

        $quoteRequest = QuoteRequest::query()->findOrFail($id);

        if ($quoteRequest->status !== 'error') {
            return response()->json([
                'message' => 'Solo se pueden reprocesar solicitudes en estado error.',
            ], 422);
        }

        if ($quoteRequest->file_path === null || $quoteRequest->file_path === '') {
            return response()->json([
                'message' => 'La solicitud no tiene archivo almacenado para reprocesar.',
            ], 422);
        }

        if (! Storage::disk('local')->exists($quoteRequest->file_path)) {
            return response()->json([
                'message' => 'El archivo original ya no está disponible en el servidor.',
            ], 422);
        }

        $absolutePath = Storage::disk('local')->path($quoteRequest->file_path);
        $extension = strtolower(pathinfo($quoteRequest->file_name ?? $absolutePath, PATHINFO_EXTENSION));
        $source = $quoteRequest->source === 'text'
            ? 'text'
            : SolicitudLecturaService::sourceFromExtension($extension);
        $doOcr = $source === 'text'
            ? false
            : ($validated['do_ocr'] ?? ! in_array($extension, ['xlsx', 'xls', 'docx', 'doc', 'txt'], true));

        $quoteRequest->update(['status' => 'procesando', 'error_message' => null]);

        if ($source === 'text') {
            return $this->reprocesarTexto($id, $quoteRequest, $absolutePath);
        }

        try {
            $prepared = $this->archivoPreparer->resolve($absolutePath);
        } catch (XlsConversionException $e) {
            $this->lecturaService->fallarReprocesamiento($id, $e->userMessage(), self::INTERPRETACION_VIA);

            return response()->json(['message' => $e->userMessage()], 422);
        }

        $doclingPath = $prepared['path'];
        $tempPath = $prepared['temp_path'];

        try {
            if (! $this->docling->ping()['ok']) {
                $this->lecturaService->fallarReprocesamiento(
                    $id,
                    'Docling no responde. Levanta Docker: docker compose -f docker-compose.lectura.yml up -d',
                    self::INTERPRETACION_VIA,
                );

                return response()->json([
                    'message' => 'Docling no responde. Levanta Docker: docker compose -f docker-compose.lectura.yml up -d',
                ], 503);
            }

            $result = $this->docling->convertFileToMarkdown($doclingPath, $doOcr);
            $markdown = DoclingMarkdownExtractor::extract($result);

            if ($markdown === '') {
                $this->lecturaService->fallarReprocesamiento(
                    $id,
                    'El archivo se procesó pero no se detectó texto ni tablas.',
                    self::INTERPRETACION_VIA,
                );

                return response()->json([
                    'message' => 'El archivo se procesó pero no se detectó texto ni tablas.',
                ], 422);
            }

            $lineas = $source === 'text'
                ? $this->lecturaService->parsePlainTextToLineas($markdown)
                : $this->lecturaService->parseMarkdownToLineas($markdown, $source);

            if ($lineas === []) {
                $this->lecturaService->fallarReprocesamiento(
                    $id,
                    'No se detectaron líneas de productos en el documento.',
                    self::INTERPRETACION_VIA,
                );

                return response()->json([
                    'message' => 'No se detectaron líneas de productos en el documento.',
                ], 422);
            }

            $updated = $this->lecturaService->completarReprocesamiento($id, $lineas, self::INTERPRETACION_VIA);
            $ensured = $this->ensureQuotePayload($updated, request()->user());

            return response()->json([
                'message' => 'Solicitud reprocesada correctamente',
                ...$this->lecturaService->toApiArray($updated),
                ...$ensured,
            ]);
        } catch (DoclingConversionException $e) {
            $this->lecturaService->fallarReprocesamiento($id, $e->userMessage(), self::INTERPRETACION_VIA);

            return response()->json(['message' => $e->userMessage()], 422);
        } catch (SolicitudFormatoException $e) {
            $this->lecturaService->fallarReprocesamiento($id, $e->getMessage(), self::INTERPRETACION_VIA);

            return response()->json([
                'message' => 'El archivo no cumple el formato requerido.',
                'errors' => $e->errors,
            ], 422);
        } finally {
            $this->archivoPreparer->cleanup($tempPath);
        }
    }

    private function reprocesarTexto(string $id, QuoteRequest $quoteRequest, string $absolutePath): JsonResponse
    {
        $rawText = trim((string) ($quoteRequest->raw_text ?? ''));
        if ($rawText === '' && is_file($absolutePath)) {
            $rawText = trim((string) file_get_contents($absolutePath));
        }

        if ($rawText === '') {
            $this->lecturaService->fallarReprocesamiento(
                $id,
                'No hay texto disponible para reprocesar.',
                self::INTERPRETACION_VIA,
            );

            return response()->json([
                'message' => 'No hay texto disponible para reprocesar.',
            ], 422);
        }

        $lineas = $this->lecturaService->parsePlainTextToLineas($rawText);

        if ($lineas === []) {
            $this->lecturaService->fallarReprocesamiento(
                $id,
                'No se detectaron líneas de productos en el texto.',
                self::INTERPRETACION_VIA,
            );

            return response()->json([
                'message' => 'No se detectaron líneas de productos en el texto.',
            ], 422);
        }

        $updated = $this->lecturaService->completarReprocesamiento($id, $lineas, self::INTERPRETACION_VIA);
        $ensured = $this->ensureQuotePayload($updated, request()->user());

        return response()->json([
            'message' => 'Solicitud reprocesada correctamente',
            ...$this->lecturaService->toApiArray($updated),
            ...$ensured,
        ]);
    }

    /**
     * @param  array{via?: string, do_ocr?: bool, client_id?: string|null}  $validated
     */
    private function procesarArchivo(
        string $via,
        string $fileName,
        string $storedPath,
        string $doclingPath,
        array $validated,
        string $source,
        bool $doOcr,
        string $processingExtension,
        bool $convertedFromXls,
        ?string $rawText = null,
    ): JsonResponse {
        if ($via === 'n8n') {
            if (! $this->n8n->isConfigured()) {
                Storage::disk('local')->delete($storedPath);

                return response()->json([
                    'message' => 'N8N_WEBHOOK_URL no configurado en .env',
                ], 422);
            }

            $quoteRequest = $this->lecturaService->crearProcesando([
                'client_id' => $validated['client_id'] ?? null,
                'source' => $source,
                'file_name' => $fileName,
                'file_path' => $storedPath,
                'raw_text' => $rawText,
                'interpretacion_via' => self::INTERPRETACION_VIA,
            ]);

            try {
                $this->n8n->dispararLecturaArchivo($doclingPath, [
                    'request_id' => $quoteRequest->id,
                    'nombre_original' => $fileName,
                    'extension' => $processingExtension,
                    'source' => $source,
                    'do_ocr' => $doOcr ? 'true' : 'false',
                    'converted_from_xls' => $convertedFromXls ? 'true' : 'false',
                ]);
            } catch (N8nWebhookException $e) {
                $this->lecturaService->actualizarDesdeN8n(
                    $quoteRequest->id,
                    [],
                    $e->userMessage(),
                    self::INTERPRETACION_VIA,
                );

                $clientStatus = match (true) {
                    $e->httpStatus === 404, $e->httpStatus === 503 => 503,
                    default => 502,
                };

                return response()->json([
                    'message' => $e->userMessage(),
                    'modo' => 'n8n',
                    'request_id' => $quoteRequest->id,
                    'n8n_status' => $e->httpStatus,
                    'webhook_url' => config('n8n.webhook_url'),
                ], $clientStatus);
            }

            return response()->json([
                'message' => $source === 'text'
                    ? 'Texto enviado a n8n. Consulta GET /api/solicitudes/'.$quoteRequest->id.' para el resultado.'
                    : 'Archivo enviado a n8n. Consulta GET /api/solicitudes/'.$quoteRequest->id.' para el resultado.',
                'modo' => 'n8n',
                'request_id' => $quoteRequest->id,
                'archivo' => $fileName,
                'converted_from_xls' => $convertedFromXls,
                'status' => 'procesando',
            ], 202);
        }

        return $this->procesarConDocling(
            $fileName,
            $storedPath,
            $doclingPath,
            $validated,
            $source,
            $doOcr,
            $convertedFromXls,
            null,
            $rawText,
        );
    }

    /**
     * @param  array{client_id?: string|null, raw_text?: string}  $validated
     */
    private function procesarConDocling(
        string $fileName,
        string $storedPath,
        string $doclingPath,
        array $validated,
        string $source,
        bool $doOcr,
        bool $convertedFromXls,
        ?string $existingRequestId = null,
        ?string $rawText = null,
    ): JsonResponse {
        if (! $this->docling->ping()['ok']) {
            Storage::disk('local')->delete($storedPath);

            return response()->json([
                'message' => 'Docling no responde. Levanta Docker: docker compose -f docker-compose.lectura.yml up -d',
            ], 503);
        }

        try {
            $result = $this->docling->convertFileToMarkdown($doclingPath, $doOcr);
        } catch (DoclingConversionException $e) {
            Storage::disk('local')->delete($storedPath);

            return response()->json([
                'message' => $e->userMessage(),
                'modo' => 'docling',
                'archivo' => $fileName,
            ], 422);
        }

        $markdown = DoclingMarkdownExtractor::extract($result);

        if ($markdown === '') {
            Storage::disk('local')->delete($storedPath);

            return response()->json([
                'message' => 'El archivo se procesó pero no se detectó texto ni tablas.',
                'modo' => 'docling',
                'archivo' => $fileName,
            ], 422);
        }

        try {
            $lineas = $source === 'text'
                ? $this->lecturaService->parsePlainTextToLineas($markdown)
                : $this->lecturaService->parseMarkdownToLineas($markdown, $source);
        } catch (SolicitudFormatoException $e) {
            Storage::disk('local')->delete($storedPath);

            return response()->json([
                'message' => 'El archivo no cumple el formato requerido.',
                'errors' => $e->errors,
                'modo' => 'docling',
                'archivo' => $fileName,
            ], 422);
        }

        if ($lineas === []) {
            Storage::disk('local')->delete($storedPath);

            return response()->json([
                'message' => 'No se detectaron líneas de productos en el documento.',
                'modo' => 'docling',
                'archivo' => $fileName,
            ], 422);
        }

        if ($existingRequestId !== null) {
            $quoteRequest = $this->lecturaService->completarReprocesamiento(
                $existingRequestId,
                $lineas,
                self::INTERPRETACION_VIA,
            );
        } else {
            $quoteRequest = $this->lecturaService->crearDesdeLineas($lineas, [
                'client_id' => $validated['client_id'] ?? null,
                'source' => $source,
                'file_name' => $fileName,
                'file_path' => $storedPath,
                'raw_text' => $rawText ?? ($validated['raw_text'] ?? null),
                'interpretacion_via' => self::INTERPRETACION_VIA,
            ]);
        }

        $ensured = $this->ensureQuotePayload($quoteRequest, request()->user());

        return response()->json([
            'message' => 'Lectura completada',
            'modo' => 'docling',
            'request_id' => $quoteRequest->id,
            'archivo' => $fileName,
            'converted_from_xls' => $convertedFromXls,
            'do_ocr' => $doOcr,
            'interpretacion_via' => self::INTERPRETACION_VIA,
            'markdown' => $markdown,
            'lineas' => $lineas,
            'lineas_count' => count($lineas),
            ...$ensured,
            'solicitud' => $this->lecturaService->toApiArray($quoteRequest),
        ]);
    }

    /**
     * @return array{quote_id: string|null, quote_folio: string|null, quote_created: bool, quote_skipped_reason: string|null}
     */
    private function ensureQuotePayload(QuoteRequest $quoteRequest, mixed $actor): array
    {
        $ensured = $this->solicitudToQuote->ensureQuoteForRequest(
            $quoteRequest->fresh(['lines', 'client']),
            $actor instanceof \App\Models\User ? $actor : null,
        );

        return [
            'quote_id' => $ensured['quote']?->id,
            'quote_folio' => $ensured['quote']?->folio,
            'quote_created' => $ensured['created'],
            'quote_skipped_reason' => $ensured['skippedReason'],
        ];
    }
}
