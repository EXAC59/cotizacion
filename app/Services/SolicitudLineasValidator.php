<?php

namespace App\Services;

use App\Exceptions\SolicitudFormatoException;

class SolicitudLineasValidator
{
    /** Optional: unit (UNIDAD) — defaults to pza when omitted. */
    private const COLUMN_LABELS = [
        'quantity' => 'CANTIDAD',
        'product' => 'PRODUCTO',
        'partNumber' => 'NO.PARTE',
        'brand' => 'MARCA',
        'unit' => 'UNIDAD',
    ];

    public function __construct(
        private readonly LecturaLineParser $parser,
    ) {}

    /**
     * @param  list<array{quantity: int|float, product: string, partNumber: string, brand: string, description?: string, unit?: string}>  $lineas
     * @return list<array{quantity: int|float, product: string, partNumber: string, brand: string, description: string, unit: string}>
     */
    public function validate(string $markdown, string $source, array $lineas): array
    {
        $errors = [];

        if ($source === 'excel') {
            $columnMap = $this->parser->detectColumnMap($markdown);
            $missing = $this->parser->missingRequiredColumns($columnMap, $source);
            if ($missing !== []) {
                $errors['columnas_faltantes'] = array_map(
                    fn (string $key) => self::COLUMN_LABELS[$key] ?? $key,
                    $missing,
                );
            }
        }

        $valid = [];
        $invalid = 0;
        $ejemplo = null;

        foreach ($lineas as $line) {
            $issue = $this->lineIssue($line, $source);
            if ($issue !== null) {
                $invalid++;
                $ejemplo ??= $issue;

                continue;
            }
            $valid[] = LecturaLineParser::normalizeLine($line);
        }

        if ($invalid > 0) {
            $errors['lineas_invalidas'] = $invalid;
            if ($ejemplo !== null) {
                $errors['ejemplo'] = $ejemplo;
            }
        }

        $total = count($valid) + $invalid;
        if ($total === 0 || count($valid) === 0) {
            $errors['lineas_validas'] = 0;
        } elseif ($invalid > 0 && $invalid / $total > 0.5) {
            $errors['demasiadas_invalidas'] = true;
        }

        if ($errors !== []) {
            throw new SolicitudFormatoException(
                $this->buildMessage($errors),
                $errors,
            );
        }

        return $valid;
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    private function buildMessage(array $errors): string
    {
        if (! empty($errors['columnas_faltantes'])) {
            return 'El archivo debe incluir columnas: '.implode(', ', $errors['columnas_faltantes']).'.';
        }

        if (! empty($errors['demasiadas_invalidas'])) {
            return 'Más del 50% de las filas no cumplen el formato requerido.';
        }

        return 'El archivo no cumple el formato requerido.';
    }

    /**
     * @param  array{quantity?: int|float, product?: string, partNumber?: string, brand?: string, unit?: string}  $line
     */
    private function lineIssue(array $line, string $source): ?string
    {
        $product = trim((string) ($line['product'] ?? ''));
        $partNumber = trim((string) ($line['partNumber'] ?? ''));
        $quantity = (float) ($line['quantity'] ?? 0);

        if ($product === '' || strlen($product) < 5) {
            return 'Fila sin descripción de producto válida';
        }

        if ($product === $partNumber) {
            return 'El producto no puede ser igual al número de parte';
        }

        if (LecturaLineParser::looksLikePartNumberOnly($product)) {
            return 'Fila con producto que parece solo SKU ('.$product.')';
        }

        if ($partNumber === '') {
            return 'Fila sin NO.PARTE';
        }

        if (preg_match('~^LINE-\d+$~i', $partNumber)) {
            return 'Fila con número de parte autogenerado';
        }

        if ($quantity <= 0) {
            return 'Fila con cantidad inválida';
        }

        return null;
    }
}
