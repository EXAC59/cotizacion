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

    #[Test]
    public function ventas_and_compras_default_to_own_solicitudes_and_can_list_others(): void
    {
        $ventas = $this->demoUser('ventas');
        $compras = $this->demoUser('gerente_compras');

        $own = QuoteRequest::query()->create([
            'source' => 'text',
            'status' => 'procesada',
            'raw_text' => 'propia',
            'created_by' => $ventas->id,
        ]);
        $other = QuoteRequest::query()->create([
            'source' => 'text',
            'status' => 'procesada',
            'raw_text' => 'ajena',
            'created_by' => $compras->id,
        ]);

        $this->actingAs($ventas);
        $mine = collect($this->getJson('/api/solicitudes')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($mine->contains($own->id));
        $this->assertFalse($mine->contains($other->id));

        $team = collect($this->getJson('/api/solicitudes?scope=team')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($team->contains($other->id));
        $this->assertFalse($team->contains($own->id));

        $this->actingAs($compras);
        $comprasMine = collect($this->getJson('/api/solicitudes')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($comprasMine->contains($other->id));
        $this->assertFalse($comprasMine->contains($own->id));

        $comprasAll = collect($this->getJson('/api/solicitudes?scope=all')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($comprasAll->contains($own->id));
        $this->assertTrue($comprasAll->contains($other->id));
    }
}
