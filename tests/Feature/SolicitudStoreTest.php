<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\QuoteRequest;
use App\Models\SalesNotification;
use App\Models\User;
use App\Services\SolicitudLecturaService;
use App\Services\Sales\SalesNotificationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudStoreTest extends AuthenticatedFeatureTestCase
{
    #[Test]
    public function it_creates_solicitud_from_text_with_lines(): void
    {
        $client = Client::query()->create([
            'company' => 'Cliente prueba',
            'rfc' => '',
            'contact_name' => '',
            'email' => '',
        ]);

        $response = $this->postJson('/api/solicitudes', [
            'raw_text' => "2 Monitor Dell 24\n1 Teclado mecánico (KB-100) — Logitech",
            'client_id' => $client->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('source', 'text')
            ->assertJsonPath('status', 'procesada')
            ->assertJsonPath('interpretacion_via', 'parser')
            ->assertJsonPath('lineas_count', 2);

        $folio = (string) $response->json('folio');
        $this->assertMatchesRegularExpression('/^SC-\d{4}$/', $folio);
        $this->assertDatabaseHas('quote_requests', [
            'id' => $response->json('id'),
            'folio' => $folio,
        ]);

        $lecturaAt = $response->json('lectura_at');
        $createdAt = $response->json('created_at');
        $this->assertNotNull($lecturaAt);
        $this->assertSame($createdAt, $lecturaAt);

        $this->assertDatabaseCount('quote_requests', 1);
        $this->assertDatabaseCount('quote_request_lines', 2);
    }

    #[Test]
    public function solicitud_folios_are_global_and_unique(): void
    {
        $service = app(SolicitudLecturaService::class);
        $meta = [
            'source' => 'text',
            'raw_text' => 'Producto de prueba',
        ];
        $lines = [[
            'quantity' => 1,
            'product' => 'Producto de prueba',
            'partNumber' => 'SKU-1',
            'brand' => 'Genérico',
        ]];

        $first = $service->crearDesdeLineas($lines, $meta);
        $second = $service->crearDesdeLineas($lines, $meta);

        $this->assertMatchesRegularExpression('/^SC-\d{4}$/', $first->folio);
        $this->assertMatchesRegularExpression('/^SC-\d{4}$/', $second->folio);
        $this->assertNotSame($first->folio, $second->folio);
        $this->assertSame(2, QuoteRequest::query()
            ->whereIn('folio', [$first->folio, $second->folio])
            ->count());
    }

    #[Test]
    public function ventas_creates_quote_and_notifies_all_active_compras_users(): void
    {
        $this->actingAsDemoUser('ventas');
        $client = Client::query()->create([
            'company' => 'Cliente aviso Compras',
            'rfc' => '',
        ]);

        $response = $this->postJson('/api/solicitudes', [
            'raw_text' => '1 Equipo de prueba',
            'client_id' => $client->id,
        ])->assertCreated()
            ->assertJsonPath('quote_created', true)
            ->assertJsonPath('compras_notification_count', 1);

        $quoteId = $response->json('quote_id');
        $compras = User::query()->whereHas('role', fn ($role) => $role->where('slug', 'gerente_compras'))
            ->where('active', true)->pluck('id');

        $this->assertEqualsCanonicalizing(
            $compras->all(),
            SalesNotification::query()
                ->where('quote_id', $quoteId)
                ->where('audience', SalesNotificationService::AUDIENCE_COMPRAS)
                ->where('reason_code', SalesNotificationService::REASON_SOLICITUD_COMPRAS)
                ->pluck('recipient_id')->all(),
        );
    }

    #[Test]
    public function it_returns_422_when_text_has_no_lines(): void
    {
        $client = Client::query()->create([
            'company' => 'Cliente prueba',
            'rfc' => '',
            'contact_name' => '',
            'email' => '',
        ]);

        $response = $this->postJson('/api/solicitudes', [
            'raw_text' => 'Solicitud de cotización',
            'client_id' => $client->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'No se detectaron líneas en el texto.');

        $this->assertDatabaseCount('quote_requests', 0);
    }

    #[Test]
    public function it_requires_client_id(): void
    {
        $response = $this->postJson('/api/solicitudes', [
            'raw_text' => "1 Producto demo (SKU-1) — Marca",
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id']);
    }
}
