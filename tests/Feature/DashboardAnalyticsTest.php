<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ComparisonJob;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteLineOffer;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestLine;
use App\Models\QuoteStatusEvent;
use App\Models\User;
use App\Models\Wholesaler;
use App\Services\Quotes\QuoteLockService;
use App\Services\Wholesalers\WholesalerCatalogSync;
use Carbon\Carbon;
use Database\Seeders\DashboardUsersSeeder;
use Database\Seeders\InventoryItemsSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class DashboardAnalyticsTest extends AuthenticatedFeatureTestCase
{
    private Client $client;

    private Wholesaler $wholesalerA;

    private Wholesaler $wholesalerB;

    protected function setUp(): void
    {
        parent::setUp();
        app(WholesalerCatalogSync::class)->sync();
        Wholesaler::query()->update(['active' => true]);
        $this->seed(DashboardUsersSeeder::class);
        $this->seed(InventoryItemsSeeder::class);

        $this->client = Client::query()->create([
            'company' => 'Analytics Corp',
            'rfc' => 'ANA010101ABC',
        ]);

        $wholesalers = Wholesaler::query()->where('active', true)->orderBy('name')->get();
        $this->wholesalerA = $wholesalers->first();
        $this->wholesalerB = $wholesalers->skip(1)->first() ?? $wholesalers->first();
    }

    #[Test]
    public function it_returns_dashboard_kpis_with_realized_and_potential_profit(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $this->createQuote('COT-WON-001', 'aceptada', [
            ['product' => 'Switch A', 'partNumber' => 'SKU-A', 'quantity' => 2, 'cost' => 100, 'salePrice' => 130],
        ]);

        $this->createQuote('COT-OPEN-001', 'en_elaboracion', [
            ['product' => 'Switch B', 'partNumber' => 'SKU-B', 'quantity' => 1, 'cost' => 200, 'salePrice' => 260],
        ]);

        $this->createQuote('COT-SENT-001', 'enviada', [
            ['product' => 'Switch C', 'partNumber' => 'SKU-C', 'quantity' => 3, 'cost' => 50, 'salePrice' => 65],
        ]);

        $response = $this->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('period.from', '2026-06-01')
            ->assertJsonPath('period.to', '2026-06-30')
            ->assertJsonPath('monthlyRealizedProfit', 60)
            ->assertJsonPath('monthlyPotentialProfit', 165)
            ->assertJsonPath('wonQuotes', 1)
            ->assertJsonPath('lostQuotes', 0)
            ->assertJsonPath('winRate', 100)
            ->assertJsonPath('quotesByStatus.aceptada', 1)
            ->assertJsonPath('quotesByStatus.en_elaboracion', 1)
            ->assertJsonPath('quotesByStatus.enviada', 1);

        Carbon::setTestNow();
    }

    #[Test]
    public function recent_quotes_include_who_created_them(): void
    {
        $ventas = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'ventas'))->firstOrFail();
        $quote = $this->createQuote('COT-BY-USER-001', 'en_elaboracion', [
            ['product' => 'Laptop', 'partNumber' => 'LP-1', 'quantity' => 1, 'cost' => 100, 'salePrice' => 150],
        ], $ventas);

        $this->actingAsDemoUser('administrador');

        $response = $this->getJson('/api/dashboard')->assertOk();
        $recent = collect($response->json('recentQuotes'));
        $row = $recent->firstWhere('id', $quote->id);
        $this->assertNotNull($row, 'La cotización creada debe aparecer en recentQuotes');
        $this->assertSame($ventas->name, $row['createdByName'] ?? null);
        $this->assertArrayHasKey('createdByName', $recent->first() ?? []);
    }

    #[Test]
    public function it_ranks_top_requested_and_quoted_products(): void
    {
        Carbon::setTestNow('2026-06-10 12:00:00');

        $request = QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'source' => 'texto',
            'status' => 'procesada',
        ]);

        QuoteRequestLine::query()->create([
            'request_id' => $request->id,
            'line_order' => 0,
            'quantity' => 10,
            'product' => 'Cisco Switch',
            'part_number' => 'C9200L-24T-4G-E',
        ]);

        QuoteRequestLine::query()->create([
            'request_id' => $request->id,
            'line_order' => 1,
            'quantity' => 3,
            'product' => 'Memoria Kingston',
            'part_number' => 'KVR16',
        ]);

        $this->createQuote('COT-TOP-001', 'enviada', [
            ['product' => 'Cisco Switch', 'partNumber' => 'C9200L-24T-4G-E', 'quantity' => 5, 'cost' => 100, 'salePrice' => 130],
            ['product' => 'Memoria Kingston', 'partNumber' => 'KVR16', 'quantity' => 2, 'cost' => 50, 'salePrice' => 65],
        ]);

        $response = $this->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('topRequestedProducts.0.partNumber', 'C9200L-24T-4G-E')
            ->assertJsonPath('topRequestedProducts.0.count', 10)
            ->assertJsonPath('topQuotedProducts.0.partNumber', 'C9200L-24T-4G-E')
            ->assertJsonPath('topQuotedProducts.0.count', 5);

        Carbon::setTestNow();
    }

    #[Test]
    public function it_filters_metrics_by_period(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $this->createQuote('COT-IN-001', 'aceptada', [
            ['product' => 'In period', 'partNumber' => 'IN', 'quantity' => 1, 'cost' => 100, 'salePrice' => 150],
        ]);

        $oldQuote = $this->createQuote('COT-OUT-001', 'aceptada', [
            ['product' => 'Out period', 'partNumber' => 'OUT', 'quantity' => 1, 'cost' => 100, 'salePrice' => 200],
        ]);
        Quote::query()->whereKey($oldQuote->id)->update(['created_at' => '2026-05-01 10:00:00']);

        $this->getJson('/api/dashboard?from=2026-06-01&to=2026-06-30')
            ->assertOk()
            ->assertJsonPath('monthlyRealizedProfit', 30)
            ->assertJsonPath('wonQuotes', 1);

        $this->getJson('/api/dashboard?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertJsonPath('monthlyRealizedProfit', 30)
            ->assertJsonPath('wonQuotes', 1);

        Carbon::setTestNow();
    }

    #[Test]
    public function it_returns_reportes_with_wholesalers_and_profit_trend(): void
    {
        Carbon::setTestNow('2026-06-20 09:00:00');

        $quote = $this->createQuote('COT-WH-001', 'facturada', [
            ['product' => 'AP Ubiquiti', 'partNumber' => 'U6+', 'quantity' => 1, 'cost' => 300, 'salePrice' => 390],
        ]);

        $line = QuoteLine::query()->where('quote_id', $quote->id)->firstOrFail();

        QuoteLineOffer::query()->create([
            'quote_line_id' => $line->id,
            'wholesaler_id' => $this->wholesalerA->id,
            'cost' => 300,
            'stock' => 5,
            'warehouse' => 'CDMX',
            'is_selected' => true,
        ]);

        QuoteLineOffer::query()->create([
            'quote_line_id' => $line->id,
            'wholesaler_id' => $this->wholesalerB->id,
            'cost' => 310,
            'stock' => 3,
            'warehouse' => 'GDL',
            'is_selected' => false,
        ]);

        $response = $this->getJson('/api/reportes');

        $response->assertOk()
            ->assertJsonPath('monthlyRealizedProfit', 90)
            ->assertJsonPath('wonQuotes', 1)
            ->assertJsonCount(6, 'profitTrend')
            ->assertJsonStructure([
                'topWholesalers' => [
                    ['name', 'count', 'percent'],
                ],
            ]);

        $wholesalers = collect($response->json('topWholesalers'));
        $this->assertTrue($wholesalers->sum('count') >= 2);

        Carbon::setTestNow();
    }

    #[Test]
    public function it_counts_pending_requests(): void
    {
        QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'source' => 'texto',
            'status' => 'pendiente',
        ]);

        QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'source' => 'texto',
            'status' => 'procesando',
        ]);

        QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'source' => 'texto',
            'status' => 'procesada',
        ]);

        QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'source' => 'texto',
            'status' => 'precios_listos',
        ]);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('pendingRequests', 2);
    }

    #[Test]
    public function it_returns_average_ticket_and_profit_by_salesperson(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $ventas = User::query()->where('email', 'maria@empresa.com')->firstOrFail();
        $compras = User::query()->where('email', 'compras@cotizacion.test')->firstOrFail();

        $this->createQuote('COT-AVG-001', 'aceptada', [
            ['product' => 'Switch A', 'partNumber' => 'SKU-A', 'quantity' => 1, 'cost' => 100, 'salePrice' => 200],
        ], $ventas);

        $this->createQuote('COT-AVG-002', 'facturada', [
            ['product' => 'Switch B', 'partNumber' => 'SKU-B', 'quantity' => 1, 'cost' => 50, 'salePrice' => 100],
        ], $compras);

        $this->actingAs($this->demoUser('administrador'));

        $dashboard = $this->getJson('/api/dashboard');
        $dashboard->assertOk()
            ->assertJsonStructure(['averageTicket'])
            ->assertJsonPath('averageTicket', fn ($value) => (float) $value > 0)
            ->assertJsonMissingPath('profitBySalesperson');

        $reportes = $this->getJson('/api/reportes');
        $reportes->assertOk()->assertJsonStructure(['averageTicket', 'profitBySalesperson']);

        $salespeople = collect($reportes->json('profitBySalesperson'));
        $this->assertTrue($salespeople->contains('name', 'María González'));
        $this->assertTrue($salespeople->contains('name', 'Luis Ramírez'));

        Carbon::setTestNow();
    }

    #[Test]
    public function it_returns_dashboard_alerts(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $staleDraft = $this->createQuote('COT-STALE-DRAFT', 'en_elaboracion', [
            ['product' => 'Stale', 'partNumber' => 'STALE-1', 'quantity' => 1, 'cost' => 10, 'salePrice' => 20],
        ]);
        Quote::query()->whereKey($staleDraft->id)->update([
            'updated_at' => '2026-06-01 10:00:00',
        ]);

        $expiring = $this->createQuote('COT-EXPIRE', 'en_elaboracion', [
            ['product' => 'Expire', 'partNumber' => 'EXP-1', 'quantity' => 1, 'cost' => 10, 'salePrice' => 20],
        ], null, 2);

        ComparisonJob::query()->create([
            'part_number' => 'FAIL-SKU',
            'quantity' => 1,
            'status' => ComparisonJob::STATUS_ERROR,
            'error_message' => 'Timeout al consultar mayorista',
        ]);

        $ct = Wholesaler::query()->where('code', 'CT')->firstOrFail();
        $lowStockQuote = $this->createQuote('COT-LOW-STOCK', 'en_elaboracion', [
            ['product' => 'Switch CT bajo', 'partNumber' => 'CT-LOW-1', 'quantity' => 1, 'cost' => 10, 'salePrice' => 20],
        ]);
        $lowLine = QuoteLine::query()->where('quote_id', $lowStockQuote->id)->firstOrFail();
        QuoteLineOffer::query()->create([
            'quote_line_id' => $lowLine->id,
            'wholesaler_id' => $ct->id,
            'cost' => 10,
            'stock' => 2,
            'warehouse' => 'Azcapotzalco (35A)',
            'is_selected' => true,
        ]);

        $response = $this->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'alerts' => [
                    'lowStock',
                    'pendingQuotes',
                    'unansweredQuotes',
                    'readyForSalesQuotes',
                    'integrationIssues',
                    'expiringQuotes',
                    'stuckProcessingRequests',
                ],
            ]);

        $lowStock = collect($response->json('alerts.lowStock'));
        $this->assertNotEmpty($lowStock);
        $ctAlert = $lowStock->first(
            fn (array $row) => ($row['partNumber'] ?? '') === 'CT-LOW-1'
                && ($row['wholesalerCode'] ?? '') === 'CT'
        );
        $this->assertNotNull($ctAlert);
        $this->assertSame(2, $ctAlert['stock']);
        $this->assertSame('Azcapotzalco (35A)', $ctAlert['warehouse']);
        $this->assertTrue(
            collect($response->json('alerts.unansweredQuotes'))->contains('folio', $staleDraft->folio)
        );
        $this->assertNotEmpty($response->json('alerts.integrationIssues'));
        $this->assertTrue(
            collect($response->json('alerts.expiringQuotes'))->contains('folio', $expiring->folio)
        );
        $this->assertSame(
            AppSetting::current()->resolvedUnansweredQuoteDays(),
            (int) $response->json('unansweredQuoteDays')
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function stale_quote_alert_uses_status_and_resets_when_the_quote_is_opened(): void
    {
        Carbon::setTestNow('2026-08-13 12:00:00');

        $draft = $this->createQuote('COT-STALE-001', 'en_elaboracion', [
            ['product' => 'Borrador detenido', 'partNumber' => 'STALE-1', 'quantity' => 1, 'cost' => 100, 'salePrice' => 130],
        ]);
        $ready = $this->createQuote('COT-STALE-002', 'pendiente_envio', [
            ['product' => 'Lista detenida', 'partNumber' => 'STALE-2', 'quantity' => 1, 'cost' => 100, 'salePrice' => 130],
        ]);
        $sent = $this->createQuote('COT-SENT-IGNORED', 'enviada', [
            ['product' => 'Ya enviada', 'partNumber' => 'SENT-1', 'quantity' => 1, 'cost' => 100, 'salePrice' => 130],
        ]);

        Quote::query()->whereKey([$draft->id, $ready->id, $sent->id])->update([
            'updated_at' => '2026-08-01 12:00:00',
        ]);

        $initialAlerts = collect(
            $this->getJson('/api/dashboard')->assertOk()->json('alerts.unansweredQuotes')
        );

        $this->assertTrue($initialAlerts->contains('folio', $draft->folio));
        $this->assertTrue($initialAlerts->contains('folio', $ready->folio));
        $this->assertFalse($initialAlerts->contains('folio', $sent->folio));

        app(QuoteLockService::class)->acquire($draft->fresh());

        $this->assertDatabaseHas('quotes', [
            'id' => $draft->id,
            'last_opened_at' => '2026-08-13 12:00:00',
        ]);

        $afterOpening = collect(
            $this->getJson('/api/dashboard')->assertOk()->json('alerts.unansweredQuotes')
        );

        $this->assertFalse(
            $afterOpening->contains('folio', $draft->folio),
        );
        $this->assertTrue($afterOpening->contains('folio', $ready->folio));

        Carbon::setTestNow();
    }

    #[Test]
    public function it_lists_lista_terminada_quotes_for_sales_alert(): void
    {
        $ready = $this->createQuote('COT-LISTA-001', 'pendiente_envio', [
            ['product' => 'Ready', 'partNumber' => 'RDY-1', 'quantity' => 1, 'cost' => 10, 'salePrice' => 20],
        ]);

        $this->createQuote('COT-ELAB-001', 'en_elaboracion', [
            ['product' => 'Draft', 'partNumber' => 'DFT-1', 'quantity' => 1, 'cost' => 10, 'salePrice' => 20],
        ]);

        $response = $this->getJson('/api/dashboard');

        $response->assertOk();
        $this->assertTrue(
            collect($response->json('alerts.readyForSalesQuotes'))->contains('folio', $ready->folio)
        );
        $this->assertFalse(
            collect($response->json('alerts.readyForSalesQuotes'))->contains('folio', 'COT-ELAB-001')
        );
    }

    #[Test]
    public function it_lists_only_quotes_that_never_reached_lista_terminada_as_pending(): void
    {
        $pending = $this->createQuote('COT-PENDING-001', 'en_elaboracion', [
            ['product' => 'Pending', 'partNumber' => 'PND-1', 'quantity' => 1, 'cost' => 10, 'salePrice' => 20],
        ]);
        $reopened = $this->createQuote('COT-REOPENED-001', 'en_elaboracion', [
            ['product' => 'Reopened', 'partNumber' => 'RPN-1', 'quantity' => 1, 'cost' => 10, 'salePrice' => 20],
        ]);

        QuoteStatusEvent::query()->create([
            'quote_id' => $reopened->id,
            'from_status' => 'en_elaboracion',
            'to_status' => 'pendiente_envio',
            'created_at' => now(),
        ]);

        $pendingAlerts = collect(
            $this->getJson('/api/dashboard')->assertOk()->json('alerts.pendingQuotes')
        );

        $this->assertTrue($pendingAlerts->contains('folio', $pending->folio));
        $this->assertFalse($pendingAlerts->contains('folio', $reopened->folio));
    }

    #[Test]
    public function it_returns_stuck_processing_requests_in_alerts(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');

        $stuck = QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'source' => 'pdf',
            'status' => 'procesando',
            'file_name' => 'pedido.pdf',
        ]);
        QuoteRequest::query()->whereKey($stuck->id)->update([
            'updated_at' => '2026-06-15 09:30:00',
        ]);

        $response = $this->getJson('/api/dashboard');

        $response->assertOk();
        $this->assertTrue(
            collect($response->json('alerts.stuckProcessingRequests'))->contains('id', $stuck->id)
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function admin_and_compras_receive_unsent_requests_with_creator_and_workflow_status(): void
    {
        $creator = User::query()->whereHas('role', fn ($query) => $query->where('slug', 'gerente_compras'))->firstOrFail();

        $draft = QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'created_by' => $creator->id,
            'source' => 'texto',
            'status' => 'procesada',
            'workflow_status' => 'en_elaboracion',
            'raw_text' => 'Solicitud en elaboración',
        ]);
        $ready = QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'created_by' => $creator->id,
            'source' => 'pdf',
            'status' => 'procesada',
            'workflow_status' => 'pendiente_envio',
            'file_name' => 'lista.pdf',
        ]);
        $sent = QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'created_by' => $creator->id,
            'source' => 'pdf',
            'status' => 'procesada',
            'workflow_status' => 'enviada',
            'file_name' => 'enviada.pdf',
        ]);

        $requests = collect(
            $this->getJson('/api/dashboard')->assertOk()->json('alerts.unsentRequests')
        );

        $this->assertSame('en_elaboracion', $requests->firstWhere('id', $draft->id)['workflowStatus'] ?? null);
        $this->assertSame('pendiente_envio', $requests->firstWhere('id', $ready->id)['workflowStatus'] ?? null);
        $this->assertSame($creator->name, $requests->firstWhere('id', $draft->id)['createdByName'] ?? null);
        $this->assertFalse($requests->contains('id', $sent->id));

        $this->actingAs($creator);
        $this->assertTrue(
            collect($this->getJson('/api/dashboard')->assertOk()->json('alerts.unsentRequests'))
                ->contains('id', $draft->id)
        );
    }

    #[Test]
    public function ventas_does_not_receive_wholesaler_integration_alerts(): void
    {
        $this->actingAsDemoUser('ventas');

        $response = $this->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('alerts.integrationIssues', [])
            ->assertJsonPath('topRequestedProducts', [])
            ->assertJsonPath('topQuotedProducts', []);
    }

    #[Test]
    public function ventas_only_sees_own_unsent_and_pending_review_requests(): void
    {
        $ventas = $this->demoUser('ventas');
        $otroVentas = User::factory()->create([
            'role_id' => $ventas->role_id,
            'name' => 'Otro Vendedor Dashboard',
            'email' => 'otro-ventas-dashboard@test.local',
        ]);
        $compras = $this->demoUser('gerente_compras');

        $miaUnsent = QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'created_by' => $ventas->id,
            'source' => 'texto',
            'status' => 'procesada',
            'workflow_status' => 'en_elaboracion',
            'raw_text' => 'Mia no enviada',
            'file_name' => 'mia-unsent.pdf',
        ]);
        $ajenaUnsent = QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'created_by' => $otroVentas->id,
            'source' => 'texto',
            'status' => 'procesada',
            'workflow_status' => 'pendiente_envio',
            'raw_text' => 'Ajena no enviada',
            'file_name' => 'ajena-unsent.pdf',
        ]);
        $comprasUnsent = QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'created_by' => $compras->id,
            'source' => 'texto',
            'status' => 'procesada',
            'workflow_status' => 'en_elaboracion',
            'raw_text' => 'De compras',
            'file_name' => 'compras-unsent.pdf',
        ]);
        $miaPending = QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'created_by' => $ventas->id,
            'source' => 'pdf',
            'status' => 'procesada',
            'workflow_status' => 'en_elaboracion',
            'file_name' => 'mia-pending.pdf',
        ]);
        $ajenaPending = QuoteRequest::query()->create([
            'client_id' => $this->client->id,
            'created_by' => $otroVentas->id,
            'source' => 'pdf',
            'status' => 'procesada',
            'workflow_status' => 'en_elaboracion',
            'file_name' => 'ajena-pending.pdf',
        ]);

        $this->actingAs($ventas);
        $ventasPayload = $this->getJson('/api/dashboard')->assertOk();

        $unsentIds = collect($ventasPayload->json('alerts.unsentRequests'))->pluck('id');
        $this->assertTrue($unsentIds->contains($miaUnsent->id));
        $this->assertTrue($unsentIds->contains($miaPending->id));
        $this->assertFalse($unsentIds->contains($ajenaUnsent->id));
        $this->assertFalse($unsentIds->contains($comprasUnsent->id));

        $pendingIds = collect($ventasPayload->json('alerts.pendingReviewRequests'))->pluck('id');
        $this->assertTrue($pendingIds->contains($miaPending->id));
        $this->assertFalse($pendingIds->contains($ajenaPending->id));

        $this->actingAs($compras);
        $comprasUnsentIds = collect(
            $this->getJson('/api/dashboard')->assertOk()->json('alerts.unsentRequests')
        )->pluck('id');
        $this->assertTrue($comprasUnsentIds->contains($miaUnsent->id));
        $this->assertTrue($comprasUnsentIds->contains($ajenaUnsent->id));
        $this->assertTrue($comprasUnsentIds->contains($comprasUnsent->id));
    }

    /**
     * @param  list<array{product: string, partNumber: string, quantity: float, cost: float, salePrice: float}>  $lines
     */
    private function createQuote(
        string $folio,
        string $status,
        array $lines,
        ?User $asUser = null,
        ?int $validityDays = null,
    ): Quote {
        if ($asUser) {
            $this->actingAs($asUser);
        }

        $payloadLines = array_map(fn (array $line) => [
            'quantity' => $line['quantity'],
            'product' => $line['product'],
            'partNumber' => $line['partNumber'],
            'cost' => $line['cost'],
            'marginPercent' => 30,
            'salePrice' => $line['salePrice'],
            'amount' => $line['quantity'] * $line['salePrice'],
        ], $lines);

        $payload = [
            'folio' => $folio,
            'clientId' => $this->client->id,
            'status' => $status,
            'globalMarginPercent' => 30,
            'taxPercent' => 16,
            'lines' => $payloadLines,
        ];

        if ($validityDays !== null) {
            $payload['validityDays'] = $validityDays;
        }

        if ($status === 'facturada') {
            $payload['invoiceNumber'] = 'FAC-TEST-'.preg_replace('/[^A-Z0-9-]/', '', strtoupper($folio));
        }

        $response = $this->postJson('/api/cotizaciones', $payload);

        $response->assertCreated();

        return Quote::query()->findOrFail($response->json('id'));
    }
}
