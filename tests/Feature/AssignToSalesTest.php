<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class AssignToSalesTest extends AuthenticatedFeatureTestCase
{
    private function createQuoteFor(): Quote
    {
        $creator = $this->demoUser('gerente_compras');
        $client = Client::query()->create([
            'company' => 'Cliente Assign Gone SA',
            'rfc' => 'CAG010101AAA',
        ]);

        return Quote::query()->create([
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
        ]);
    }

    #[Test]
    public function assign_and_list_team_endpoints_are_gone(): void
    {
        $compras = $this->demoUser('gerente_compras');
        $ventas = $this->demoUser('ventas');
        $quote = $this->createQuoteFor();

        $this->actingAs($compras);
        $this->postJson("/api/cotizaciones/{$quote->id}/asignar-ventas", [
            'recipientId' => $ventas->id,
        ])->assertNotFound();

        $this->postJson("/api/cotizaciones/{$quote->id}/asignar-compras", [
            'recipientId' => $compras->id,
        ])->assertNotFound();

        $this->getJson('/api/usuarios/ventas')->assertNotFound();
        $this->getJson('/api/usuarios/compras')->assertNotFound();

        $client = Client::query()->create([
            'company' => 'Cliente Solicitud Gone',
            'rfc' => 'CSG010101AAA',
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

        $this->postJson("/api/solicitudes/{$request->id}/asignar-compras", [
            'recipientId' => $compras->id,
        ])->assertNotFound();
    }
}
