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
    public function sync_command_backfills_missing_request_notifications_without_duplicates(): void
    {
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
            'company' => 'Cliente Backfill Compras',
            'rfc' => 'CBC010101AAA',
        ]);
        $quoteDefaults = [
            'client_id' => $client->id,
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ];
        $pending = Quote::query()->create([...$quoteDefaults,
            'folio' => 'COT-BACKFILL-PENDIENTE',
            'status' => 'solicitud_cotizaciones',
        ]);
        $notPending = Quote::query()->create([...$quoteDefaults,
            'folio' => 'COT-BACKFILL-ELABORACION',
            'status' => 'en_elaboracion',
        ]);

        $this->artisan('sales:sync-compras-request-notifications')->assertSuccessful();
        $this->artisan('sales:sync-compras-request-notifications')->assertSuccessful();

        $notifications = SalesNotification::query()
            ->where('quote_id', $pending->id)
            ->where('reason_code', SalesNotificationService::REASON_SOLICITUD_COMPRAS)
            ->get();

        $this->assertCount(2, $notifications);
        $this->assertEqualsCanonicalizing(
            [$primaryCompras->id, $secondCompras->id],
            $notifications->pluck('recipient_id')->all(),
        );
        $this->assertNotContains($inactiveCompras->id, $notifications->pluck('recipient_id')->all());
        $this->assertDatabaseMissing('sales_notifications', [
            'quote_id' => $notPending->id,
            'reason_code' => SalesNotificationService::REASON_SOLICITUD_COMPRAS,
        ]);
    }

    #[Test]
    public function compras_inbox_backfills_missing_pending_request_notifications_when_listed(): void
    {
        $compras = $this->demoUser('gerente_compras');
        $client = Client::query()->create([
            'company' => 'Cliente Bandeja Compras',
            'rfc' => 'CBC020202AAA',
        ]);
        $quote = Quote::query()->create([
            'folio' => 'COT-BANDEJA-COMPRAS',
            'client_id' => $client->id,
            'status' => 'solicitud_cotizaciones',
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        $this->actingAs($compras);
        $inbox = collect($this->getJson('/api/notificaciones')->assertOk()->json('data'));

        $this->assertTrue($inbox->contains(
            fn (array $notification) => ($notification['quoteId'] ?? null) === $quote->id
                && ($notification['reasonCode'] ?? null) === SalesNotificationService::REASON_SOLICITUD_COMPRAS
                && ($notification['recipientId'] ?? null) === $compras->id,
        ));
        $this->assertDatabaseHas('sales_notifications', [
            'quote_id' => $quote->id,
            'recipient_id' => $compras->id,
            'audience' => SalesNotificationService::AUDIENCE_COMPRAS,
            'reason_code' => SalesNotificationService::REASON_SOLICITUD_COMPRAS,
        ]);
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
