<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\QuoteLine;
use App\Models\QuoteLineOffer;
use App\Models\Role;
use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerCatalogSync;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class MayoristaApiTest extends AuthenticatedFeatureTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        app(WholesalerCatalogSync::class)->sync();
        Wholesaler::query()->update(['active' => true]);
    }

    #[Test]
    public function it_lists_wholesalers_from_catalog(): void
    {
        $response = $this->getJson('/api/mayoristas');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['active', 'total']])
            ->assertJsonPath('meta.total', 24);
    }

    #[Test]
    public function it_toggles_wholesaler_active_state(): void
    {
        $wholesaler = Wholesaler::query()->where('code', 'CT')->firstOrFail();

        $this->patchJson("/api/mayoristas/{$wholesaler->id}", ['active' => false])
            ->assertOk()
            ->assertJsonPath('active', false);

        $this->assertDatabaseHas('wholesalers', [
            'id' => $wholesaler->id,
            'active' => false,
        ]);
    }

    #[Test]
    public function it_returns_pending_offers_when_not_configured(): void
    {
        $response = $this->postJson('/api/mayoristas/consultar', [
            'partNumber' => 'C9200L-24T-4G-E',
        ]);

        $response->assertOk()
            ->assertJsonPath('partNumber', 'C9200L-24T-4G-E')
            ->assertJsonStructure(['offers']);

        $offers = $response->json('offers');
        $this->assertNotEmpty($offers);
        $this->assertArrayHasKey('error', $offers[0]);
    }

    #[Test]
    public function it_lists_low_stock_across_wholesalers(): void
    {
        $client = Client::query()->create([
            'company' => 'Stock Bajo SA',
            'rfc' => 'SBA010101XYZ',
        ]);
        $ct = Wholesaler::query()->where('code', 'CT')->firstOrFail();

        $quoteResponse = $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-STOCK-BAJO-1',
            'clientId' => $client->id,
            'status' => 'en_elaboracion',
            'globalMarginPercent' => 30,
            'taxPercent' => 16,
            'lines' => [[
                'quantity' => 1,
                'product' => 'Producto stock bajo',
                'partNumber' => 'LOW-ALL-1',
                'cost' => 10,
                'marginPercent' => 30,
                'salePrice' => 20,
                'amount' => 20,
            ]],
        ]);
        $quoteResponse->assertCreated();

        $line = QuoteLine::query()
            ->where('quote_id', $quoteResponse->json('id'))
            ->firstOrFail();

        QuoteLineOffer::query()->create([
            'quote_line_id' => $line->id,
            'wholesaler_id' => $ct->id,
            'cost' => 10,
            'stock' => 2,
            'warehouse' => 'Azcapotzalco (35A)',
            'is_selected' => true,
        ]);

        $response = $this->getJson('/api/mayoristas/stock-bajo');

        $response->assertOk()
            ->assertJsonStructure([
                'threshold',
                'count',
                'items' => [
                    ['partNumber', 'product', 'stock', 'warehouse', 'wholesalerName', 'wholesalerCode'],
                ],
            ]);

        $item = collect($response->json('items'))->firstWhere('partNumber', 'LOW-ALL-1');
        $this->assertNotNull($item);
        $this->assertSame(2, $item['stock']);
        $this->assertSame('CT', $item['wholesalerCode']);
        $this->assertSame('Azcapotzalco (35A)', $item['warehouse']);
    }

    #[Test]
    public function ventas_sees_aliased_low_stock_wholesaler_names(): void
    {
        $client = Client::query()->create([
            'company' => 'Ventas Stock SA',
            'rfc' => 'VSA010101XYZ',
        ]);
        $ct = Wholesaler::query()->where('code', 'CT')->firstOrFail();
        $ventas = $this->demoUser('ventas');
        $ventasRoleId = Role::query()->where('slug', 'ventas')->value('id');
        $moduleId = DB::table('modules')->where('slug', 'mayoristas')->value('id');
        $viewPermissionId = DB::table('permissions')
            ->where('module_id', $moduleId)
            ->where('action', 'view')
            ->value('id');
        $this->assertNotNull($ventasRoleId);
        $this->assertNotNull($viewPermissionId);
        // Ventas no tiene mayoristas.view por defecto; se concede solo para validar el enmascarado.
        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $ventasRoleId,
            'permission_id' => $viewPermissionId,
        ]);

        $this->actingAs($this->demoUser('administrador'));
        $quoteResponse = $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-STOCK-BAJO-V',
            'clientId' => $client->id,
            'status' => 'en_elaboracion',
            'globalMarginPercent' => 30,
            'taxPercent' => 16,
            'lines' => [[
                'quantity' => 1,
                'product' => 'Producto alias',
                'partNumber' => 'LOW-ALIAS-1',
                'cost' => 10,
                'marginPercent' => 30,
                'salePrice' => 20,
                'amount' => 20,
            ]],
        ]);
        $quoteResponse->assertCreated();
        $line = QuoteLine::query()
            ->where('quote_id', $quoteResponse->json('id'))
            ->firstOrFail();
        QuoteLineOffer::query()->create([
            'quote_line_id' => $line->id,
            'wholesaler_id' => $ct->id,
            'cost' => 10,
            'stock' => 1,
            'warehouse' => 'Azcapotzalco (35A)',
            'is_selected' => true,
        ]);

        $this->actingAs($ventas);
        $response = $this->getJson('/api/mayoristas/stock-bajo')->assertOk();
        $item = collect($response->json('items'))->firstWhere('partNumber', 'LOW-ALIAS-1');
        $this->assertNotNull($item);
        $this->assertSame('BODEGA01', $item['wholesalerCode']);
        $this->assertSame('BODEGA01', $item['wholesalerName']);
        $this->assertStringNotContainsStringIgnoringCase('CT Internacional', (string) $item['wholesalerName']);
    }
}
