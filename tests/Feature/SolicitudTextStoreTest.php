<?php

namespace Tests\Feature;

use App\Models\Client;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudTextStoreTest extends AuthenticatedFeatureTestCase
{

    #[Test]
    public function it_resolves_brands_when_storing_text_solicitud(): void
    {
        $client = Client::query()->create([
            'company' => 'Cliente texto',
            'rfc' => '',
            'contact_name' => '',
            'email' => '',
        ]);

        $response = $this->postJson('/api/solicitudes', [
            'raw_text' => '1 Switch Cisco administrable (SG350-24) — Genérico',
            'client_id' => $client->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('lineas_count', 1)
            ->assertJsonPath('lineas.0.brand', 'Cisco')
            ->assertJsonPath('lineas.0.partNumber', 'SG350-24');
    }

    #[Test]
    public function it_stores_text_solicitud_with_client_edited_lineas(): void
    {
        $client = Client::query()->create([
            'company' => 'Cliente texto editado',
            'rfc' => '',
            'contact_name' => '',
            'email' => '',
        ]);

        $response = $this->postJson('/api/solicitudes', [
            'raw_text' => '1 Switch Cisco administrable (SG350-24) — Genérico',
            'client_id' => $client->id,
            'lineas' => [
                [
                    'quantity' => 2,
                    'product' => 'Switch Cisco 24p',
                    'partNumber' => 'SG350-24',
                    'brand' => 'Cisco',
                    'description' => 'Switch 24 puertos administrable',
                    'unit' => 'pza',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('lineas_count', 1)
            ->assertJsonPath('lineas.0.quantity', 2)
            ->assertJsonPath('lineas.0.product', 'Switch Cisco 24p')
            ->assertJsonPath('lineas.0.description', 'Switch 24 puertos administrable');
    }
}
