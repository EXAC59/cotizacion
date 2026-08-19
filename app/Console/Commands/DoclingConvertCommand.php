<?php

namespace App\Console\Commands;

use App\Services\DoclingClient;
use Illuminate\Console\Command;

class DoclingConvertCommand extends Command
{
    protected $signature = 'docling:convert {path : Ruta absoluta o relativa al PDF/Excel/Word} {--no-ocr : Desactiva OCR (recomendado en xlsx/docx)}';

    protected $description = 'Convierte un archivo con Docling Serve y muestra Markdown en consola';

    public function handle(DoclingClient $docling): int
    {
        $path = $this->argument('path');

        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:\\\\/', $path)) {
            $path = base_path($path);
        }

        if (! is_file($path)) {
            $this->error("No existe: {$path}");

            return self::FAILURE;
        }

        $this->line('Enviando a Docling: '.$path);

        try {
            $result = $docling->convertFileToMarkdown($path, ! $this->option('no-ocr'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $markdown = $this->extractMarkdown($result);

        if ($markdown === '') {
            $this->warn('Sin markdown en la respuesta. JSON completo:');
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($markdown);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function extractMarkdown(array $result): string
    {
        if (isset($result['document']['md_content']) && is_string($result['document']['md_content'])) {
            return $result['document']['md_content'];
        }

        if (isset($result['md_content']) && is_string($result['md_content'])) {
            return $result['md_content'];
        }

        $documents = $result['documents'] ?? null;
        if (is_array($documents)) {
            foreach ($documents as $doc) {
                if (is_array($doc) && isset($doc['md_content']) && is_string($doc['md_content'])) {
                    return $doc['md_content'];
                }
            }
        }

        return '';
    }
}
