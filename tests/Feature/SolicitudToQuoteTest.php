<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudToQuoteTest extends AuthenticatedFeatureTestCase
{
    #[Test]
    public function storing_solicitud_with_lines_creates_quote_for_compras(): void
    {
        $this->actingAsDemoUser('gerente_compras');
        $client = Client::query()->create([
            'company' => 'Cliente Auto Quote',
            'rfc' => 'CAQ010101AAA',
        ]);

        $response = $this->postJson('/api/solicitudes', [
            'client_id' => $client->id,
            'raw_text' => "2 Switch\n1 Cable",
            'lineas' => [
                [
                    'quantity' => 2,
                    'product' => 'Switch',
                    'partNumber' => 'SW-1',
                    'brand' => 'Cisco',
                    'description' => 'Switch',
                    'unit' => 'pza',
                ],
                [
                    'quantity' => 1,
                    'product' => 'Cable',
                    'partNumber' => 'CB-1',
                    'brand' => 'Generic',
                    'description' => 'Cable',
                    'unit' => 'pza',
                ],
            ],
        ]);

        $response->assertCreated();
        $requestId = $response->json('request_id');
        $quoteId = $response->json('quote_id');
        $this->assertNotEmpty($requestId);
        $this->assertNotEmpty($quoteId);
        $this->assertTrue((bool) $response->json('quote_created'));

        $this->assertDatabaseHas('quotes', [
            'id' => $quoteId,
            'request_id' => $requestId,
            'client_id' => $client->id,
        ]);
        $quote = Quote::query()->findOrFail($quoteId);
        $this->assertNull($quote->sent_at);
        $this->assertSame('solicitud_cotizaciones', $quote->status);

        $again = $this->putJson("/api/solicitudes/{$requestId}/lineas", [
            'lineas' => [
                [
                    'quantity' => 3,
                    'product' => 'Switch',
                    'partNumber' => 'SW-1',
                    'brand' => 'Cisco',
                    'description' => 'Switch',
                    'unit' => 'pza',
                ],
            ],
        ]);

        // Con cotización vinculada se pueden editar líneas; se sincronizan a la cotización.
        $again->assertOk();
        $this->assertSame(1, Quote::query()->where('request_id', $requestId)->count());
        $this->assertSame(1, Quote::query()->findOrFail($quoteId)->lines()->count());
        $this->assertSame(3.0, (float) Quote::query()->findOrFail($quoteId)->lines()->first()->quantity);
    }

    #[Test]
    public function update_lineas_creates_quote_when_missing(): void
    {
        $this->actingAsDemoUser('gerente_compras');
        $client = Client::query()->create([
            'company' => 'Cliente Update Quote',
            'rfc' => 'CUQ010101AAA',
        ]);
        $user = $this->demoUser('gerente_compras');
        $request = QuoteRequest::query()->create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'source' => 'texto',
            'status' => 'procesada',
            'workflow_status' => 'en_elaboracion',
            'raw_text' => 'demo',
        ]);

        $response = $this->putJson("/api/solicitudes/{$request->id}/lineas", [
            'lineas' => [
                [
                    'quantity' => 1,
                    'product' => 'Item',
                    'partNumber' => 'IT-1',
                    'brand' => 'X',
                    'description' => 'Item',
                    'unit' => 'pza',
                ],
            ],
        ]);

        $response->assertOk();
        $quoteId = $response->json('quote_id');
        $this->assertNotEmpty($quoteId);
        $this->assertDatabaseHas('quotes', [
            'id' => $quoteId,
            'request_id' => $request->id,
        ]);
    }
}
