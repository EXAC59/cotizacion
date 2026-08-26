<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class QuoteLockTest extends AuthenticatedFeatureTestCase
{
    #[Test]
    public function it_blocks_a_second_user_from_acquiring_the_edit_lock(): void
    {
        $client = Client::query()->create([
            'company' => 'Lock SA',
            'rfc' => 'LCK020202ABC',
        ]);

        $line = [
            'quantity' => 1,
            'product' => 'Item',
            'partNumber' => 'SKU-1',
            'cost' => 100,
            'marginPercent' => 30,
        ];

        $created = $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-LOCK-002',
            'clientId' => $client->id,
            'status' => 'en_elaboracion',
            'lines' => [$line],
        ])->assertCreated();

        $quoteId = $created->json('id');

        $this->postJson("/api/cotizaciones/{$quoteId}/bloqueo")
            ->assertOk()
            ->assertJsonPath('locked', true);

        $ventas = $this->demoUser('ventas');
        $this->actingAs($ventas);

        $this->postJson("/api/cotizaciones/{$quoteId}/bloqueo")
            ->assertStatus(423)
            ->assertJsonPath('lockedBy.email', 'admin@cotizacion.test');
    }

    #[Test]
    public function it_releases_lock_and_allows_save_only_while_held(): void
    {
        $client = Client::query()->create([
            'company' => 'Save Lock SA',
            'rfc' => 'SVL020202ABC',
        ]);

        $line = [
            'quantity' => 1,
            'product' => 'Item',
            'partNumber' => 'SKU-1',
            'cost' => 100,
            'marginPercent' => 30,
        ];

        $created = $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-LOCK-003',
            'clientId' => $client->id,
            'status' => 'en_elaboracion',
            'lines' => [$line],
        ])->assertCreated();

        $quoteId = $created->json('id');

        $this->postJson('/api/cotizaciones', [
            'id' => $quoteId,
            'folio' => 'COT-LOCK-003',
            'clientId' => $client->id,
            'status' => 'pendiente_envio',
            'lines' => [$line],
        ])->assertStatus(423);

        $this->postJson("/api/cotizaciones/{$quoteId}/bloqueo")->assertOk();

        $this->postJson('/api/cotizaciones', [
            'id' => $quoteId,
            'folio' => 'COT-LOCK-003',
            'clientId' => $client->id,
            'status' => 'pendiente_envio',
            'lines' => [$line],
        ])->assertCreated()
            ->assertJsonPath('status', 'pendiente_envio');

        $this->deleteJson("/api/cotizaciones/{$quoteId}/bloqueo")->assertOk();
    }

    #[Test]
    public function it_auto_transitions_solicitud_cotizaciones_to_en_elaboracion_on_lock(): void
    {
        $client = Client::query()->create([
            'company' => 'Auto Status SA',
            'rfc' => 'AST020202ABC',
        ]);

        $quote = Quote::query()->create([
            'folio' => 'COT-LOCK-AUTO-001',
            'client_id' => $client->id,
            'status' => 'solicitud_cotizaciones',
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        $this->postJson("/api/cotizaciones/{$quote->id}/bloqueo")
            ->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('status', 'en_elaboracion')
            ->assertJsonPath('statusChanged', true);

        $this->assertSame('en_elaboracion', $quote->fresh()->status);
    }

    #[Test]
    public function it_does_not_auto_transition_enviada_to_modificacion_on_lock(): void
    {
        $client = Client::query()->create([
            'company' => 'Mod Status SA',
            'rfc' => 'MST020202ABC',
        ]);

        $quote = Quote::query()->create([
            'folio' => 'COT-LOCK-MOD-001',
            'client_id' => $client->id,
            'status' => 'enviada',
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'sent_at' => now(),
        ]);

        $this->postJson("/api/cotizaciones/{$quote->id}/bloqueo")
            ->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('status', 'enviada')
            ->assertJsonPath('statusChanged', false);

        $this->assertSame('enviada', $quote->fresh()->status);
    }

    #[Test]
    public function it_auto_transitions_lista_terminada_to_en_elaboracion_on_lock(): void
    {
        $client = Client::query()->create([
            'company' => 'Lista Elab SA',
            'rfc' => 'LMD020202ABC',
        ]);

        $quote = Quote::query()->create([
            'folio' => 'COT-LOCK-ELAB-002',
            'client_id' => $client->id,
            'status' => 'pendiente_envio',
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
        ]);

        $this->postJson("/api/cotizaciones/{$quote->id}/bloqueo")
            ->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('status', 'en_elaboracion')
            ->assertJsonPath('statusChanged', true);

        $this->assertSame('en_elaboracion', $quote->fresh()->status);
    }

    #[Test]
    public function it_does_not_auto_transition_aceptada_facturada_to_modificacion_on_lock(): void
    {
        foreach (['aceptada', 'facturada'] as $status) {
            $client = Client::query()->create([
                'company' => "Won {$status} SA",
                'rfc' => 'W'.strtoupper(substr($status, 0, 2)).'020202ABC',
            ]);

            $quote = Quote::query()->create([
                'folio' => 'COT-LOCK-WON-'.strtoupper($status),
                'client_id' => $client->id,
                'status' => $status,
                'validity_days' => 15,
                'global_margin_percent' => 30,
                'tax_percent' => 16,
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0,
            ]);

            $this->postJson("/api/cotizaciones/{$quote->id}/bloqueo")
                ->assertOk()
                ->assertJsonPath('locked', true)
                ->assertJsonPath('status', $status)
                ->assertJsonPath('statusChanged', false);

            $this->assertSame($status, $quote->fresh()->status);
            Quote::query()->whereKey($quote->id)->delete();
            Client::query()->whereKey($client->id)->delete();
        }
    }

    #[Test]
    public function it_allows_takeover_when_lock_heartbeat_expired(): void
    {
        config(['quotes.lock_ttl_seconds' => 120]);

        $client = Client::query()->create([
            'company' => 'TTL Lock SA',
            'rfc' => 'TTL020202ABC',
        ]);

        $created = $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-LOCK-TTL-001',
            'clientId' => $client->id,
            'status' => 'en_elaboracion',
            'lines' => [[
                'quantity' => 1,
                'product' => 'Item',
                'partNumber' => 'SKU-1',
                'cost' => 100,
                'marginPercent' => 30,
            ]],
        ])->assertCreated();

        $quoteId = $created->json('id');

        $this->postJson("/api/cotizaciones/{$quoteId}/bloqueo")->assertOk();

        Quote::query()->whereKey($quoteId)->update([
            'locked_at' => now()->subSeconds(121),
        ]);

        $ventas = $this->demoUser('ventas');
        $this->actingAs($ventas);

        $this->postJson("/api/cotizaciones/{$quoteId}/bloqueo")
            ->assertOk()
            ->assertJsonPath('locked', true);
    }

    #[Test]
    public function logout_releases_held_quote_locks(): void
    {
        $client = Client::query()->create([
            'company' => 'Logout Lock SA',
            'rfc' => 'LGL020202ABC',
        ]);

        $created = $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-LOCK-LOGOUT-001',
            'clientId' => $client->id,
            'status' => 'en_elaboracion',
            'lines' => [[
                'quantity' => 1,
                'product' => 'Item',
                'partNumber' => 'SKU-1',
                'cost' => 100,
                'marginPercent' => 30,
            ]],
        ])->assertCreated();

        $quoteId = $created->json('id');
        $this->postJson("/api/cotizaciones/{$quoteId}/bloqueo")->assertOk();

        $this->assertNotNull(Quote::query()->whereKey($quoteId)->value('locked_by'));

        $this->postJson('/api/logout')->assertOk();

        $this->assertNull(Quote::query()->whereKey($quoteId)->value('locked_by'));
        $this->assertNull(Quote::query()->whereKey($quoteId)->value('locked_at'));
    }
}
