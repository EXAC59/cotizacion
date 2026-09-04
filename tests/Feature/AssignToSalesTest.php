<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\SalesNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class AssignToSalesTest extends AuthenticatedFeatureTestCase
{
    private function createQuoteFor(User $creator, array $overrides = []): Quote
    {
        $client = Client::query()->create([
            'company' => 'Cliente Asignacion SA',
            'rfc' => 'CAS010101AAA',
        ]);

        return Quote::query()->create(array_merge([
            'folio' => 'COT-COMPRAS-'.uniqid(),
            'client_id' => $client->id,
            'status' => 'en_elaboracion',
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
            'subtotal' => 100,
            'tax_amount' => 16,
            'total' => 116,
            'created_by' => $creator->id,
        ], $overrides));
    }

    #[Test]
    public function compras_can_list_active_salespeople(): void
    {
        $compras = $this->demoUser('gerente_compras');
        $ventas = $this->demoUser('ventas');
        $this->actingAs($compras);

        $response = $this->getJson('/api/usuarios/ventas')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($ventas->id));
    }

    #[Test]
    public function ventas_cannot_list_salespeople(): void
    {
        $this->actingAs($this->demoUser('ventas'));
        $this->getJson('/api/usuarios/ventas')->assertStatus(422);
    }

    #[Test]
    public function compras_cannot_list_compras_users(): void
    {
        $this->actingAs($this->demoUser('gerente_compras'));
        $this->getJson('/api/usuarios/compras')->assertStatus(422);
    }

    #[Test]
    public function admin_can_list_both_teams(): void
    {
        $this->actingAs($this->demoUser('administrador'));

        $this->getJson('/api/usuarios/ventas')->assertOk();
        $this->getJson('/api/usuarios/compras')->assertOk();
    }

    #[Test]
    public function compras_assigns_quote_regenerates_folio_and_notifies(): void
    {
        $compras = $this->demoUser('gerente_compras');
        $ventas = $this->demoUser('ventas');
        $quote = $this->createQuoteFor($compras, [
            'folio' => 'COT-COMPRAS-ASSIGN-1',
        ]);

        $this->actingAs($compras);
        $response = $this->postJson("/api/cotizaciones/{$quote->id}/asignar-ventas", [
            'recipientId' => $ventas->id,
            'message' => 'Por favor da seguimiento a esta cotización.',
        ])->assertOk();

        $newFolio = $response->json('folio');
        $this->assertNotSame('COT-COMPRAS-ASSIGN-1', $newFolio);
        $code = $ventas->resolveQuoteFolioCode();
        $this->assertNotNull($code);
        $this->assertStringStartsWith('COT-'.$code.'-', $newFolio);
        $this->assertSame($ventas->id, (int) $quote->fresh()->created_by);

        $this->assertSame(
            1,
            SalesNotification::query()
                ->where('quote_id', $quote->id)
                ->where('recipient_id', $ventas->id)
                ->count()
        );

        $this->actingAs($ventas);
        $folios = collect($this->getJson('/api/cotizaciones?scope=mine')->assertOk()->json('data'))
            ->pluck('folio');
        $this->assertTrue($folios->contains($newFolio));
    }

    #[Test]
    public function compras_assigns_solicitud_regenerates_folio(): void
    {
        $compras = $this->demoUser('gerente_compras');
        $ventas = $this->demoUser('ventas');

        $request = QuoteRequest::query()->create([
            'source' => 'text',
            'status' => 'procesada',
            'raw_text' => 'producto de prueba',
            'folio' => 'SOL-COMPRAS-001',
            'created_by' => $compras->id,
        ]);

        $this->actingAs($compras);
        $response = $this->postJson("/api/solicitudes/{$request->id}/asignar-ventas", [
            'recipientId' => $ventas->id,
        ])->assertOk();

        $newFolio = $response->json('folio');
        $this->assertNotSame('SOL-COMPRAS-001', $newFolio);
        $code = $ventas->resolveQuoteFolioCode();
        $this->assertNotNull($code);
        $this->assertStringStartsWith('SOL-'.$code.'-', $newFolio);
        $this->assertSame($ventas->id, (int) $request->fresh()->created_by);
        $this->assertSame($compras->id, (int) $request->fresh()->reviewed_by);
        $response
            ->assertJsonPath('reviewed_by', (string) $compras->id)
            ->assertJsonPath('needs_external_review', false)
            ->assertJsonPath('assigned_to_sales', true);

        $this->actingAs($ventas);
        $ids = collect($this->getJson('/api/solicitudes?scope=mine')->assertOk()->json('data'))
            ->pluck('id');
        $this->assertTrue($ids->contains($request->id));
    }

    #[Test]
    public function ventas_cannot_assign_quote_to_other_ventas(): void
    {
        $ventas = $this->demoUser('ventas');
        $other = User::factory()->create([
            'role_id' => DB::table('roles')->where('slug', 'ventas')->value('id'),
            'active' => true,
            'name' => 'Otro Vendedor',
            'folio_code' => 'OTRO2',
        ]);
        $quote = $this->createQuoteFor($ventas);

        $this->actingAs($ventas);
        $this->postJson("/api/cotizaciones/{$quote->id}/asignar-ventas", [
            'recipientId' => $other->id,
            'message' => 'Te paso esta cotización.',
        ])->assertStatus(422);

        $this->assertSame($ventas->id, (int) $quote->fresh()->created_by);
    }

    #[Test]
    public function rejects_non_ventas_recipient(): void
    {
        $compras = $this->demoUser('gerente_compras');
        $admin = $this->demoUser('administrador');
        $quote = $this->createQuoteFor($compras);

        $this->actingAs($compras);
        $this->postJson("/api/cotizaciones/{$quote->id}/asignar-ventas", [
            'recipientId' => $admin->id,
            'message' => 'Destinatario inválido.',
        ])->assertStatus(422);
    }

    #[Test]
    public function can_reassign_quote_already_sent_to_sales(): void
    {
        $compras = $this->demoUser('gerente_compras');
        $ventas = $this->demoUser('ventas');
        $otroVentas = User::factory()->create([
            'role_id' => DB::table('roles')->where('slug', 'ventas')->value('id'),
            'active' => true,
            'name' => 'Otro Vendedor Reassign',
            'folio_code' => 'OTROV',
        ]);
        $quote = $this->createQuoteFor($compras, [
            'folio' => 'COT-COMPRAS-ONCE',
        ]);

        $this->actingAs($compras);
        $this->postJson("/api/cotizaciones/{$quote->id}/asignar-ventas", [
            'recipientId' => $ventas->id,
        ])->assertOk();

        $this->postJson("/api/cotizaciones/{$quote->id}/asignar-ventas", [
            'recipientId' => $otroVentas->id,
        ])->assertOk();

        $this->assertSame($otroVentas->id, (int) $quote->fresh()->created_by);
    }

    #[Test]
    public function ventas_can_list_active_compras(): void
    {
        $ventas = $this->demoUser('ventas');
        $compras = $this->demoUser('gerente_compras');
        $this->actingAs($ventas);

        $response = $this->getJson('/api/usuarios/compras')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($compras->id));
        $this->assertFalse($ids->contains($ventas->id));
    }

    #[Test]
    public function compras_cannot_list_compras_users_for_handoff(): void
    {
        $compras = $this->demoUser('gerente_compras');
        $this->actingAs($compras);
        $this->getJson('/api/usuarios/compras')->assertStatus(422);
    }

    #[Test]
    public function ventas_assigns_quote_to_compras(): void
    {
        $ventas = $this->demoUser('ventas');
        $compras = $this->demoUser('gerente_compras');
        $quote = $this->createQuoteFor($ventas, [
            'folio' => 'COT-VENTAS-TO-COMPRAS',
        ]);

        $this->actingAs($ventas);
        $response = $this->postJson("/api/cotizaciones/{$quote->id}/asignar-compras", [
            'recipientId' => $compras->id,
        ])->assertOk();

        $newFolio = $response->json('folio');
        $this->assertNotSame('COT-VENTAS-TO-COMPRAS', $newFolio);
        $this->assertTrue($response->json('assignedToCompras'));
        $this->assertFalse($response->json('assignedToSales'));
        $this->assertSame($compras->id, (int) $quote->fresh()->created_by);

        $this->assertSame(
            1,
            SalesNotification::query()
                ->where('quote_id', $quote->id)
                ->where('recipient_id', $compras->id)
                ->where('audience', 'compras')
                ->count()
        );
    }

    #[Test]
    public function ventas_assigns_solicitud_to_compras(): void
    {
        $ventas = $this->demoUser('ventas');
        $compras = $this->demoUser('gerente_compras');

        $request = QuoteRequest::query()->create([
            'source' => 'text',
            'status' => 'procesada',
            'raw_text' => 'producto de prueba',
            'folio' => 'SOL-VENTAS-001',
            'created_by' => $ventas->id,
        ]);

        $this->actingAs($ventas);
        $response = $this->postJson("/api/solicitudes/{$request->id}/asignar-compras", [
            'recipientId' => $compras->id,
        ])->assertOk();

        $this->assertTrue($response->json('assigned_to_compras'));
        $this->assertSame($compras->id, (int) $request->fresh()->created_by);
    }

    #[Test]
    public function can_reassign_quote_already_sent_to_compras(): void
    {
        $ventas = $this->demoUser('ventas');
        $compras = $this->demoUser('gerente_compras');
        $otroCompras = User::factory()->create([
            'role_id' => DB::table('roles')->where('slug', 'gerente_compras')->value('id'),
            'active' => true,
            'name' => 'Otro Compras',
            'folio_code' => 'OTROC',
        ]);
        $quote = $this->createQuoteFor($ventas, [
            'folio' => 'COT-VENTAS-ONCE',
        ]);

        $this->actingAs($ventas);
        $this->postJson("/api/cotizaciones/{$quote->id}/asignar-compras", [
            'recipientId' => $compras->id,
        ])->assertOk();

        $this->actingAs($this->demoUser('administrador'));
        $this->postJson("/api/cotizaciones/{$quote->id}/asignar-compras", [
            'recipientId' => $otroCompras->id,
        ])->assertOk();

        $this->assertSame($otroCompras->id, (int) $quote->fresh()->created_by);
    }

    #[Test]
    public function assign_without_recipient_uses_default_team_user(): void
    {
        $ventas = $this->demoUser('ventas');
        $compras = $this->demoUser('gerente_compras');
        $quote = $this->createQuoteFor($ventas, [
            'folio' => 'COT-DEFAULT-TEAM',
        ]);

        $this->actingAs($ventas);
        $response = $this->postJson("/api/cotizaciones/{$quote->id}/asignar-compras", [])
            ->assertOk();

        $this->assertSame($compras->id, (int) $quote->fresh()->created_by);
        $this->assertNotSame('', (string) $response->json('folio'));
        $this->assertNotSame('COT-DEFAULT-TEAM', (string) $response->json('folio'));
    }

    #[Test]
    public function compras_destination_rejects_non_compras_user(): void
    {
        $ventas = $this->demoUser('ventas');
        $otroVentas = User::factory()->create([
            'role_id' => DB::table('roles')->where('slug', 'ventas')->value('id'),
            'active' => true,
            'name' => 'Otro Ventas Destino',
            'folio_code' => 'OVDES',
        ]);
        $quote = $this->createQuoteFor($ventas, [
            'folio' => 'COT-ANY-USER',
        ]);

        $this->actingAs($ventas);
        $this->postJson("/api/cotizaciones/{$quote->id}/asignar-compras", [
            'recipientId' => $otroVentas->id,
        ])->assertStatus(422);

        $this->assertSame($ventas->id, (int) $quote->fresh()->created_by);
    }
}
