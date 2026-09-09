<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class AssignToSalesTest extends AuthenticatedFeatureTestCase
{
    private function createQuoteFor(User $creator, array $overrides = []): Quote
    {
        $client = Client::query()->create([
            'company' => 'Cliente Assign SA',
            'rfc' => 'CAS010101AAA',
        ]);

        return Quote::query()->create(array_merge([
            'folio' => 'COT-ASSIGN-'.uniqid(),
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
    public function ventas_can_assign_quote_to_compras(): void
    {
        $ventas = $this->demoUser('ventas');
        $compras = $this->demoUser('gerente_compras');
        $quote = $this->createQuoteFor($ventas);

        $this->actingAs($ventas);
        $response = $this->postJson("/api/cotizaciones/{$quote->id}/asignar-compras", [
            'recipientId' => $compras->id,
            'message' => 'Por favor revisa costos.',
        ]);

        $response->assertOk();
        $this->assertSame($compras->id, (int) $quote->fresh()->created_by);
        $this->assertNotSame('COT-ASSIGN', substr((string) $quote->fresh()->folio, 0, 10));
    }

    #[Test]
    public function ventas_can_assign_solicitud_to_compras(): void
    {
        $ventas = $this->demoUser('ventas');
        $compras = $this->demoUser('gerente_compras');
        $client = Client::query()->create([
            'company' => 'Cliente Solicitud Assign',
            'rfc' => 'CSA010101AAA',
        ]);
        $request = QuoteRequest::query()->create([
            'client_id' => $client->id,
            'created_by' => $ventas->id,
            'source' => 'texto',
            'status' => 'procesada',
            'workflow_status' => 'en_elaboracion',
            'raw_text' => 'demo',
        ]);

        $this->actingAs($ventas);
        $response = $this->postJson("/api/solicitudes/{$request->id}/asignar-compras", [
            'recipientId' => $compras->id,
        ]);

        $response->assertOk();
        $this->assertSame($compras->id, (int) $request->fresh()->created_by);
    }

    #[Test]
    public function compras_cannot_assign_to_compras(): void
    {
        $compras = $this->demoUser('gerente_compras');
        $otro = User::factory()->create([
            'role_id' => DB::table('roles')->where('slug', 'gerente_compras')->value('id'),
            'active' => true,
            'name' => 'Otro Compras',
        ]);
        $quote = $this->createQuoteFor($compras);

        $this->actingAs($compras);
        $this->postJson("/api/cotizaciones/{$quote->id}/asignar-compras", [
            'recipientId' => $otro->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function asignar_ventas_route_is_gone(): void
    {
        $compras = $this->demoUser('gerente_compras');
        $ventas = $this->demoUser('ventas');
        $quote = $this->createQuoteFor($compras);

        $this->actingAs($compras);
        $this->postJson("/api/cotizaciones/{$quote->id}/asignar-ventas", [
            'recipientId' => $ventas->id,
        ])->assertNotFound();

        $client = Client::query()->create([
            'company' => 'Cliente Gone',
            'rfc' => 'CGO010101AAA',
        ]);
        $request = QuoteRequest::query()->create([
            'client_id' => $client->id,
            'created_by' => $compras->id,
            'source' => 'texto',
            'status' => 'procesada',
            'workflow_status' => 'en_elaboracion',
            'raw_text' => 'demo',
        ]);

        $this->postJson("/api/solicitudes/{$request->id}/asignar-ventas", [
            'recipientId' => $ventas->id,
        ])->assertNotFound();
    }

    #[Test]
    public function usuarios_ventas_endpoint_is_gone(): void
    {
        $this->actingAsDemoUser('gerente_compras');
        $this->getJson('/api/usuarios/ventas')->assertNotFound();
    }
}
