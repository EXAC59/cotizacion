<?php

namespace App\Services;

class LecturaLineParser
{
    /** UNIDAD is optional; missing unit defaults to pza. */
    private const EXCEL_REQUIRED_COLUMNS = ['quantity', 'product', 'partNumber', 'brand'];

    /**
     * @return list<array{quantity: int, product: string, partNumber: string, brand: string, description: string, unit: string}>
     */
    public function fromMarkdown(string $markdown): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];

        if ($this->looksLikeMarkdownTable($lines)) {
            return $this->parseMarkdownTable($lines);
        }

        return $this->parsePlainLines($lines);
    }

    /**
     * @return list<array{quantity: int, product: string, partNumber: string, brand: string, description: string, unit: string}>
     */
    public function fromPlainText(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        return $this->parsePlainLines($lines);
    }

    /**
     * @return array<string, int>
     */
    public function detectColumnMap(string $markdown): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];

        return $this->resolveColumnMap($lines);
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, int>
     */
    private function resolveColumnMap(array $lines): array
    {
        $fromHeader = $this->detectColumnMapFromHeaders($lines);
        if ($fromHeader !== []) {
            return $fromHeader;
        }

        return $this->inferExcelColumnMap($lines);
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, int>
     */
    private function detectColumnMapFromHeaders(array $lines): array
    {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '|') || self::isTableSeparator($line)) {
                continue;
            }

            $cells = self::splitTableCells($line);
            if ($cells !== [] && self::isHeaderRow($cells)) {
                return self::mapColumns($cells);
            }
        }

        return [];
    }

    /**
     * Docling a veces omite la fila de encabezados (p. ej. fila 1 vacía en Excel).
     *
     * @param  list<string>  $lines
     * @return array<string, int>
     */
    private function inferExcelColumnMap(array $lines): array
    {
        $dataRows = 0;
        $quantityLike = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '|') || self::isTableSeparator($line)) {
                continue;
            }

            $cells = self::splitTableCells($line);
            if ($cells === [] || self::isHeaderRow($cells) || self::isEmptyDataRow($cells)) {
                continue;
            }

            $dataRows++;
            $first = trim($cells[0] ?? '');
            if ($first === '' || is_numeric($first)) {
                $quantityLike++;
            }
        }

        if ($dataRows === 0) {
            return [];
        }

        if ($quantityLike / $dataRows < 0.5) {
            return [];
        }

        $map = [
            'quantity' => 0,
            'product' => 1,
            'partNumber' => 2,
            'brand' => 3,
        ];

        // Only map UNIDAD when a 5th data column is present in inferred layouts.
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '|') || self::isTableSeparator($line)) {
                continue;
            }
            $cells = self::splitTableCells($line);
            if (count($cells) >= 5) {
                $map['unit'] = 4;
                break;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $columnMap
     * @return list<string>
     */
    public function missingRequiredColumns(array $columnMap, string $source): array
    {
        if ($source !== 'excel') {
            return [];
        }

        $missing = [];
        foreach (self::EXCEL_REQUIRED_COLUMNS as $key) {
            if (! isset($columnMap[$key])) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public static function looksLikePartNumberOnly(string $text): bool
    {
        $text = trim($text);

        return $text !== ''
            && ! str_contains($text, ' ')
            && self::looksLikePartNumber($text);
    }

    /**
     * @param  list<string>  $lines
     */
    private function looksLikeMarkdownTable(array $lines): bool
    {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && str_starts_with($line, '|')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{quantity: int, product: string, partNumber: string, brand: string, description: string, unit: string}>
     */
    private function parseMarkdownTable(array $lines): array
    {
        $out = [];
        $columnMap = $this->resolveColumnMap($lines);
        $lineIndex = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '|')) {
                continue;
            }

            if (self::isTableSeparator($line)) {
                continue;
            }

            $cells = self::splitTableCells($line);
            if ($cells === [] || self::isEmptyDataRow($cells)) {
                continue;
            }

            if (self::isHeaderRow($cells)) {
                $columnMap = self::mapColumns($cells);

                continue;
            }

            $strictExcel = $this->missingRequiredColumns($columnMap, 'excel') === [];
            $parsed = self::rowFromCells($cells, $columnMap, $lineIndex, $strictExcel);
            if ($parsed !== null) {
                $out[] = $parsed;
                $lineIndex++;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function splitTableCells(string $line): array
    {
        $inner = trim($line, " \t|");

        return array_map('trim', explode('|', $inner));
    }

    private static function isTableSeparator(string $line): bool
    {
        if (preg_match('~^\|[\s\-:|]+\|$~', $line)) {
            return true;
        }

        foreach (self::splitTableCells($line) as $cell) {
            if (! preg_match('~^:?-{2,}:?$~', $cell)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $cells
     */
    private static function isHeaderRow(array $cells): bool
    {
        $joined = strtolower(implode(' ', $cells));

        return str_contains($joined, 'cantidad')
            || str_contains($joined, 'producto')
            || str_contains($joined, 'descripcion')
            || str_contains($joined, 'descripción')
            || str_contains($joined, 'qty')
            || str_contains($joined, 'quantity');
    }

    /**
     * @param  list<string>  $cells
     */
    private static function isEmptyDataRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function normalizeUnit(string $unit): string
    {
        $unit = trim($unit);
        if ($unit === '') {
            return 'pza';
        }

        $lower = strtolower($unit);
        if (in_array($lower, ['pieza', 'piezas', 'pza', 'pzas', 'und', 'unidad', 'und.', 'ea', 'each'], true)) {
            return 'pza';
        }

        return $unit;
    }

    private static function cleanPartNumber(string $partNumber): string
    {
        $partNumber = trim($partNumber);

        if (preg_match('/^\((.+)\)$/', $partNumber, $m)) {
            return trim($m[1]);
        }

        return $partNumber;
    }

    private static function extractPartNumberFromProduct(string $product): string
    {
        if (preg_match('/\(([^)]+)\)/', $product, $paren)) {
            $inner = trim($paren[1]);
            if (strlen($inner) >= 3) {
                return $inner;
            }
        }

        if (preg_match('/\b(\d{4})\b/', $product, $model)) {
            return $model[1];
        }

        $sku = self::guessSkuFromText($product);
        if ($sku !== null) {
            return $sku;
        }

        return '';
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    private static function mapColumns(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $h = strtolower($header);

            if (preg_match('~cantidad|qty|quantity|cant~u', $h)) {
                $map['quantity'] = $index;
            } elseif (preg_match('~numero\s*articulos?|n[uú]m\.?\s*articulos?|consecutivo~u', $h)) {
                $map['partNumber'] = $index;
            } elseif (preg_match('~descripci[oó]n~u', $h)) {
                $map['description'] = $index;
            } elseif (preg_match('~producto|item~u', $h) || (preg_match('~articulo|artículo~u', $h) && ! str_contains($h, 'numero'))) {
                $map['product'] = $index;
            } elseif (preg_match('~no\.?\s*parte|n[uú]m\.?\s*parte|\bsku\b|modelo|part.?number~u', $h) && ! str_contains($h, 'partida')) {
                $map['partNumber'] = $index;
            } elseif (preg_match('~marca|brand~u', $h)) {
                $map['brand'] = $index;
            } elseif (preg_match('~unidad|unit|uom~u', $h)) {
                $map['unit'] = $index;
            }
        }

        if (! isset($map['product']) && isset($map['description'])) {
            $map['product'] = $map['description'];
        }

        return $map;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $columnMap
     * @return array{quantity: int, product: string, partNumber: string, brand: string, description: string, unit: string}|null
     */
    private static function rowFromCells(array $cells, array $columnMap, int $lineIndex, bool $strictExcel = false): ?array
    {
        $hasMappedColumns = $columnMap !== [] && isset($columnMap['quantity'], $columnMap['product']);

        $qtyIdx = $hasMappedColumns ? $columnMap['quantity'] : 0;
        $prodIdx = $hasMappedColumns ? $columnMap['product'] : 1;
        $unitIdx = $columnMap['unit'] ?? null;

        $quantityRaw = trim($cells[$qtyIdx] ?? '');
        $product = trim($cells[$prodIdx] ?? '');
        $partNumber = isset($columnMap['partNumber'])
            ? self::cleanPartNumber(trim($cells[$columnMap['partNumber']] ?? ''))
            : '';
        $brand = isset($columnMap['brand']) ? trim($cells[$columnMap['brand']] ?? '') : '';
        $description = isset($columnMap['description'])
            ? trim($cells[$columnMap['description']] ?? '')
            : '';
        $unit = $unitIdx !== null ? trim($cells[$unitIdx] ?? '') : '';

        if (! $hasMappedColumns && $product === '' && count($cells) >= 2) {
            $product = trim($cells[1] ?? '');
        }

        // Excel con CANTIDAD vacía: Docling suele poner la descripción en la columna cantidad
        // y el no. de parte en la columna producto.
        if ($hasMappedColumns && $quantityRaw !== '' && ! is_numeric($quantityRaw) && strlen($quantityRaw) > 12) {
            $prodCell = trim($cells[$prodIdx] ?? '');
            if ($prodCell === '' || self::looksLikePartNumber($prodCell)) {
                $product = $quantityRaw;
                if ($prodCell !== '') {
                    $partNumber = $partNumber !== '' ? $partNumber : $prodCell;
                }
                $quantityRaw = '';
            }
        }

        $product = self::resolveProductDescription($cells, $columnMap, $product, $partNumber);

        if ($product === '' || self::isHeaderRow($cells) || self::isTableSummaryRow($product, $quantityRaw)) {
            return null;
        }

        $quantity = is_numeric($quantityRaw) ? max(1, (int) $quantityRaw) : 1;

        if ($partNumber === '') {
            $partNumber = self::extractPartNumberFromProduct($product);
            if ($partNumber === '' && $strictExcel && isset($columnMap['partNumber'])) {
                return null;
            }
            if ($partNumber === '') {
                $partNumber = self::guessPartNumber($product, $lineIndex);
            }
        }

        if ($strictExcel && preg_match('~^LINE-\d+$~i', $partNumber)) {
            return null;
        }

        if (self::looksLikePartNumberOnly($product) || $product === $partNumber) {
            return null;
        }

        if ($brand === '') {
            $brand = self::guessBrand($product, $partNumber);
        }

        $unit = self::normalizeUnit($unit);

        if ($description === '' && isset($columnMap['description'], $columnMap['product'])
            && $columnMap['description'] === $columnMap['product']) {
            $description = $product;
        }

        return self::normalizeLine([
            'quantity' => $quantity,
            'product' => $product,
            'partNumber' => $partNumber,
            'brand' => $brand,
            'description' => $description !== '' ? $description : $product,
            'unit' => $unit,
        ]);
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{quantity: int, product: string, partNumber: string, brand: string, description: string, unit: string}>
     */
    private function parsePlainLines(array $lines): array
    {
        $out = [];

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || self::isNoiseLine($line)) {
                continue;
            }

            foreach (self::expandMergedQuantityLine($line) as $segmentIndex => $segment) {
                $quantity = 1;
                $rest = $segment;

                if (preg_match('/^(\d+)\s+(.+)$/u', $segment, $m)) {
                    $quantity = max(1, (int) $m[1]);
                    $rest = trim($m[2]);
                } elseif (preg_match('/^(.+?)\s+(\d+)\s*$/u', $segment, $m)) {
                    $quantity = max(1, (int) $m[2]);
                    $rest = trim($m[1]);
                }

                $parsed = self::parseProductDetails($rest, $index + $segmentIndex);

                $out[] = self::normalizeLine([
                    'quantity' => $quantity,
                    'product' => $parsed['product'],
                    'partNumber' => $parsed['partNumber'],
                    'brand' => $parsed['brand'],
                    'description' => $parsed['product'],
                    'unit' => 'pza',
                ]);
            }
        }

        return $out;
    }

    /**
     * Docling a veces concatena varias filas PDF en una sola línea de markdown.
     *
     * @return list<string>
     */
    private static function expandMergedQuantityLine(string $line): array
    {
        if (! preg_match_all('/\b(\d+)\s+(\S+)/u', $line, $matches) || count($matches[0]) < 2) {
            return [$line];
        }

        $specWords = [
            'pulgadas', 'pulg', 'metros', 'metro', 'gb', 'tb', 'mb', 'mhz', 'ghz',
            'watts', 'watt', 'pzas', 'pza', 'años', 'anos', 'meses', 'dias', 'días',
        ];

        $lineStarts = 0;
        foreach ($matches[2] as $word) {
            if (! in_array(strtolower($word), $specWords, true)) {
                $lineStarts++;
            }
        }

        if ($lineStarts < 2) {
            return [$line];
        }

        $parts = preg_split('/\s+(?=\d+\s+\S)/u', $line) ?: [$line];

        return array_values(array_filter(array_map('trim', $parts)));
    }

    /**
     * @param  array{quantity: int, product: string, partNumber: string, brand: string, description?: string, unit?: string}  $line
     * @return array{quantity: int, product: string, partNumber: string, brand: string, description: string, unit: string}
     */
    public static function normalizeLine(array $line): array
    {
        $product = trim((string) ($line['product'] ?? ''));

        $unit = self::normalizeUnit(trim((string) ($line['unit'] ?? 'pza')));

        return [
            'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
            'product' => $product,
            'partNumber' => trim((string) ($line['partNumber'] ?? '')),
            'brand' => trim((string) ($line['brand'] ?? 'Genérico')) ?: 'Genérico',
            'description' => trim((string) ($line['description'] ?? $product)),
            'unit' => $unit !== '' ? $unit : 'pza',
        ];
    }

    private static function isTableSummaryRow(string $product, string $quantityRaw): bool
    {
        $lower = strtolower($product);

        if (preg_match('~^(subtotal|iva|total|techo presupuestal|adicional de dir)~u', $lower)) {
            return true;
        }

        if ($product === '0' || preg_match('~^\d{1,3}$~', $product)) {
            return true;
        }

        return $quantityRaw === '' && $product !== '' && preg_match('~presupuestal|pendiente~iu', $product);
    }

    private static function isNoiseLine(string $line): bool
    {
        if (preg_match('~^#{1,6}\s~', $line)) {
            return true;
        }

        if (preg_match('~^[-|]+$~', $line)) {
            return true;
        }

        $lower = strtolower($line);

        return (bool) preg_match(
            '~^(solicitud de cotizaci[oó]n|requerimiento|pedido|lista de productos)\b~u',
            $lower,
        );
    }

    /**
     * @return array{product: string, partNumber: string, brand: string}
     */
    private static function parseProductDetails(string $rest, int $lineIndex): array
    {
        if (preg_match('/^(.+?)\s+\(([^)]+)\)\s*[—–-]\s*(.+)$/u', $rest, $m)) {
            $product = trim($m[1]);
            $partNumber = trim($m[2]);
            $brand = trim($m[3]);

            return [
                'product' => $product,
                'partNumber' => $partNumber !== '' ? $partNumber : self::guessPartNumber($product, $lineIndex),
                'brand' => $brand !== '' ? $brand : self::guessBrand($product, $partNumber),
            ];
        }

        $partNumber = self::guessPartNumber($rest, $lineIndex);
        $brand = self::guessBrand($rest, $partNumber);
        $product = $rest;

        if (preg_match('~^(.+?)\s+([A-Z0-9][A-Z0-9\-+/]{2,})$~i', $rest, $m)) {
            $candidateSku = trim($m[2]);
            if (self::isLikelySkuToken($candidateSku)) {
                $product = trim($m[1]);
                $partNumber = $candidateSku;
                $brand = self::guessBrand($product, $partNumber);
            }
        } elseif (preg_match('~^(.+?)\s+([A-Za-z0-9][A-Za-z0-9\-+/]{2,})\s+([A-Za-zÁÉÍÓÚáéíóú][\wÁÉÍÓÚáéíóú+\-]{1,})$~u', $rest, $m)) {
            $candidateSku = trim($m[2]);
            if (self::isLikelySkuToken($candidateSku)) {
                $product = trim($m[1]);
                $partNumber = $candidateSku;
                $brand = trim($m[3]);
            }
        }

        return [
            'product' => $product,
            'partNumber' => $partNumber,
            'brand' => $brand,
        ];
    }

    private static function isLikelySkuToken(string $text): bool
    {
        if (! self::looksLikePartNumber($text)) {
            return false;
        }

        $lower = strtolower($text);
        $specWords = [
            'pulgadas', 'pulg', 'metros', 'metro', 'piezas', 'pieza', 'unidad', 'unidades',
            'watts', 'watt', 'meses', 'dias', 'días', 'anos', 'años',
        ];

        if (in_array($lower, $specWords, true)) {
            return false;
        }

        return preg_match('/\d/', $text) === 1
            || str_contains($text, '-')
            || str_contains($text, '/');
    }

    private static function looksLikePartNumber(string $text): bool
    {
        $text = trim($text);

        return $text !== ''
            && strlen($text) <= 40
            && (bool) preg_match('~^[A-Z0-9][A-Z0-9\-+/]{3,}$~i', $text);
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $columnMap
     */
    private static function resolveProductDescription(
        array $cells,
        array $columnMap,
        string $product,
        string $partNumber,
    ): string {
        $needsBetter = $product === ''
            || $product === $partNumber
            || ($partNumber !== '' && self::looksLikePartNumber($product) && ! str_contains($product, ' '));

        if (! $needsBetter) {
            return $product;
        }

        $skip = array_values(array_filter([
            $columnMap['quantity'] ?? null,
            $columnMap['partNumber'] ?? null,
            $columnMap['brand'] ?? null,
            $columnMap['unit'] ?? null,
        ], fn ($v) => $v !== null));

        $best = '';
        foreach ($cells as $index => $cell) {
            if (in_array($index, $skip, true)) {
                continue;
            }
            $cell = trim($cell);
            if ($cell === '' || $cell === $partNumber || is_numeric($cell)) {
                continue;
            }
            if (self::looksLikePartNumber($cell) && ! str_contains($cell, ' ')) {
                continue;
            }
            if (strlen($cell) > strlen($best)) {
                $best = $cell;
            }
        }

        return $best !== '' ? $best : $product;
    }

    private static function guessPartNumber(string $text, int $lineIndex): string
    {
        if (preg_match('#\(([A-Z0-9][A-Z0-9\-]{2,})\)\s*\.?\s*$#i', $text, $paren)) {
            return $paren[1];
        }

        $guessed = self::guessSkuFromText($text);
        if ($guessed !== null && self::isLikelySkuToken($guessed)) {
            return $guessed;
        }

        return 'LINE-'.($lineIndex + 1);
    }

    private static function guessSkuFromText(string $text): ?string
    {
        if (! preg_match_all('#\b([A-Z0-9][A-Z0-9\-+/]{2,})\b#i', $text, $matches)) {
            return null;
        }

        $categoryWords = [
            'monitor', 'teclado', 'switch', 'cable', 'router', 'impresora', 'toner', 'tóner',
            'memoria', 'mouse', 'ratón', 'raton', 'disco', 'laptop', 'servidor', 'fuente',
            'audifonos', 'audífonos', 'webcam', 'bocina', 'scanner', 'escaner', 'tablet',
            'proyector', 'ups', 'rack', 'patch', 'panel', 'toner', 'cartucho', 'tinta',
        ];

        $best = null;
        $bestScore = -1;

        foreach ($matches[1] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            $lower = strtolower($candidate);
            if (in_array($lower, $categoryWords, true) || ! self::isLikelySkuToken($candidate)) {
                continue;
            }

            $score = 0;
            if (preg_match('/\d/', $candidate)) {
                $score += 12;
            }
            if (str_contains($candidate, '-') || str_contains($candidate, '/')) {
                $score += 6;
            }
            if (strlen($candidate) >= 6) {
                $score += 3;
            }
            if (strlen($candidate) >= 4) {
                $score += 1;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    public function inferBrand(string $product, string $partNumber): string
    {
        return self::guessBrand($product, $partNumber);
    }

    private static function guessBrand(string $product, string $partNumber): string
    {
        $p = strtolower($product.' '.$partNumber);

        $known = [
            'balam rush' => 'Balam Rush',
            'logitech' => 'Logitech',
            'cisco' => 'Cisco',
            'kingston' => 'Kingston',
            'ubiquiti' => 'Ubiquiti',
            'dell' => 'Dell',
            'lenovo' => 'Lenovo',
            'gigabyte' => 'Gigabyte',
            'hp poly' => 'HP',
            'poly' => 'HP',
            'hp ' => 'HP',
        ];

        foreach ($known as $needle => $label) {
            if (str_contains($p, $needle)) {
                return $label;
            }
        }

        $fromCaps = self::extractCapsBrandFromProduct($product);
        if ($fromCaps !== null) {
            return $fromCaps;
        }

        return 'Genérico';
    }

    private static function extractCapsBrandFromProduct(string $product): ?string
    {
        $stopwords = [
            'PLUS', 'GOLD', 'SILVER', 'BRONZE', 'SERIES', 'LEGEND', 'BURST', 'WATT', 'WATTS',
            'FUENTE', 'PODER', 'TARJETA', 'MADRE', 'LAPTOP', 'AUDIFONOS', 'AUDÍFONOS',
            'MEMORIA', 'MONITOR', 'CABLE', 'SWITCH', 'ROUTER', 'ATX', 'DDR', 'SSD', 'HDD',
            'USB', 'GR', 'RGB', 'LED', 'WIFI', 'BLUETOOTH', 'ALAMBRICOS', 'ALÁMBRICOS',
        ];

        if (! preg_match_all('/\b([A-ZÁÉÍÓÚÑ]{2,}(?:\s+[A-ZÁÉÍÓÚÑ]{2,}){0,2})\b/u', $product, $matches)) {
            return null;
        }

        foreach ($matches[1] as $candidate) {
            $words = preg_split('/\s+/', trim($candidate)) ?: [];
            $filtered = array_values(array_filter($words, fn (string $w) => ! in_array($w, $stopwords, true)));
            if ($filtered === []) {
                continue;
            }
            $brand = implode(' ', $filtered);
            if (strlen($brand) >= 4 && ! preg_match('/^\d/', $brand)) {
                return mb_convert_case($brand, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return null;
    }
}
