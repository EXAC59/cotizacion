<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudCotizacionesTest extends AuthenticatedFeatureTestCase
{

    #[Test]
    public function it_lists_quotes_linked_to_solicitud(): void
    {
        $client = Client::query()->create([
            'company' => 'Cliente vinculado',
            'rfc' => '',
            'contact_name' => '',
            'email' => '',
        ]);

        $request = QuoteRequest::query()->create([
            'client_id' => $client->id,
            'source' => 'pdf',
            'status' => 'procesada',
            'file_name' => 'req.pdf',
        ]);

        $quote = Quote::query()->create([
            'folio' => 'COT-SOL-001',
            'client_id' => $client->id,
            'request_id' => $request->id,
            'status' => 'pendiente_envio',
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
            'subtotal' => 1000,
            'tax_amount' => 160,
            'total' => 1160,
        ]);

        $response = $this->getJson("/api/solicitudes/{$request->id}/cotizaciones");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $quote->id)
            ->assertJsonPath('data.0.folio', 'COT-SOL-001')
            ->assertJsonPath('data.0.clientName', 'Cliente vinculado')
            ->assertJsonPath('data.0.total', 1160);
    }

    #[Test]
    public function it_returns_404_for_unknown_solicitud(): void
    {
        $this->getJson('/api/solicitudes/00000000-0000-0000-0000-000000000099/cotizaciones')
            ->assertNotFound();
    }
}
