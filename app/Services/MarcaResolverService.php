<?php

namespace App\Services;

class MarcaResolverService
{
    public function __construct(
        private readonly LecturaLineParser $parser,
    ) {}

    /**
     * @param  list<array{quantity?: int|float, product: string, partNumber?: string, brand?: string, description?: string, unit?: string}>  $lineas
     * @return list<array{quantity: int|float, product: string, partNumber: string, brand: string, description: string, unit: string, brand_resolved: bool}>
     */
    public function resolveLines(array $lineas): array
    {
        return array_map(function (array $line): array {
            $normalized = LecturaLineParser::normalizeLine($line);
            $current = $normalized['brand'];

            if ($current !== 'Genérico') {
                return [...$normalized, 'brand_resolved' => false];
            }

            $resolved = $this->resolveBrand(
                $normalized['product'],
                $normalized['partNumber'],
            );

            return [
                ...$normalized,
                'brand' => $resolved,
                'brand_resolved' => $resolved !== 'Genérico',
            ];
        }, $lineas);
    }

    public function resolveBrand(string $product, string $partNumber): string
    {
        $heuristic = $this->parser->inferBrand($product, $partNumber);
        if ($heuristic !== 'Genérico') {
            return $heuristic;
        }

        $haystack = strtolower($product.' '.$partNumber);

        foreach (config('brands', []) as $needle => $label) {
            if (str_contains($haystack, strtolower((string) $needle))) {
                return (string) $label;
            }
        }

        return 'Genérico';
    }
}
