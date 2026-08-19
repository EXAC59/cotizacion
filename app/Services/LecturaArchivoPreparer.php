<?php

namespace App\Services;

use App\Exceptions\XlsConversionException;

/**
 * Normaliza archivos de entrada (p. ej. .xls → .xlsx) antes de Docling o n8n.
 */
class LecturaArchivoPreparer
{
    public function __construct(
        private readonly LegacyXlsConverter $xlsConverter,
    ) {}

    /**
     * @return array{path: string, converted_from_xls: bool, temp_path: ?string}
     *
     * @throws XlsConversionException
     */
    public function resolve(string $absolutePath): array
    {
        $prepared = $this->xlsConverter->prepareForDocling($absolutePath);

        return [
            'path' => $prepared['path'],
            'converted_from_xls' => $prepared['converted'],
            'temp_path' => $prepared['temp_path'],
        ];
    }

    public function cleanup(?string $tempPath): void
    {
        $this->xlsConverter->deleteTemp($tempPath);
    }
}
