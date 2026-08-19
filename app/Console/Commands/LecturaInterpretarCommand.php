<?php

namespace App\Console\Commands;

use App\Exceptions\XlsConversionException;
use App\Services\DoclingClient;
use App\Services\DoclingMarkdownExtractor;
use App\Services\LecturaArchivoPreparer;
use App\Services\LecturaInterpretacionService;
use Illuminate\Console\Command;

class LecturaInterpretarCommand extends Command
{
    protected $signature = 'lectura:interpretar
                            {archivo : Ruta al PDF, Excel o Word}
                            {--no-ocr : Desactivar OCR en Docling}';

    protected $description = 'Prueba lectura Docling + interpretación con parser';

    public function handle(
        DoclingClient $docling,
        LecturaInterpretacionService $interpretacion,
        LecturaArchivoPreparer $archivoPreparer,
    ): int {
        $path = $this->argument('archivo');

        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            $path = base_path($path);
        }

        if (! is_file($path)) {
            $this->error("Archivo no encontrado: {$path}");

            return self::FAILURE;
        }

        if (! $docling->ping()['ok']) {
            $this->error('Docling no responde.');

            return self::FAILURE;
        }

        try {
            $prepared = $archivoPreparer->resolve($path);
        } catch (XlsConversionException $e) {
            $this->error($e->userMessage());

            return self::FAILURE;
        }

        $doclingPath = $prepared['path'];
        $tempPath = $prepared['temp_path'];

        if ($prepared['converted_from_xls']) {
            $this->info('Excel .xls convertido automáticamente a .xlsx.');
        }

        $extension = strtolower(pathinfo($doclingPath, PATHINFO_EXTENSION));
        $doOcr = ! $this->option('no-ocr') && ! in_array($extension, ['xlsx', 'xls', 'docx', 'doc'], true);

        $this->info('Convirtiendo con Docling (OCR: '.($doOcr ? 'sí' : 'no').')...');

        try {
            $result = $docling->convertFileToMarkdown($doclingPath, $doOcr);
            $markdown = DoclingMarkdownExtractor::extract($result);
            $interpretacionResult = $interpretacion->interpretar($markdown);

            $this->info('Interpretación vía: '.$interpretacionResult->via);
            $this->table(
                ['Qty', 'Producto', 'SKU', 'Marca', 'Unidad'],
                collect($interpretacionResult->lineas)->map(fn ($l) => [
                    $l['quantity'],
                    mb_substr($l['product'], 0, 40),
                    $l['partNumber'],
                    $l['brand'],
                    $l['unit'],
                ])->all()
            );

            $this->info('Total líneas: '.count($interpretacionResult->lineas));

            return self::SUCCESS;
        } finally {
            $archivoPreparer->cleanup($tempPath);
        }
    }
}
