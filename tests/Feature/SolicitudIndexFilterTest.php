<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\QuoteRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudIndexFilterTest extends AuthenticatedFeatureTestCase
{

    #[Test]
    public function it_filters_by_status(): void
    {
        QuoteRequest::query()->create([
            'source' => 'text',
            'status' => 'procesando',
            'raw_text' => 'pendiente',
        ]);

        QuoteRequest::query()->create([
            'source' => 'text',
            'status' => 'error',
            'raw_text' => 'fallo',
        ]);

        $response = $this->getJson('/api/solicitudes?status=error');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'error');
    }

    #[Test]
    public function it_searches_by_client_name_or_id(): void
    {
        $client = Client::query()->create([
            'company' => 'Zorro Industries 99',
            'rfc' => '',
            'contact_name' => '',
            'email' => '',
        ]);

        $match = QuoteRequest::query()->create([
            'client_id' => $client->id,
            'source' => 'text',
            'status' => 'procesada',
            'raw_text' => 'productos',
        ]);

        QuoteRequest::query()->create([
            'source' => 'text',
            'status' => 'procesada',
            'raw_text' => 'otro cliente',
        ]);

        $byClient = $this->getJson('/api/solicitudes?q=Zorro+Industries');
        $byClient->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);

        $byId = $this->getJson("/api/solicitudes?q={$match->id}");
        $byId->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    #[Test]
    public function it_includes_creator_name_in_index(): void
    {
        $user = $this->demoUser('administrador');

        QuoteRequest::query()->create([
            'source' => 'text',
            'status' => 'procesada',
            'raw_text' => 'switch 24p',
            'created_by' => $user->id,
        ]);

        $response = $this->getJson('/api/solicitudes');

        $response->assertOk()
            ->assertJsonPath('data.0.created_by', $user->id)
            ->assertJsonPath('data.0.created_by_name', $user->name);
    }
}
