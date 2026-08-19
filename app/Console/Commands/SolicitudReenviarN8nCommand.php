<?php

namespace App\Console\Commands;

use App\Models\QuoteRequest;
use App\Services\N8nClient;
use App\Services\SolicitudLecturaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SolicitudReenviarN8nCommand extends Command
{
    protected $signature = 'solicitud:reenviar-n8n {id : UUID de la solicitud}';

    protected $description = 'Reenvía a n8n el archivo de una solicitud en estado procesando';

    public function handle(N8nClient $n8n, SolicitudLecturaService $lectura): int
    {
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

        if (! $n8n->isConfigured()) {
            $this->error('N8N_WEBHOOK_URL no configurado.');

            return self::FAILURE;
        }

        $extension = pathinfo($request->file_name ?? $absolute, PATHINFO_EXTENSION) ?: 'pdf';

        $this->info("Reenviando solicitud {$request->id} a n8n…");

        $n8n->dispararLecturaArchivo($absolute, [
            'request_id' => $request->id,
            'nombre_original' => $request->file_name ?? basename($absolute),
            'extension' => $extension,
            'source' => $request->source,
            'do_ocr' => 'true',
        ]);

        $request->update(['status' => 'procesando', 'error_message' => null]);

        $this->info('Enviado. Consulta el detalle o n8n Executions en 1–2 min.');

        return self::SUCCESS;
    }
}
