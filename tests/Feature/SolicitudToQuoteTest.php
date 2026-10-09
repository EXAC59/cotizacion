<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\Role;
use App\Models\SalesNotification;
use App\Models\User;
use App\Services\Sales\SalesNotificationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudToQuoteTest extends AuthenticatedFeatureTestCase
{
    #[Test]
    public function creating_quote_from_request_notifies_each_active_compras_user_independently(): void
    {
        $this->actingAsDemoUser('gerente_compras');
        $primaryCompras = $this->demoUser('gerente_compras');
        $comprasRole = Role::query()->where('slug', 'gerente_compras')->firstOrFail();
        $secondCompras = User::factory()->create([
            'role_id' => $comprasRole->id,
            'active' => true,
        ]);
        $inactiveCompras = User::factory()->create([
            'role_id' => $comprasRole->id,
            'active' => false,
        ]);
        $client = Client::query()->create([
            'company' => 'Cliente Avisos Compras',
            'rfc' => 'CAC010101AAA',
        ]);

        $response = $this->postJson('/api/solicitudes', [
            'client_id' => $client->id,
            'raw_text' => '1 Equipo',
            'lineas' => [[
                'quantity' => 1,
                'product' => 'Equipo',
                'partNumber' => 'EQ-1',
                'brand' => 'Marca',
                'description' => 'Equipo',
                'unit' => 'pza',
            ]],
        ])->assertCreated();

        $quoteId = $response->json('quote_id');
        $notifications = SalesNotification::query()
            ->where('quote_id', $quoteId)
            ->where('audience', SalesNotificationService::AUDIENCE_COMPRAS)
            ->where('reason_code', SalesNotificationService::REASON_SOLICITUD_COMPRAS)
            ->get();

        $this->assertCount(2, $notifications);
        $this->assertEqualsCanonicalizing(
            [$primaryCompras->id, $secondCompras->id],
            $notifications->pluck('recipient_id')->all(),
        );
        $this->assertNotContains($inactiveCompras->id, $notifications->pluck('recipient_id')->all());

        $this->actingAs($primaryCompras);
        $primaryInbox = collect($this->getJson('/api/notificaciones')->assertOk()->json('data'));
        $this->assertSame([$primaryCompras->id], $primaryInbox->pluck('recipientId')->all());

        $this->actingAs($secondCompras);
        $secondInbox = collect($this->getJson('/api/notificaciones')->assertOk()->json('data'));
        $this->assertSame([$secondCompras->id], $secondInbox->pluck('recipientId')->all());
    }

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
        $requestFolio = (string) $response->json('folio');
        $quoteFolio = (string) $response->json('quote_folio');
        $this->assertMatchesRegularExpression('/^SC-\d{4}$/', $requestFolio);
        $this->assertStringStartsWith('COT-', $quoteFolio);
        $this->assertNotSame($requestFolio, $quoteFolio);

        $this->getJson("/api/cotizaciones/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('folio', $quoteFolio)
            ->assertJsonPath('requestFolio', $requestFolio);

        $this->getJson("/api/solicitudes/{$requestId}/cotizaciones")
            ->assertOk()
            ->assertJsonPath('data.0.folio', $quoteFolio);

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
