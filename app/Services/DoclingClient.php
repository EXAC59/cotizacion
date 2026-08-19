<?php

namespace App\Services;

use App\Exceptions\DoclingConversionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DoclingClient
{
    public function baseUrl(): string
    {
        return (string) config('docling.base_url');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '';
    }

    /**
     * @return array{ok: bool, status: int|null, error: string|null}
     */
    public function ping(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'status' => null, 'error' => 'DOCLING_BASE_URL vacío'];
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->timeout(15)
                ->get('/docs');

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'error' => $response->successful() ? null : $response->body(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Convierte un archivo local a Markdown vía Docling Serve.
     *
     * @return array<string, mixed>
     */
    public function convertFileToMarkdown(string $absolutePath, ?bool $doOcr = null): array
    {
        if (! is_file($absolutePath)) {
            throw new \InvalidArgumentException("Archivo no encontrado: {$absolutePath}");
        }

        $doOcr ??= (bool) config('docling.do_ocr');
        $mime = mime_content_type($absolutePath) ?: 'application/octet-stream';
        $basename = basename($absolutePath);

        $fields = [
            'to_formats' => 'md',
            'do_ocr' => $doOcr ? 'true' : 'false',
            'force_ocr' => 'false',
            'table_mode' => (string) config('docling.table_mode', 'accurate'),
            'abort_on_error' => 'false',
        ];

        $langs = config('docling.ocr_langs', ['es', 'en']);
        if ($langs !== []) {
            $fields['ocr_lang'] = implode(',', $langs);
        }

        /** @var Response $response */
        $response = Http::baseUrl($this->baseUrl())
            ->timeout((int) config('docling.timeout', 180))
            ->acceptJson()
            ->attach('files', file_get_contents($absolutePath) ?: '', $basename, ['Content-Type' => $mime])
            ->post('/v1/convert/file', $fields);

        if ($response->failed()) {
            Log::error('Docling convert/file falló', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $response->throw();
        }

        $result = $response->json() ?? [];
        $this->assertConversionSucceeded($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertConversionSucceeded(array $result): void
    {
        $status = $result['status'] ?? null;
        $errors = $result['errors'] ?? [];

        if (in_array($status, ['skipped', 'failure'], true)) {
            $message = 'Docling no pudo convertir el archivo.';
            if (is_array($errors) && isset($errors[0]['error_message'])) {
                $message = (string) $errors[0]['error_message'];
            }

            throw new DoclingConversionException($message);
        }

        $markdown = DoclingMarkdownExtractor::extract($result);
        if ($markdown === '' && is_array($errors) && $errors !== []) {
            $message = (string) ($errors[0]['error_message'] ?? 'Sin contenido legible en el archivo.');

            throw new DoclingConversionException($message);
        }
    }
}
