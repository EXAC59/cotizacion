<?php

namespace Tests\Feature;

use App\Models\Client;
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

        $lecturaAt = $response->json('lectura_at');
        $createdAt = $response->json('created_at');
        $this->assertNotNull($lecturaAt);
        $this->assertSame($createdAt, $lecturaAt);

        $this->assertDatabaseCount('quote_requests', 1);
        $this->assertDatabaseCount('quote_request_lines', 2);
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
