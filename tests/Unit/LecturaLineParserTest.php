<?php

namespace Tests\Unit;

use App\Services\LecturaLineParser;
use App\Services\SolicitudLineasValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LecturaLineParserTest extends TestCase
{
    private LecturaLineParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new LecturaLineParser;
    }

    #[Test]
    public function it_parses_markdown_table_with_descripcion_and_cantidad(): void
    {
        $markdown = <<<'MD'
| CANTIDAD | DESCRIPCION | MARCA | UNIDAD |
| --- | --- | --- | --- |
| 5 | Cable HDMI 2m | Belkin | pza |
| 2 | Monitor 24 pulgadas | Dell | pza |
MD;

        $lines = $this->parser->fromMarkdown($markdown);

        $this->assertCount(2, $lines);
        $this->assertSame(5, $lines[0]['quantity']);
        $this->assertSame('Cable HDMI 2m', $lines[0]['product']);
        $this->assertSame('Belkin', $lines[0]['brand']);
        $this->assertSame('pza', $lines[0]['unit']);
    }

    #[Test]
    public function it_parses_word_style_line_with_sku_and_brand(): void
    {
        $text = "Memoria RAM 16GB (KVR16LS11/8) — Kingston\n";

        $lines = $this->parser->fromPlainText($text);

        $this->assertCount(1, $lines);
        $this->assertSame('Memoria RAM 16GB', $lines[0]['product']);
        $this->assertSame('KVR16LS11/8', $lines[0]['partNumber']);
        $this->assertSame('Kingston', $lines[0]['brand']);
    }

    #[Test]
    public function it_splits_merged_pdf_lines_with_multiple_quantities(): void
    {
        $text = "3 Cable UTP Cat6 5 Switch 8 puertos 2 Router WiFi";

        $lines = $this->parser->fromPlainText($text);

        $this->assertGreaterThanOrEqual(2, count($lines));
        $this->assertSame(3, $lines[0]['quantity']);
        $this->assertStringContainsString('Cable', $lines[0]['product']);
    }

    #[Test]
    public function it_maps_laptop_description_when_quantity_cell_has_text_and_part_in_product_column(): void
    {
        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | MARCA | UNIDAD |
| --- | --- | --- | --- | --- |
| Laptop V14 G4, AMD Ryzen 5 7430U, 16 GB, 512 GB SSD, Lenovo Pantalla 14 pulgadas, W11P, 1 año de garantia | 82YX0042LM | | |
MD;

        $lines = $this->parser->fromMarkdown($markdown);

        $this->assertCount(1, $lines);
        $this->assertSame(1, $lines[0]['quantity']);
        $this->assertStringContainsString('Laptop V14 G4', $lines[0]['product']);
        $this->assertSame('82YX0042LM', $lines[0]['partNumber']);
        $this->assertSame('Lenovo', $lines[0]['brand']);
    }

    #[Test]
    public function it_parses_four_column_excel_template_without_unidad(): void
    {
        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | MARCA |
| --- | --- | --- | --- |
| 4 | TINTA HP 662 NEGRO | CZ103AL | HP |
| 78 | Televisor Hisense, 50 pulgadas | 50A65NV | HISENSE |
MD;

        $map = $this->parser->detectColumnMap($markdown);
        $lines = $this->parser->fromMarkdown($markdown);
        $validator = new SolicitudLineasValidator($this->parser);

        $this->assertArrayNotHasKey('unit', $map);
        $this->assertSame([], $this->parser->missingRequiredColumns($map, 'excel'));
        $this->assertCount(2, $lines);

        $valid = $validator->validate($markdown, 'excel', $lines);
        $this->assertCount(2, $valid);
        $this->assertSame('pza', $valid[0]['unit']);
        $this->assertSame('CZ103AL', $valid[0]['partNumber']);
    }

    #[Test]
    public function it_parses_five_column_excel_template(): void
    {
        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | MARCA | UNIDAD |
| --- | --- | --- | --- | --- |
| 3 | Teclado mecánico RGB | KB-100 | Logitech | pza |
MD;

        $map = $this->parser->detectColumnMap($markdown);
        $lines = $this->parser->fromMarkdown($markdown);

        $this->assertArrayHasKey('quantity', $map);
        $this->assertArrayHasKey('product', $map);
        $this->assertArrayHasKey('partNumber', $map);
        $this->assertArrayHasKey('brand', $map);
        $this->assertArrayHasKey('unit', $map);
        $this->assertCount(1, $lines);
        $this->assertSame('KB-100', $lines[0]['partNumber']);
    }

    #[Test]
    public function it_rejects_sku_only_product_in_strict_excel(): void
    {
        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | MARCA | UNIDAD |
| --- | --- | --- | --- | --- |
| 1 | 82YX0042LM | 82YX0042LM | Lenovo | pza |
MD;

        $lines = $this->parser->fromMarkdown($markdown);

        $this->assertCount(0, $lines);
    }

    #[Test]
    public function validator_fails_when_marca_column_is_missing_for_excel(): void
    {
        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | UNIDAD |
| --- | --- | --- | --- |
| 1 | Monitor Dell 24 | MON-24 | pza |
MD;

        $validator = new SolicitudLineasValidator($this->parser);
        $lines = $this->parser->fromMarkdown($markdown);

        $this->expectException(\App\Exceptions\SolicitudFormatoException::class);
        $validator->validate($markdown, 'excel', $lines);
    }

    #[Test]
    public function it_parses_excel_when_docling_omits_header_row(): void
    {
        $markdown = <<<'MD'
|    |  Laptop  V14 G4, AMD Ryzen 5 7430U, 16 GB, 512 GB SSD, Lenovo Pantalla 14 pulgadas, W11P, 1 año de garantia   | 82YX0042LM   |          |       |
|----|---------------------------------------------------------------------------------------------------------------|--------------|----------|-------|
|  5 | Audifonos Poly Blackwire 5220, Alambricos, USB-C con adaptador USB-C/A, conector 3.5 mm, Binaural, HP         |              |          |       |
|  6 | TARJETA MADRE GIGABYTE ATX A620M H, DDR5, AM5, 128 GB, PARA AMD                                               | (A620M H)    | GIGABYTE | PIEZA |
MD;

        $map = $this->parser->detectColumnMap($markdown);
        $lines = $this->parser->fromMarkdown($markdown);
        $validator = new SolicitudLineasValidator($this->parser);

        $this->assertArrayHasKey('quantity', $map);
        $this->assertCount(3, $lines);
        $this->assertSame('82YX0042LM', $lines[0]['partNumber']);
        $this->assertSame('5220', $lines[1]['partNumber']);
        $this->assertSame('A620M H', $lines[2]['partNumber']);

        $valid = $validator->validate($markdown, 'excel', $lines);
        $this->assertCount(3, $valid);
    }

    #[Test]
    public function it_ignores_subtotal_and_techo_presupuestal_rows(): void
    {
        $markdown = <<<'MD'
| CANTIDAD | DESCRIPCION |
| --- | --- |
| 1 | Tinta negra |
| | SUBTOTAL |
| | TECHO PRESUPUESTAL |
MD;

        $lines = $this->parser->fromMarkdown($markdown);

        $this->assertCount(1, $lines);
        $this->assertSame('Tinta negra', $lines[0]['product']);
    }

    #[Test]
    public function it_normalizes_pieza_unit_to_pza(): void
    {
        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | MARCA | UNIDAD |
| --- | --- | --- | --- | --- |
| 5 | Switch Cisco | C9200L-24T-4G-E | Cisco | Pieza |
MD;

        $lines = $this->parser->fromMarkdown($markdown);

        $this->assertCount(1, $lines);
        $this->assertSame('pza', $lines[0]['unit']);
    }

    #[Test]
    public function it_parses_six_column_template_with_separate_description(): void
    {
        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | MARCA | DESCRIPCION | UNIDAD |
| --- | --- | --- | --- | --- | --- |
| 5 | Switch Cisco | C9200L-24T-4G-E | Cisco | Switch 24p 4G NW Adv | Pieza |
MD;

        $lines = $this->parser->fromMarkdown($markdown);

        $this->assertCount(1, $lines);
        $this->assertSame('Switch Cisco', $lines[0]['product']);
        $this->assertSame('C9200L-24T-4G-E', $lines[0]['partNumber']);
        $this->assertSame('Switch 24p 4G NW Adv', $lines[0]['description']);
        $this->assertSame('pza', $lines[0]['unit']);
    }

    #[Test]
    public function it_parses_plain_text_with_category_brand_and_model(): void
    {
        $text = <<<'TXT'
2 Monitor Dell P2422H 24 pulgadas
3 Teclado Logitech K120
1 Switch Cisco C9200L-24T-4G-E
TXT;

        $lines = $this->parser->fromPlainText($text);

        $this->assertCount(3, $lines);
        $this->assertSame('P2422H', $lines[0]['partNumber']);
        $this->assertSame('Dell', $lines[0]['brand']);
        $this->assertSame('K120', $lines[1]['partNumber']);
        $this->assertSame('Logitech', $lines[1]['brand']);
        $this->assertSame('C9200L-24T-4G-E', $lines[2]['partNumber']);
        $this->assertSame('Cisco', $lines[2]['brand']);
    }

    #[Test]
    public function it_parses_plain_text_with_sku_at_end_of_line(): void
    {
        $lines = $this->parser->fromPlainText("5 Switch Cisco C9200L-24T-4G-E\n");

        $this->assertCount(1, $lines);
        $this->assertSame(5, $lines[0]['quantity']);
        $this->assertSame('Switch Cisco', $lines[0]['product']);
        $this->assertSame('C9200L-24T-4G-E', $lines[0]['partNumber']);
        $this->assertSame('Cisco', $lines[0]['brand']);
    }
}
