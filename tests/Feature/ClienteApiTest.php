<?php

namespace Tests\Feature;

use App\Models\Client;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class ClienteApiTest extends AuthenticatedFeatureTestCase
{
    #[Test]
    public function it_lists_and_creates_clients(): void
    {
        Client::query()->create([
            'company' => 'Acme SA',
            'rfc' => 'ACM123456ABC',
            'contact_name' => 'Juan Pérez',
            'email' => 'juan@acme.test',
        ]);

        $list = $this->getJson('/api/clientes');
        $list->assertOk()->assertJsonCount(1, 'data');

        $create = $this->postJson('/api/clientes', [
            'company' => 'Beta Corp',
            'contact' => 'María López',
            'paymentTerms' => '30 días',
        ]);

        $create->assertCreated()
            ->assertJsonPath('company', 'Beta Corp')
            ->assertJsonPath('contact', 'María López')
            ->assertJsonPath('paymentTerms', '30 días');

        $this->assertDatabaseCount('clients', 2);
    }

    #[Test]
    public function it_updates_and_deletes_client(): void
    {
        $client = Client::query()->create([
            'company' => 'Gamma',
            'rfc' => '',
            'contact_name' => '',
            'email' => '',
        ]);

        $this->putJson("/api/clientes/{$client->id}", [
            'company' => 'Gamma Actualizada',
        ])->assertOk()->assertJsonPath('company', 'Gamma Actualizada');

        $this->deleteJson("/api/clientes/{$client->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Cliente eliminado');

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    #[Test]
    public function it_validates_client_form_limits_and_formats(): void
    {
        $this->postJson('/api/clientes', [
            'company' => '',
            'contact' => str_repeat('a', 256),
            'email' => 'correo-invalido',
            'whatsapp' => str_repeat('1', 31),
            'paymentTerms' => str_repeat('a', 121),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'company',
                'contact',
                'email',
                'whatsapp',
                'paymentTerms',
            ]);

        $this->postJson('/api/clientes', [
            'company' => 'Empresa válida',
            'rfc' => 'RFC-INVALIDO',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['rfc']);
    }

    #[Test]
    public function it_rejects_invalid_client_id_on_solicitud(): void
    {
        $client = Client::query()->create([
            'company' => 'Válido SA',
            'rfc' => '',
            'contact_name' => '',
            'email' => '',
        ]);

        $response = $this->postJson('/api/solicitudes', [
            'raw_text' => '1 Producto demo (SKU-1) — Marca',
            'client_id' => '00000000-0000-4000-8000-000000000099',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id']);

        $missing = $this->postJson('/api/solicitudes', [
            'raw_text' => '1 Producto demo (SKU-1) — Marca',
        ]);

        $missing->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id']);

        $this->postJson('/api/solicitudes', [
            'raw_text' => '1 Producto demo (SKU-1) — Marca',
            'client_id' => $client->id,
        ])->assertCreated();
    }
}
