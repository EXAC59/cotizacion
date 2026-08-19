<?php

namespace Tests\Unit;

use App\Services\Wholesalers\CtCatalogIndex;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CtCatalogIndexTest extends TestCase
{
    #[Test]
    public function it_prefers_manufacturer_sku_over_ct_clave(): void
    {
        $index = new class extends CtCatalogIndex
        {
            public function index(): array
            {
                return [
                    'CAREPS3920' => 'CAREPS3920',
                    'T664320AL' => 'CAREPS3920',
                    '886112670999' => 'CAREPS3920', // UPC-like, debe ignorarse
                ];
            }

            public function aliases(): array
            {
                return [];
            }
        };

        $this->assertSame('T664320AL', $index->manufacturerSkuForClave('CAREPS3920'));
    }

    #[Test]
    public function it_maps_manufacturer_part_to_ct_clave(): void
    {
        $index = new CtCatalogIndex;

        $map = $index->buildIndex([
            [
                'clave' => 'ACPTPL210',
                'numParte' => 'CPE210',
                'modelo' => 'CPE210',
                'upc' => '845973071677',
                'nombre' => 'Access Point Exterior TP-LINK CPE210',
                'descripcion_corta' => 'ACCESS POINT DE EXTERIOR',
                'marca' => 'TP-LINK',
            ],
            [
                'clave' => 'ACPUBI640',
                'numParte' => 'U6+',
                'modelo' => 'U6+',
            ],
        ]);

        $this->assertSame('ACPTPL210', $map[$index->normalize('CPE210')]);
        $this->assertSame('ACPTPL210', $map[$index->normalize('cpe-210')]);
        $this->assertSame('ACPUBI640', $map[$index->normalize('U6+')]);
        $this->assertSame('ACPUBI640', $map[$index->normalize('u6+')]);

        $products = $index->buildProducts([
            [
                'clave' => 'ACPTPL210',
                'nombre' => 'Access Point Exterior TP-LINK CPE210',
                'descripcion_corta' => 'ACCESS POINT DE EXTERIOR',
                'marca' => 'TP-LINK',
            ],
        ]);
        $this->assertSame('Access Point Exterior TP-LINK CPE210', $products['ACPTPL210']['nombre']);
        $this->assertSame('Access Point Exterior TP-LINK CPE210', $products[$index->normalize('ACPTPL210')]['nombre']);
    }

    #[Test]
    public function it_resolves_partial_sku_suffix_when_unique(): void
    {
        $index = new class extends CtCatalogIndex
        {
            public function index(): array
            {
                return [
                    'ACPTPL210' => 'ACPTPL210',
                    'CPE210' => 'ACPTPL210',
                    'ACPUBI640' => 'ACPUBI640',
                    'U6' => 'ACPUBI640',
                ];
            }

            public function aliases(): array
            {
                return [];
            }
        };

        $this->assertSame('ACPTPL210', $index->resolveCtCode('PTPL210'));
        $this->assertSame('ACPTPL210', $index->resolveCtCode('ACPTPL210'));
        $this->assertNull($index->resolveCtCode('210')); // too short
    }

    #[Test]
    public function it_indexes_sku_tokens_from_product_name(): void
    {
        $index = new CtCatalogIndex;

        $map = $index->buildIndex([
            [
                'clave' => 'CARHPP4300',
                'numParte' => 'M0H50AL',
                'modelo' => 'M0H50AL',
                'nombre' => 'Cabezal HP M0H50AL',
            ],
        ]);

        $this->assertSame('CARHPP4300', $map[$index->normalize('M0H50AL')]);
    }

    #[Test]
    public function it_resolves_configured_part_alias_for_missing_ftp_model(): void
    {
        config(['ct_part_aliases' => ['CZ103AL' => 'CARHPP2110']]);

        $index = new class extends CtCatalogIndex
        {
            public function index(): array
            {
                return [];
            }
        };

        $this->assertSame('CARHPP2110', $index->resolveCtCode('CZ103AL'));
        $this->assertSame('CARHPP2110', $index->resolveCtCode('cz-103-al'));
    }

    #[Test]
    public function it_searches_by_partial_sku_and_description(): void
    {
        $index = new CtCatalogIndex;

        $rows = [
            [
                'clave' => 'ACPTPL210',
                'numParte' => 'CPE210',
                'modelo' => 'CPE210',
                'nombre' => 'Access Point Exterior TP-LINK CPE210',
                'descripcion_corta' => 'ACCESS POINT DE EXTERIOR',
                'marca' => 'TP-LINK',
            ],
            [
                'clave' => 'CARHPP2110',
                'numParte' => 'CZ103AL',
                'modelo' => 'CZ103AL',
                'nombre' => 'Tinta HP 662 Negra CZ103AL',
                'descripcion_corta' => 'CARTUCHO TINTA HP 662 NEGRO',
                'marca' => 'HP',
            ],
        ];

        $codes = $index->buildIndex($rows);
        $products = $index->buildProducts($rows);

        $bySku = $index->searchInCatalog(
            $codes,
            $products,
            $index->normalize('CZ103'),
            $index->fold('CZ103'),
            10,
        );
        $this->assertNotEmpty($bySku);
        $this->assertSame('CARHPP2110', $bySku[0]['clave']);

        $byDesc = $index->searchInCatalog(
            $codes,
            $products,
            $index->normalize('tinta hp'),
            $index->fold('tinta hp'),
            10,
        );
        $this->assertNotEmpty($byDesc);
        $claves = array_column($byDesc, 'clave');
        $this->assertContains('CARHPP2110', $claves);

        $byTokens = $index->searchInCatalog(
            $codes,
            $products,
            '',
            $index->fold('cartucho tinta'),
            10,
        );
        $this->assertContains('CARHPP2110', array_column($byTokens, 'clave'));

        $byName = $index->searchInCatalog(
            $codes,
            $products,
            $index->normalize('access point'),
            $index->fold('access point'),
            10,
        );
        $this->assertNotEmpty($byName);
        $this->assertSame('ACPTPL210', $byName[0]['clave']);
    }

    #[Test]
    public function it_returns_resolved_clave_as_single_candidate(): void
    {
        $index = new class extends CtCatalogIndex
        {
            public function resolveCtCode(string $partNumber): ?string
            {
                return 'ACPTPL210';
            }

            public function search(string $query, int $limit = 15): array
            {
                return [
                    [
                        'clave' => 'OTHER',
                        'partNumber' => null,
                        'nombre' => 'Other',
                        'descripcion' => '',
                        'marca' => '',
                    ],
                ];
            }
        };

        $this->assertSame(['ACPTPL210'], $index->candidateClaves('anything'));
    }

    #[Test]
    public function it_returns_unique_search_claves_when_unresolved(): void
    {
        $index = new class extends CtCatalogIndex
        {
            public function resolveCtCode(string $partNumber): ?string
            {
                return null;
            }

            public function search(string $query, int $limit = 15): array
            {
                return [
                    [
                        'clave' => 'ACCDAT1410',
                        'partNumber' => 'AEX500U3CRD',
                        'nombre' => 'Rojo',
                        'descripcion' => '',
                        'marca' => '',
                    ],
                    [
                        'clave' => 'ACCDAT1480',
                        'partNumber' => 'AEX500U3CBK',
                        'nombre' => 'Negro',
                        'descripcion' => '',
                        'marca' => '',
                    ],
                    [
                        'clave' => 'ACCDAT1410',
                        'partNumber' => null,
                        'nombre' => 'Rojo dup',
                        'descripcion' => '',
                        'marca' => '',
                    ],
                    [
                        'clave' => 'ACCDAT1500',
                        'partNumber' => 'AEX500U3XXX',
                        'nombre' => 'Otro',
                        'descripcion' => '',
                        'marca' => '',
                    ],
                ];
            }
        };

        $this->assertSame(
            ['ACCDAT1410', 'ACCDAT1480', 'ACCDAT1500'],
            $index->candidateClaves('aex500u3'),
        );
    }

    #[Test]
    public function it_returns_single_search_clave_when_unresolved_but_unique(): void
    {
        $index = new class extends CtCatalogIndex
        {
            public function resolveCtCode(string $partNumber): ?string
            {
                return null;
            }

            public function search(string $query, int $limit = 15): array
            {
                return [
                    [
                        'clave' => 'ACCDAT1410',
                        'partNumber' => 'AEX500U3CRD',
                        'nombre' => 'Rojo',
                        'descripcion' => '',
                        'marca' => '',
                    ],
                    [
                        'clave' => 'ACCDAT1410',
                        'partNumber' => null,
                        'nombre' => 'Rojo dup',
                        'descripcion' => '',
                        'marca' => '',
                    ],
                ];
            }
        };

        $this->assertSame(['ACCDAT1410'], $index->candidateClaves('aex500u3'));
    }

    #[Test]
    public function it_resolves_manufacturer_sku_to_unique_regional_variant(): void
    {
        $index = new class extends CtCatalogIndex
        {
            public function index(): array
            {
                return [
                    'CART664320' => 'CART664320',
                    'T664320AL' => 'CART664320',
                ];
            }

            public function aliases(): array
            {
                return [];
            }
        };

        $this->assertSame('CART664320', $index->resolveCtCode('t664320'));
        $this->assertSame('CART664320', $index->resolveCtCode('T664320'));
        $this->assertSame('CART664320', $index->resolveCtCode('t664320-al'));
        $this->assertSame(['CART664320'], $index->candidateClaves('t664320'));
    }

    #[Test]
    public function it_resolves_longer_query_to_shorter_catalog_ref(): void
    {
        $index = new class extends CtCatalogIndex
        {
            public function index(): array
            {
                return [
                    'CART664320' => 'CART664320',
                    'T664320' => 'CART664320',
                ];
            }

            public function aliases(): array
            {
                return [];
            }
        };

        $this->assertSame('CART664320', $index->resolveCtCode('t664320-al'));
        $this->assertSame('CART664320', $index->resolveCtCode('T664320AL'));
    }

    #[Test]
    public function it_rejects_ambiguous_regional_variants(): void
    {
        $index = new class extends CtCatalogIndex
        {
            public function index(): array
            {
                return [
                    'CLAVE_AL' => 'CLAVE_AL',
                    'T664320AL' => 'CLAVE_AL',
                    'CLAVE_MX' => 'CLAVE_MX',
                    'T664320MX' => 'CLAVE_MX',
                ];
            }

            public function aliases(): array
            {
                return [];
            }

            public function search(string $query, int $limit = 15): array
            {
                return [
                    [
                        'clave' => 'CLAVE_AL',
                        'partNumber' => 'T664320AL',
                        'nombre' => 'Variante AL',
                        'descripcion' => '',
                        'marca' => '',
                    ],
                    [
                        'clave' => 'CLAVE_MX',
                        'partNumber' => 'T664320MX',
                        'nombre' => 'Variante MX',
                        'descripcion' => '',
                        'marca' => '',
                    ],
                ];
            }
        };

        $this->assertNull($index->resolveCtCode('t664320'));
        $this->assertSame(['CLAVE_AL', 'CLAVE_MX'], $index->candidateClaves('t664320'));
    }

    #[Test]
    public function it_scores_query_starting_with_shorter_catalog_key(): void
    {
        $index = new CtCatalogIndex;

        $rows = [
            [
                'clave' => 'CART664320',
                'numParte' => 'T664320',
                'modelo' => 'T664320',
                'nombre' => 'Toner demo T664320',
                'descripcion_corta' => 'TONER',
                'marca' => 'DEMO',
            ],
        ];

        $codes = $index->buildIndex($rows);
        $products = $index->buildProducts($rows);

        $hits = $index->searchInCatalog(
            $codes,
            $products,
            $index->normalize('t664320-al'),
            $index->fold('t664320-al'),
            10,
        );

        $this->assertNotEmpty($hits);
        $this->assertSame('CART664320', $hits[0]['clave']);
    }

    #[Test]
    public function it_intersects_sku_and_description_matches_equally(): void
    {
        $index = new class extends CtCatalogIndex
        {
            public function search(string $query, int $limit = 15): array
            {
                $q = strtoupper(trim($query));

                if (str_contains($q, 'CARBRT3') || $q === 'CARBRT3') {
                    return [
                        [
                            'clave' => 'CARBRT340',
                            'partNumber' => 'TN15',
                            'nombre' => 'Tóner BROTHER TN15',
                            'descripcion' => 'Tóner BROTHER TN15',
                            'marca' => 'BROTHER',
                        ],
                        [
                            'clave' => 'CARBRT2300',
                            'partNumber' => 'TN630',
                            'nombre' => 'Tóner BROTHER TN630',
                            'descripcion' => 'Tóner BROTHER TN630',
                            'marca' => 'BROTHER',
                        ],
                    ];
                }

                if (str_contains(strtolower($query), 'tn630')) {
                    return [
                        [
                            'clave' => 'CARBRT2300',
                            'partNumber' => 'TN630',
                            'nombre' => 'Tóner BROTHER TN630',
                            'descripcion' => 'Tóner BROTHER TN630',
                            'marca' => 'BROTHER',
                        ],
                    ];
                }

                return [];
            }
        };

        $hits = $index->searchMatching('CARBRT3', 'Tóner BROTHER TN630', 10);
        $this->assertCount(1, $hits);
        $this->assertSame('CARBRT2300', $hits[0]['clave']);

        $onlySku = $index->searchMatching('CARBRT3', '', 10);
        $this->assertCount(2, $onlySku);

        $onlyDesc = $index->searchMatching('', 'TN630', 10);
        $this->assertCount(1, $onlyDesc);
        $this->assertSame('CARBRT2300', $onlyDesc[0]['clave']);
    }

    #[Test]
    public function it_filters_sku_hits_by_generic_description_without_top_n_intersection(): void
    {
        $index = new CtCatalogIndex;

        $rows = [
            [
                'clave' => 'CARHPP2110',
                'numParte' => 'CZ103AL',
                'modelo' => 'CZ103AL',
                'nombre' => 'Tinta',
                'descripcion_corta' => 'Tinta HP 662 - CZ103AL, Negro',
                'marca' => 'HP',
            ],
            [
                'clave' => 'CARHPD3270',
                'numParte' => 'CZ129A',
                'modelo' => 'CZ129A',
                'nombre' => 'Tinta',
                'descripcion_corta' => 'Tinta HP 711, CZ129A, Negro 38ml',
                'marca' => 'HP',
            ],
        ];

        $codes = $index->buildIndex($rows);
        $products = $index->buildProducts($rows);

        $index = new class($codes, $products) extends CtCatalogIndex
        {
            public function __construct(
                private array $codesMap,
                private array $productsMap,
            ) {}

            public function search(string $query, int $limit = 15): array
            {
                return $this->searchInCatalog(
                    $this->codesMap,
                    $this->productsMap,
                    $this->normalize($query),
                    $this->fold($query),
                    $limit,
                );
            }
        };

        $hits = $index->searchMatching('CZ103AL', 'Tinta', 10);
        $this->assertNotEmpty($hits);
        $this->assertSame('CARHPP2110', $hits[0]['clave']);
    }

    #[Test]
    public function exact_sku_is_not_rejected_by_different_product_wording(): void
    {
        $index = new class extends CtCatalogIndex
        {
            public function resolveCtCode(string $partNumber): ?string
            {
                return strtoupper($partNumber) === 'T664320' ? 'CART664320' : null;
            }

            public function search(string $query, int $limit = 15): array
            {
                return [[
                    'clave' => 'CART664320',
                    'partNumber' => 'T664320AL',
                    'nombre' => 'Cartucho EPSON T664320-AL',
                    'descripcion' => 'Consumible EPSON color magenta',
                    'marca' => 'EPSON',
                ]];
            }
        };

        $hits = $index->searchMatching('T664320', 'Tinta Epson', 10);

        $this->assertCount(1, $hits);
        $this->assertSame('CART664320', $hits[0]['clave']);
    }

    #[Test]
    public function it_parses_especiales_xml_and_indexes_no_parte(): void
    {
        $index = new CtCatalogIndex;

        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Articulo version="2.0">
  <Producto>
    <clave>CARHPP4350</clave>
    <no_parte>4S6X5PL</no_parte>
    <nombre>Cartucho</nombre>
    <modelo>938</modelo>
    <marca>HP</marca>
    <descripcion_corta>HP CARTUCHO 938 4S6X5PL CIAN</descripcion_corta>
    <upc>196548611119</upc>
  </Producto>
</Articulo>
XML;

        $rows = $index->parseCatalogXml($xml);
        $this->assertCount(1, $rows);
        $this->assertSame('CARHPP4350', $rows[0]['clave']);
        $this->assertSame('4S6X5PL', $rows[0]['numParte']);
        $this->assertSame('4S6X5PL', $rows[0]['no_parte']);

        $map = $index->buildIndex($rows);
        $this->assertSame('CARHPP4350', $map[$index->normalize('4S6X5PL')]);
        $this->assertSame('CARHPP4350', $map[$index->normalize('CARHPP4350')]);
    }
}
