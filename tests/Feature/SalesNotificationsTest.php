<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\SalesNotification;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class SalesNotificationsTest extends AuthenticatedFeatureTestCase
{
    private function createQuote(array $overrides = []): Quote
    {
        $client = Client::query()->create([
            'company' => 'Cliente Aviso SA',
            'rfc' => 'CAV010101AAA',
        ]);

        $ventas = $this->demoUser('ventas');

        return Quote::query()->create(array_merge([
            'folio' => 'COT-AVISO-'.uniqid(),
            'client_id' => $client->id,
            'status' => 'pendiente_envio',
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
            'subtotal' => 100,
            'tax_amount' => 16,
            'total' => 116,
            'created_by' => $ventas->id,
            'updated_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function compras_can_notify_eligible_lista_terminada(): void
    {
        $this->actingAsDemoUser('gerente_compras');
        $quote = $this->createQuote(['status' => 'pendiente_envio']);

        $this->postJson('/api/notificaciones', ['quoteId' => $quote->id])
            ->assertCreated()
            ->assertJsonPath('reasonCode', 'lista_terminada')
            ->assertJsonPath('audience', 'ventas');
    }

    #[Test]
    public function rejects_notify_when_not_eligible(): void
    {
        $this->actingAsDemoUser('gerente_compras');
        $quote = $this->createQuote([
            'status' => 'en_elaboracion',
            'updated_at' => now(),
            'last_opened_at' => now(),
        ]);

        $this->postJson('/api/notificaciones', ['quoteId' => $quote->id])
            ->assertStatus(422);
    }

    #[Test]
    public function dedupes_unread_notifications(): void
    {
        $this->actingAsDemoUser('gerente_compras');
        $quote = $this->createQuote(['status' => 'pendiente_envio']);

        $this->postJson('/api/notificaciones', ['quoteId' => $quote->id])
            ->assertCreated();

        $this->postJson('/api/notificaciones', ['quoteId' => $quote->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quoteId']);

        $this->assertSame(1, SalesNotification::query()->where('quote_id', $quote->id)->count());
    }

    #[Test]
    public function follow_up_negociacion_rejects_past_date(): void
    {
        $this->actingAsDemoUser('ventas');
        $quote = $this->createQuote();

        $yesterday = Carbon::yesterday()->toDateString();

        $this->patchJson("/api/cotizaciones/{$quote->id}/seguimiento", [
            'status' => 'negociacion',
            'remindDate' => $yesterday,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['remindDate']);
    }

    #[Test]
    public function follow_up_ganada_requires_invoice_and_notifies_compras(): void
    {
        $compras = $this->demoUser('gerente_compras');
        $this->actingAs($compras);
        $quote = $this->createQuote(['status' => 'pendiente_envio']);

        $this->postJson('/api/notificaciones', ['quoteId' => $quote->id])
            ->assertCreated();

        $ventas = $this->demoUser('ventas');
        $this->actingAs($ventas);

        $this->patchJson("/api/cotizaciones/{$quote->id}/seguimiento", [
            'status' => 'ganada',
        ])->assertStatus(422);

        $this->patchJson("/api/cotizaciones/{$quote->id}/seguimiento", [
            'status' => 'ganada',
            'invoice' => 'FAC-99',
        ])->assertOk()
            ->assertJsonPath('followUp.status', 'ganada')
            ->assertJsonPath('followUp.invoice', 'FAC-99');

        $this->assertDatabaseHas('quote_follow_up_events', [
            'quote_id' => $quote->id,
            'to_status' => 'ganada',
            'invoice' => 'FAC-99',
        ]);

        $this->assertTrue(
            SalesNotification::query()
                ->where('quote_id', $quote->id)
                ->where('audience', 'ventas')
                ->whereNotNull('read_at')
                ->exists()
        );

        $this->actingAs($compras);
        $list = $this->getJson('/api/notificaciones')->assertOk();
        $this->assertGreaterThanOrEqual(1, $list->json('unreadCount'));
        $this->assertTrue(collect($list->json('data'))->contains(
            fn ($n) => ($n['reasonCode'] ?? '') === 'seguimiento_actualizado'
                && ($n['quoteId'] ?? '') === $quote->id
        ));
    }

    #[Test]
    public function notifications_filtered_by_role(): void
    {
        $compras = $this->demoUser('gerente_compras');
        $ventas = $this->demoUser('ventas');
        $quote = $this->createQuote(['status' => 'pendiente_envio']);

        $this->actingAs($compras);
        $this->postJson('/api/notificaciones', ['quoteId' => $quote->id])->assertCreated();

        $this->actingAs($ventas);
        $ventasList = $this->getJson('/api/notificaciones')->assertOk();
        $this->assertTrue(collect($ventasList->json('data'))->contains(
            fn ($n) => ($n['audience'] ?? '') === 'ventas' && ($n['quoteId'] ?? '') === $quote->id
        ));

        $this->actingAs($compras);
        $comprasList = $this->getJson('/api/notificaciones')->assertOk();
        $this->assertTrue(collect($comprasList->json('data'))->every(
            fn ($n) => ($n['audience'] ?? '') === 'compras'
        ));
    }

    #[Test]
    public function sin_avance_eligible_when_idle_enough(): void
    {
        $this->actingAsDemoUser('gerente_compras');
        $quote = $this->createQuote(['status' => 'en_elaboracion']);
        $idleAt = now()->subDays(5);
        $quote->forceFill([
            'updated_at' => $idleAt,
            'last_opened_at' => $idleAt,
        ])->saveQuietly();

        $this->getJson("/api/cotizaciones/{$quote->id}/elegibilidad-aviso")
            ->assertOk()
            ->assertJsonPath('eligible', true)
            ->assertJsonPath('reasonCode', 'sin_avance');

        $this->postJson('/api/notificaciones', ['quoteId' => $quote->id])
            ->assertCreated()
            ->assertJsonPath('reasonCode', 'sin_avance');
    }
}
