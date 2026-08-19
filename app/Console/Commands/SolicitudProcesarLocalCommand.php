<?php

namespace App\Console\Commands;

use App\Models\QuoteRequest;
use App\Services\DoclingClient;
use App\Services\DoclingMarkdownExtractor;
use App\Services\LecturaInterpretacionService;
use App\Services\SolicitudLecturaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SolicitudProcesarLocalCommand extends Command
{
    protected $signature = 'solicitud:procesar-local {id : UUID de la solicitud}';

    protected $description = 'Procesa con Docling + parser una solicitud atascada en procesando';

    public function handle(
        DoclingClient $docling,
        LecturaInterpretacionService $interpretacion,
        SolicitudLecturaService $lectura,
    ): int {
        $request = QuoteRequest::query()->findOrFail($this->argument('id'));

        if (! $request->file_path) {
            $this->error('La solicitud no tiene archivo asociado.');

            return self::FAILURE;
        }

        $absolute = Storage::disk('local')->path($request->file_path);
        if (! is_file($absolute)) {
            $this->error("Archivo no encontrado: {$absolute}");

            return self::FAILURE;
        }

        if (! $docling->ping()['ok']) {
            $this->error('Docling no responde. Levanta docker compose -f docker-compose.lectura.yml up -d');

            return self::FAILURE;
        }

        $this->info("Procesando {$request->file_name} con Docling…");

        $result = $docling->convertFileToMarkdown($absolute, true);
        $markdown = DoclingMarkdownExtractor::extract($result);
        $lineas = $interpretacion->interpretar($markdown)->lineas;

        if ($lineas === []) {
            $lectura->actualizarDesdeN8n($request->id, [], 'No se detectaron líneas en el documento.', 'parser');
            $this->error('Sin líneas detectadas.');

            return self::FAILURE;
        }

        $updated = $lectura->actualizarDesdeN8n($request->id, $lineas, null, 'parser');
        $this->info("Listo: {$updated->status}, ".count($lineas).' líneas.');

        return self::SUCCESS;
    }
}
