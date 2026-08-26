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

        $this->postJson('/api/notificaciones', [
            'quoteId' => $quote->id,
            'message' => 'Cotización lista, por favor da seguimiento.',
        ])
            ->assertCreated()
            ->assertJsonPath('reasonCode', 'lista_terminada')
            ->assertJsonPath('audience', 'ventas');
    }

    #[Test]
    public function rejects_notify_without_comment(): void
    {
        $this->actingAsDemoUser('gerente_compras');
        $quote = $this->createQuote(['status' => 'pendiente_envio']);

        $this->postJson('/api/notificaciones', ['quoteId' => $quote->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    #[Test]
    public function compras_can_comment_even_when_not_eligible_for_auto_alert(): void
    {
        $this->actingAsDemoUser('gerente_compras');
        $quote = $this->createQuote([
            'status' => 'en_elaboracion',
            'updated_at' => now(),
            'last_opened_at' => now(),
        ]);

        $this->postJson('/api/notificaciones', [
            'quoteId' => $quote->id,
            'message' => 'Revisa el recordatorio de esta cotización con el cliente.',
        ])
            ->assertCreated()
            ->assertJsonPath('reasonCode', 'comentario_compras')
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

        $this->getJson("/api/cotizaciones/{$quote->id}/elegibilidad-aviso")
            ->assertOk()
            ->assertJsonPath('eligible', false);
    }

    #[Test]
    public function updates_unread_comment_instead_of_duplicating(): void
    {
        $this->actingAsDemoUser('gerente_compras');
        $quote = $this->createQuote(['status' => 'pendiente_envio']);

        $this->postJson('/api/notificaciones', [
            'quoteId' => $quote->id,
            'message' => 'Primer comentario del recordatorio.',
        ])->assertCreated();

        $this->postJson('/api/notificaciones', [
            'quoteId' => $quote->id,
            'message' => 'Comentario actualizado del recordatorio.',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Comentario actualizado del recordatorio.');

        $this->assertSame(1, SalesNotification::query()->where('quote_id', $quote->id)->count());
    }

    #[Test]
    public function compras_cannot_mark_follow_up_status(): void
    {
        $this->actingAsDemoUser('gerente_compras');
        $quote = $this->createQuote(['status' => 'pendiente_envio']);

        $this->patchJson("/api/cotizaciones/{$quote->id}/seguimiento", [
            'status' => 'negociacion',
            'remindDate' => now()->toDateString(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
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

        $this->postJson('/api/notificaciones', [
            'quoteId' => $quote->id,
            'message' => 'Lista para que ventas continúe el recordatorio.',
        ])
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
        $this->postJson('/api/notificaciones', [
            'quoteId' => $quote->id,
            'message' => 'Comentario de prueba para filtrar por rol.',
        ])->assertCreated();

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
    public function notify_targets_last_ventas_user_who_touched_quote(): void
    {
        $compras = $this->demoUser('gerente_compras');
        $creator = $this->demoUser('ventas');

        $otherVentas = \App\Models\User::query()->create([
            'name' => 'Ventas Ultimo',
            'email' => 'ventas.ultimo.'.uniqid().'@test.local',
            'username' => 'ventas_ultimo_'.uniqid(),
            'password' => bcrypt('demo'),
            'role_id' => $creator->role_id,
            'active' => true,
        ]);

        $quote = $this->createQuote([
            'status' => 'pendiente_envio',
            'created_by' => $creator->id,
        ]);

        \App\Models\QuoteStatusEvent::query()->create([
            'quote_id' => $quote->id,
            'from_status' => 'en_elaboracion',
            'to_status' => 'pendiente_envio',
            'user_id' => $otherVentas->id,
            'created_at' => now(),
        ]);

        $this->actingAs($compras);
        $this->getJson("/api/cotizaciones/{$quote->id}/elegibilidad-aviso")
            ->assertOk()
            ->assertJsonPath('notifyRecipientName', $otherVentas->name);

        $this->postJson('/api/notificaciones', [
            'quoteId' => $quote->id,
            'message' => 'Por favor da seguimiento al cliente.',
        ])
            ->assertCreated()
            ->assertJsonPath('recipientName', $otherVentas->name)
            ->assertJsonPath('message', 'Por favor da seguimiento al cliente.');

        $this->actingAs($otherVentas);
        $list = $this->getJson('/api/notificaciones')->assertOk();
        $this->assertTrue(collect($list->json('data'))->contains(
            fn ($n) => ($n['quoteId'] ?? '') === $quote->id
                && ($n['message'] ?? '') === 'Por favor da seguimiento al cliente.'
        ));

        $this->actingAs($creator);
        $creatorList = $this->getJson('/api/notificaciones')->assertOk();
        $this->assertFalse(
            collect($creatorList->json('data'))->contains(
                fn ($n) => ($n['quoteId'] ?? '') === $quote->id
                    && str_contains((string) ($n['message'] ?? ''), 'Por favor da seguimiento')
            ),
            'El creador no debe recibir el aviso dirigido a otro vendedor.'
        );
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

        $this->postJson('/api/notificaciones', [
            'quoteId' => $quote->id,
            'message' => 'Lleva varios días sin avance, revisa el recordatorio.',
        ])
            ->assertCreated()
            ->assertJsonPath('reasonCode', 'sin_avance');
    }

    #[Test]
    public function auto_notify_creates_bell_for_idle_en_elaboracion(): void
    {
        $ventas = $this->demoUser('ventas');
        $quote = $this->createQuote([
            'status' => 'en_elaboracion',
            'created_by' => $ventas->id,
        ]);
        $idleAt = now()->subDays(5);
        $quote->forceFill([
            'updated_at' => $idleAt,
            'last_opened_at' => $idleAt,
        ])->saveQuietly();

        $this->artisan('sales:notify-idle-quotes')
            ->assertSuccessful();

        $this->assertDatabaseHas('sales_notifications', [
            'quote_id' => $quote->id,
            'audience' => 'ventas',
            'reason_code' => 'sin_avance',
            'recipient_id' => $ventas->id,
        ]);

        // Dedupe: segundo run no duplica unread
        $this->artisan('sales:notify-idle-quotes')->assertSuccessful();
        $this->assertSame(
            1,
            SalesNotification::query()
                ->where('quote_id', $quote->id)
                ->where('reason_code', 'sin_avance')
                ->whereNull('read_at')
                ->count()
        );

        $this->actingAs($ventas);
        $list = $this->getJson('/api/notificaciones')->assertOk();
        $this->assertTrue(collect($list->json('data'))->contains(
            fn ($n) => ($n['quoteId'] ?? '') === $quote->id
                && ($n['reasonCode'] ?? '') === 'sin_avance'
                && ($n['senderName'] ?? '') === 'Automático'
        ));
    }

    #[Test]
    public function auto_notify_skips_fresh_quotes(): void
    {
        $quote = $this->createQuote(['status' => 'en_elaboracion']);
        $quote->forceFill([
            'updated_at' => now(),
            'last_opened_at' => now(),
        ])->saveQuietly();

        $this->artisan('sales:notify-idle-quotes')->assertSuccessful();

        $this->assertSame(
            0,
            SalesNotification::query()->where('quote_id', $quote->id)->count()
        );
    }
}
