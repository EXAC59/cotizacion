<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteRequest;
use App\Models\Wholesaler;
use App\Services\Quotes\QuoteLockService;
use App\Services\Quotes\QuotePersistenceService;
use App\Services\Quotes\QuoteProfitCalculator;
use App\Services\Wholesalers\WholesalerCatalogSync;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class CotizacionPersistenceTest extends AuthenticatedFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(WholesalerCatalogSync::class)->sync();
        Wholesaler::query()->update(['active' => true]);
    }

    #[Test]
    public function it_saves_lines_via_service(): void
    {
        $client = Client::query()->create([
            'company' => 'Direct SA',
            'rfc' => 'DIR010101ABC',
        ]);

        $payload = [
            'folio' => 'COT-DIRECT-001',
            'clientId' => $client->id,
            'status' => 'en_elaboracion',
            'globalMarginPercent' => 30,
            'taxPercent' => 16,
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'Test product',
                    'partNumber' => 'SKU-1',
                    'cost' => 100,
                    'marginPercent' => 30,
                ],
            ],
        ];

        $prepared = app(QuoteProfitCalculator::class)->recalcQuotePayload($payload);
        $this->assertCount(1, $prepared['lines'], 'recalcQuotePayload debe conservar líneas');

        app(QuotePersistenceService::class)->save($payload);

        $this->assertSame(1, QuoteLine::count('*'));
    }

    #[Test]
    public function it_persists_quote_with_line_offers(): void
    {
        $client = Client::query()->create([
            'company' => 'Acme SA',
            'rfc' => 'ACM010101ABC',
        ]);

        $wh = Wholesaler::query()->where('active', true)->firstOrFail();

        $response = $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-TEST-0001',
            'clientId' => $client->id,
            'status' => 'en_elaboracion',
            'validityDays' => 15,
            'globalMarginPercent' => 30,
            'taxPercent' => 16,
            'notes' => 'Test',
            'lines' => [
                [
                    'quantity' => 2,
                    'product' => 'Switch Cisco',
                    'partNumber' => 'C9200L-24T-4G-E',
                    'cost' => 1200,
                    'marginPercent' => 30,
                    'salePrice' => 1560,
                    'amount' => 3120,
                    'warehouse' => 'CDMX',
                    'selectedWholesalerId' => $wh->id,
                    'offers' => [
                        [
                            'wholesalerId' => $wh->id,
                            'cost' => 1200,
                            'stock' => 10,
                            'warehouse' => 'CDMX',
                            'leadDays' => 0,
                            'isSelected' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('lines.0.offers.0.isSelected', true);

        $folio = (string) $response->json('folio');
        $this->assertMatchesRegularExpression('/^COT-ADMIN-\d{4}$/', $folio);

        $this->assertDatabaseHas('quote_line_offers', [
            'wholesaler_id' => $wh->id,
            'is_selected' => true,
            'cost' => 1200,
        ]);
    }

    #[Test]
    public function it_separates_customer_observations_from_the_internal_log(): void
    {
        $client = Client::query()->create([
            'company' => 'Cliente Observaciones SA',
            'rfc' => 'OBS010101ABC',
        ]);

        $response = $this->postJson('/api/cotizaciones', [
            'clientId' => $client->id,
            'status' => 'en_elaboracion',
            'customerObservations' => 'Entrega estimada de 3 a 5 días.',
            'lines' => [[
                'quantity' => 1,
                'product' => 'Producto',
                'partNumber' => 'SKU-OBS',
                'cost' => 100,
                'marginPercent' => 30,
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('customerObservations', 'Entrega estimada de 3 a 5 días.')
            ->assertJsonCount(0, 'internalNotes');

        $quoteId = (string) $response->json('id');
        $note = $this->postJson("/api/cotizaciones/{$quoteId}/notas-internas", [
            'body' => 'Compras debe confirmar disponibilidad antes de enviar.',
        ]);

        $note->assertCreated()
            ->assertJsonPath('body', 'Compras debe confirmar disponibilidad antes de enviar.')
            ->assertJsonPath('userName', auth()->user()?->name);

        $this->getJson("/api/cotizaciones/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('customerObservations', 'Entrega estimada de 3 a 5 días.')
            ->assertJsonPath('internalNotes.0.body', 'Compras debe confirmar disponibilidad antes de enviar.');
    }

    #[Test]
    public function it_updates_same_quote_when_folio_repeats_with_non_uuid_id(): void
    {
        $client = Client::query()->create([
            'company' => 'Upsert SA',
            'rfc' => 'UPS010101ABC',
        ]);

        $payload = [
            'id' => 'q-local-temp-id',
            'folio' => 'COT-UPSERT-001',
            'clientId' => $client->id,
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'A',
                    'partNumber' => 'SKU-A',
                    'cost' => 100,
                    'marginPercent' => 30,
                ],
            ],
        ];

        $first = app(QuotePersistenceService::class)->save($payload);
        app(QuoteLockService::class)->acquire($first->fresh());
        $second = app(QuotePersistenceService::class)->save([
            ...$payload,
            'id' => 'q-another-temp',
            'folio' => $first->folio,
            'lines' => [
                [
                    'quantity' => 2,
                    'product' => 'B',
                    'partNumber' => 'SKU-B',
                    'cost' => 200,
                    'marginPercent' => 30,
                ],
            ],
        ]);

        $this->assertSame($first->folio, $second->folio);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Quote::count('*'));
        $this->assertSame(1, QuoteLine::count('*'));
        $this->assertDatabaseHas('quote_lines', ['part_number' => 'SKU-B']);
    }

    #[Test]
    public function it_requires_invoice_number_for_facturada_status(): void
    {
        $client = Client::query()->create([
            'company' => 'Factura SA',
            'rfc' => 'FAC010101ABC',
        ]);

        $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-FAC-001',
            'clientId' => $client->id,
            'status' => 'facturada',
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'Item',
                    'partNumber' => 'SKU-1',
                    'cost' => 100,
                    'marginPercent' => 30,
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['invoiceNumber']);
    }

    #[Test]
    public function it_filters_quotes_by_search_and_status(): void
    {
        $clientA = Client::query()->create([
            'company' => 'Alpha Systems',
            'rfc' => 'ALP010101ABC',
        ]);
        $clientB = Client::query()->create([
            'company' => 'Beta Corp',
            'rfc' => 'BET010101ABC',
        ]);

        $alphaQuote = $this->postJson('/api/cotizaciones', [
            'clientId' => $clientA->id,
            'status' => 'en_elaboracion',
            'lines' => [
                ['quantity' => 1, 'product' => 'A', 'partNumber' => 'A1', 'cost' => 10, 'marginPercent' => 30],
            ],
        ])->assertCreated();

        $betaQuote = $this->postJson('/api/cotizaciones', [
            'clientId' => $clientB->id,
            'status' => 'pendiente_envio',
            'lines' => [
                ['quantity' => 1, 'product' => 'B', 'partNumber' => 'B1', 'cost' => 10, 'marginPercent' => 30],
            ],
        ])->assertCreated();

        $demoQuote = Quote::query()->create([
            'folio' => 'COT-DEMO-0001',
            'client_id' => $clientB->id,
            'status' => 'en_elaboracion',
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
            'notes' => '',
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        $this->getJson('/api/cotizaciones?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folio', $alphaQuote->json('folio'));

        $this->getJson('/api/cotizaciones?status=pendiente_envio')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folio', $betaQuote->json('folio'));

        $this->getJson('/api/cotizaciones?search=demo-001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folio', $demoQuote->folio);
    }

    #[Test]
    public function it_auto_generates_unique_folio_on_create(): void
    {
        $client = Client::query()->create([
            'company' => 'Folio Auto SA',
            'rfc' => 'AUT010101ABC',
        ]);

        $response = $this->postJson('/api/cotizaciones', [
            'clientId' => $client->id,
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'Item',
                    'partNumber' => 'SKU-1',
                    'cost' => 10,
                    'marginPercent' => 30,
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertMatchesRegularExpression('/^COT-ADMIN-\d{4}$/', (string) $response->json('folio'));
    }

    #[Test]
    public function it_keeps_folio_on_update(): void
    {
        $client = Client::query()->create([
            'company' => 'Folio Keep SA',
            'rfc' => 'KEP010101ABC',
        ]);

        $created = app(QuotePersistenceService::class)->save([
            'clientId' => $client->id,
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'A',
                    'partNumber' => 'A1',
                    'cost' => 10,
                    'marginPercent' => 30,
                ],
            ],
        ]);

        app(QuoteLockService::class)->acquire($created->fresh());

        $updated = app(QuotePersistenceService::class)->save([
            'id' => $created->id,
            'folio' => 'COT-MANUAL-HACK',
            'clientId' => $client->id,
            'notes' => 'Actualizada',
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'B',
                    'partNumber' => 'B1',
                    'cost' => 20,
                    'marginPercent' => 30,
                ],
            ],
        ]);

        $this->assertSame($created->folio, $updated->folio);
        $this->assertNotSame('COT-MANUAL-HACK', $updated->folio);
    }

    #[Test]
    public function it_allows_regressing_quote_status(): void
    {
        $client = Client::query()->create([
            'company' => 'Lock Test SA',
            'rfc' => 'LCK010101ABC',
        ]);

        $line = [
            'quantity' => 1,
            'product' => 'Item',
            'partNumber' => 'SKU-1',
            'cost' => 100,
            'marginPercent' => 30,
        ];

        $created = $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-LOCK-001',
            'clientId' => $client->id,
            'status' => 'pendiente_envio',
            'lines' => [$line],
        ])->assertCreated();

        $quoteId = $created->json('id');

        $this->postJson("/api/cotizaciones/{$quoteId}/bloqueo")->assertOk();

        $this->postJson('/api/cotizaciones', [
            'id' => $quoteId,
            'folio' => 'COT-LOCK-001',
            'clientId' => $client->id,
            'status' => 'enviada',
            'lines' => [$line],
        ])->assertCreated()
            ->assertJsonPath('status', 'enviada');

        $this->postJson("/api/cotizaciones/{$quoteId}/bloqueo")->assertOk();

        $this->postJson('/api/cotizaciones', [
            'id' => $quoteId,
            'folio' => 'COT-LOCK-001',
            'clientId' => $client->id,
            'status' => 'en_elaboracion',
            'lines' => [$line],
        ])->assertCreated()
            ->assertJsonPath('status', 'en_elaboracion');
    }

    #[Test]
    public function it_defaults_to_solicitud_cotizaciones_when_created_from_request(): void
    {
        $client = Client::query()->create([
            'company' => 'From Request SA',
            'rfc' => 'FRQ010101ABC',
        ]);

        $request = QuoteRequest::query()->create([
            'client_id' => $client->id,
            'source' => 'pdf',
            'status' => 'procesada',
            'file_name' => 'req.pdf',
        ]);

        $response = $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-FROM-REQ-001',
            'clientId' => $client->id,
            'requestId' => $request->id,
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'Item',
                    'partNumber' => 'SKU-1',
                    'cost' => 100,
                    'marginPercent' => 30,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'solicitud_cotizaciones');
    }

    #[Test]
    public function it_records_solicitud_then_elaboracion_when_saving_from_request_as_elaboracion(): void
    {
        $client = Client::query()->create([
            'company' => 'From Request Elab SA',
            'rfc' => 'FRE010101ABC',
        ]);

        $request = QuoteRequest::query()->create([
            'client_id' => $client->id,
            'source' => 'pdf',
            'status' => 'procesada',
            'file_name' => 'req2.pdf',
        ]);

        $response = $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-FROM-REQ-002',
            'clientId' => $client->id,
            'requestId' => $request->id,
            'status' => 'en_elaboracion',
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'Item',
                    'partNumber' => 'SKU-1',
                    'cost' => 100,
                    'marginPercent' => 30,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'en_elaboracion');

        $history = $response->json('statusHistory') ?? [];
        $tos = array_column($history, 'toStatus');
        $this->assertContains('solicitud_cotizaciones', $tos);
        $this->assertContains('en_elaboracion', $tos);
    }
}
