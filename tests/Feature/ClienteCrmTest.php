<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Wholesaler;
use App\Services\Clients\ClientImportService;
use App\Services\Wholesalers\WholesalerCatalogSync;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class ClienteCrmTest extends AuthenticatedFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(WholesalerCatalogSync::class)->sync();
        Wholesaler::query()->update(['active' => true]);
    }

    #[Test]
    public function it_returns_client_detail_with_quote_stats(): void
    {
        $client = Client::query()->create([
            'company' => 'Acme SA',
            'rfc' => 'ACM010101ABC',
            'email' => 'acme@test.com',
        ]);

        $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-CRM-0001',
            'clientId' => $client->id,
            'status' => 'en_elaboracion',
            'globalMarginPercent' => 30,
            'taxPercent' => 16,
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'Producto demo',
                    'partNumber' => 'SKU-1',
                    'cost' => 100,
                    'marginPercent' => 30,
                    'salePrice' => 130,
                    'amount' => 130,
                ],
            ],
        ])->assertCreated();

        $this->getJson("/api/clientes/{$client->id}")
            ->assertOk()
            ->assertJsonPath('stats.quotesCount', 1)
            ->assertJsonPath('company', 'Acme SA');
    }

    #[Test]
    public function it_lists_quotes_for_client(): void
    {
        $client = Client::query()->create([
            'company' => 'Beta Corp',
            'rfc' => 'BET010101ABC',
        ]);

        $other = Client::query()->create([
            'company' => 'Otro SA',
            'rfc' => 'OTR010101ABC',
        ]);

        $betaQuote = $this->postJson('/api/cotizaciones', [
            'clientId' => $client->id,
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'Item',
                    'cost' => 50,
                    'marginPercent' => 30,
                    'salePrice' => 65,
                    'amount' => 65,
                ],
            ],
        ])->assertCreated();

        $this->postJson('/api/cotizaciones', [
            'clientId' => $other->id,
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'Item',
                    'cost' => 50,
                    'marginPercent' => 30,
                    'salePrice' => 65,
                    'amount' => 65,
                ],
            ],
        ])->assertCreated();

        $response = $this->getJson("/api/clientes/{$client->id}/cotizaciones");

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.folio', $betaQuote->json('folio'));
        $response->assertJsonPath('data.0.createdByName', auth()->user()?->name);
    }

    #[Test]
    public function it_searches_clients_by_company_or_rfc(): void
    {
        Client::query()->create([
            'company' => 'Acme Tecnología',
            'rfc' => 'ACM010101ABC',
        ]);

        Client::query()->create([
            'company' => 'Beta Industrial',
            'rfc' => 'BET010101ABC',
        ]);

        $this->getJson('/api/clientes?q=acme')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company', 'Acme Tecnología');

        $this->getJson('/api/clientes?q=BET010101')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rfc', 'BET010101ABC');
    }

    #[Test]
    public function it_imports_clients_from_excel_with_upsert_by_rfc(): void
    {
        Client::query()->create([
            'company' => 'Acme Vieja',
            'rfc' => 'ACM010101ABC',
            'email' => 'viejo@acme.test',
        ]);

        $result = app(ClientImportService::class)->importRows([
            ['Empresa', 'RFC', 'Dirección', 'Contacto', 'Correo', 'WhatsApp', 'Condiciones de pago'],
            ['Acme Actualizada', 'ACM010101ABC', 'CDMX', 'Juan', 'nuevo@acme.test', '', '30 días'],
            ['Nueva Corp', 'NUE010101ABC', 'MTY', 'Ana', 'ana@nueva.test', '', 'Contado'],
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);

        $this->assertDatabaseHas('clients', [
            'company' => 'Acme Actualizada',
            'email' => 'nuevo@acme.test',
        ]);

        $this->assertDatabaseHas('clients', [
            'company' => 'Nueva Corp',
            'rfc' => 'NUE010101ABC',
        ]);
    }

    #[Test]
    public function it_reports_invalid_rfc_rows_without_stopping_import(): void
    {
        $result = app(ClientImportService::class)->importRows([
            ['Empresa', 'RFC', 'Dirección', 'Contacto', 'Correo', 'WhatsApp', 'Condiciones de pago'],
            ['Valida SA', 'VAL010101ABC', '', '', '', '', ''],
            ['Invalida SA', 'MALRFC', '', '', '', '', ''],
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertCount(1, $result['errors']);

        $this->assertDatabaseHas('clients', ['company' => 'Valida SA']);
        $this->assertDatabaseMissing('clients', ['company' => 'Invalida SA']);
    }

    #[Test]
    public function it_requires_file_on_import_endpoint(): void
    {
        $this->postJson('/api/clientes/import', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['archivo']);
    }

    #[Test]
    public function it_downloads_import_template(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('La extensión php-zip es necesaria para plantillas Excel.');
        }

        $this->get('/api/clientes/import/plantilla')
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );
    }

    #[Test]
    public function client_import_template_is_clean_and_ready_to_fill(): void
    {
        $spreadsheet = app(ClientImportService::class)->buildTemplateSpreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Clientes', $sheet->getTitle());
        $this->assertSame('Empresa', $sheet->getCell('A1')->getValue());
        $this->assertSame('Condiciones de pago', $sheet->getCell('G1')->getValue());
        $this->assertNull($sheet->getCell('A2')->getValue());
        $this->assertSame('A2', $sheet->getFreezePane());

        $result = app(ClientImportService::class)->importRows(
            $sheet->toArray(null, true, true, false),
        );

        $this->assertSame(0, $result['created']);
        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function it_blocks_delete_when_client_has_quotes(): void
    {
        $client = Client::query()->create([
            'company' => 'Con Cotizaciones',
            'rfc' => 'CON010101ABC',
        ]);

        $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-DEL-001',
            'clientId' => $client->id,
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'Item',
                    'cost' => 10,
                    'marginPercent' => 30,
                    'salePrice' => 13,
                    'amount' => 13,
                ],
            ],
        ])->assertCreated();

        $this->deleteJson("/api/clientes/{$client->id}")
            ->assertStatus(409)
            ->assertJsonFragment([
                'message' => 'No se puede eliminar el cliente porque tiene cotizaciones asociadas.',
                'code' => 'client_has_quotes',
            ])
            ->assertJsonPath('quotesCount', 1);
    }
}
