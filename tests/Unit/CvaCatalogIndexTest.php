<?php

namespace Tests\Unit;

use App\Services\Wholesalers\CvaCatalogIndex;
use Tests\TestCase;

class CvaCatalogIndexTest extends TestCase
{
    public function test_search_by_clave_and_codigo(): void
    {
        $index = new CvaCatalogIndex;
        $index->replaceCatalog(
            [
                'AC13886' => 'AC-13886',
                '99T54AA' => 'AC-13886',
            ],
            [
                'AC-13886' => [
                    'nombre' => 'CARTUCHO HP 99T54AA',
                    'descripcion' => 'CARTUCHO HP 99T54AA NEGRO',
                    'marca' => 'HP',
                    'codigo' => '99T54AA',
                    'precio' => 691.92,
                    'stock' => 2,
                    'warehouse' => 'CEDIS Guadalajara',
                ],
            ],
        );

        $this->assertSame('AC-13886', $index->resolveClave('99T54AA'));
        $this->assertSame(['AC-13886'], $index->candidateClaves('AC-13886'));

        $hits = $index->search('99T54');
        $this->assertNotEmpty($hits);
        $this->assertSame('AC-13886', $hits[0]['clave']);

        $byDesc = $index->search('cartucho hp');
        $this->assertNotEmpty($byDesc);
        $this->assertSame('AC-13886', $byDesc[0]['clave']);
    }

    public function test_unresolved_partial_sku_is_not_used_by_comparator(): void
    {
        $index = new class extends CvaCatalogIndex
        {
            public function resolveClave(string $partNumber): ?string
            {
                return null;
            }

            public function search(string $query, int $limit = 15): array
            {
                return [[
                    'clave' => 'WRONG-1',
                    'partNumber' => 'ABC1234',
                    'nombre' => 'Producto parecido',
                    'descripcion' => '',
                    'marca' => '',
                ]];
            }
        };

        $this->assertSame([], $index->candidateClaves('ABC123'));
    }

    public function test_exact_sku_is_not_rejected_by_different_product_wording(): void
    {
        $index = new class extends CvaCatalogIndex
        {
            public function resolveClave(string $partNumber): ?string
            {
                return 'CN-1616';
            }

            public function search(string $query, int $limit = 15): array
            {
                return [[
                    'clave' => 'CN-1616',
                    'partNumber' => 'T664320',
                    'nombre' => 'Botella EPSON T664320',
                    'descripcion' => 'Consumible magenta',
                    'marca' => 'EPSON',
                ]];
            }
        };

        $hits = $index->searchMatching('T664320', 'Tinta Epson', 10);

        $this->assertCount(1, $hits);
        $this->assertSame('CN-1616', $hits[0]['clave']);
    }

    public function test_exact_sku_matching_ignores_case_and_separators(): void
    {
        $index = new CvaCatalogIndex;
        $index->replaceCatalog(
            [
                'CN1616' => 'CN-1616',
                'T664320' => 'CN-1616',
            ],
            [
                'CN-1616' => [
                    'nombre' => 'Botella EPSON T664320',
                    'descripcion' => 'Consumible magenta',
                    'marca' => 'EPSON',
                    'codigo' => 'T664320',
                    'precio' => 160.73,
                    'stock' => 946,
                    'warehouse' => 'CVA',
                ],
            ],
        );

        $hits = $index->searchMatching('t664320-al', 'redacción diferente', 10);

        $this->assertSame(['CN-1616'], $index->candidateClaves('t664320-al'));
        $this->assertCount(1, $hits);
        $this->assertSame('T664320', $hits[0]['partNumber']);
    }
}
