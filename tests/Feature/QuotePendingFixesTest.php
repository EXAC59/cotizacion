<?php

namespace Tests\Feature;

use App\Exceptions\QuoteLockedException;
use App\Models\Client;
use App\Models\Quote;
use App\Models\SalesNotification;
use App\Services\Quotes\QuoteLockService;
use App\Services\Sales\SalesNotificationService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class QuotePendingFixesTest extends AuthenticatedFeatureTestCase
{
    private function quote(array $values = []): Quote
    {
        $quote = Quote::create([...[
            'folio' => 'COT-PENDING-'.Str::random(8),
            'client_id' => Client::firstOrCreate(['company' => 'Pending fixes client'])->id,
            'created_by' => $this->demoUser('ventas')->id,
            'status' => 'en_elaboracion', 'tax_percent' => 16, 'total' => 100,
        ], ...$values]);
        if (isset($values['created_at'])) $quote->forceFill(['created_at' => $values['created_at']])->saveQuietly();
        return $quote;
    }

    #[Test]
    public function expired_reservations_are_renewed_for_sales_and_admin_without_reopening(): void
    {
        foreach (['ventas', 'administrador'] as $role) {
            $user = $this->demoUser($role);
            $quote = $this->quote(['locked_by' => $user->id, 'locked_at' => now()->subHour()]);
            app(QuoteLockService::class)->assertHeldByCurrentUser($quote, $user);
            $this->assertSame($user->id, $quote->fresh()->locked_by);
            $this->assertTrue($quote->fresh()->locked_at->greaterThan(now()->subMinute()));
        }
    }

    #[Test]
    public function active_reservation_of_another_editor_still_blocks_saving(): void
    {
        $quote = $this->quote(['locked_by' => $this->demoUser('gerente_compras')->id, 'locked_at' => now()]);
        $this->expectException(QuoteLockedException::class);
        app(QuoteLockService::class)->assertHeldByCurrentUser($quote, $this->demoUser('ventas'));
    }

    #[Test]
    public function purchasing_reminders_to_assigned_sales_are_visible_and_can_be_read_when_admin_created_the_quote(): void
    {
        $seller = $this->demoUser('ventas');
        $quote = $this->quote(['created_by' => $this->demoUser('administrador')->id, 'follow_up_assigned_to' => $seller->id]);
        $this->actingAsDemoUser('gerente_compras');
        $notice = $this->postJson('/api/notificaciones', ['quoteId' => $quote->id, 'message' => 'Revisa el seguimiento pendiente.'])
            ->assertCreated()->json('id');
        $this->actingAs($seller);
        $this->assertTrue(collect($this->getJson('/api/notificaciones')->assertOk()->json('data'))->contains('id', $notice));
        $this->patchJson('/api/notificaciones/'.$notice.'/leer')->assertOk();
        $this->assertDatabaseMissing('sales_notifications', ['id' => $notice, 'read_at' => null]);
    }

    #[Test]
    public function a_reminder_does_not_overwrite_an_unread_chat_notification(): void
    {
        $seller = $this->demoUser('ventas');
        $quote = $this->quote();
        $chat = SalesNotification::create(['quote_id' => $quote->id, 'recipient_id' => $seller->id,
            'audience' => 'ventas', 'reason_code' => 'mensaje_chat', 'message' => 'Mensaje original']);
        $this->actingAsDemoUser('gerente_compras');
        $this->postJson('/api/notificaciones', ['quoteId' => $quote->id, 'message' => 'Nuevo recordatorio de Compras.'])->assertCreated();
        $this->assertSame('mensaje_chat', $chat->fresh()->reason_code);
        $this->assertSame('Mensaje original', $chat->fresh()->message);
        $this->assertDatabaseCount('sales_notifications', 2);
    }

    #[Test]
    public function historical_purchasing_activity_restores_a_chat_without_claiming_a_completed_quote(): void
    {
        config(['messaging.enabled' => true]);
        $buyer = $this->demoUser('gerente_compras');
        $quote = $this->quote(['status' => 'pendiente_envio']);
        $quote->statusEvents()->create(['from_status' => 'en_elaboracion', 'to_status' => 'pendiente_envio',
            'user_id' => $buyer->id, 'created_at' => now()]);
        $this->actingAsDemoUser('ventas');
        $this->postJson('/api/mensajeria/'.$quote->id.'/abrir')->assertOk()->assertJsonPath('quote.canChat', true);
        $this->postJson('/api/mensajeria/'.$quote->id.'/mensajes', ['text' => 'Confirmar disponibilidad.', 'clientId' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('quote_conversations', ['quote_id' => $quote->id, 'purchasing_user_id' => $buyer->id]);
        $this->assertNull($quote->fresh()->purchase_assigned_to);
        $this->actingAs($buyer);
        $this->getJson('/api/mensajeria/'.$quote->id)->assertOk()->assertJsonPath('messages.1.text', 'Confirmar disponibilidad.');
    }

    #[Test]
    public function legacy_direct_reminders_on_admin_quotes_remain_visible_in_messaging(): void
    {
        config(['messaging.enabled' => true]);
        $seller = $this->demoUser('ventas');
        $quote = $this->quote(['created_by' => $this->demoUser('administrador')->id]);
        $notice = SalesNotification::create(['quote_id' => $quote->id, 'recipient_id' => $seller->id,
            'sender_id' => $this->demoUser('gerente_compras')->id, 'audience' => 'ventas',
            'reason_code' => 'sin_avance', 'message' => 'Seguimiento de Compras pendiente.']);
        $this->actingAs($seller);
        $this->assertTrue(app(SalesNotificationService::class)->visibleForUser($seller)->whereKey($notice->id)->exists());
        $this->getJson('/api/mensajeria')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.canChat', true);
        $this->postJson('/api/mensajeria/'.$quote->id.'/abrir')->assertOk()->assertJsonPath('quote.stage', 'en_proceso');
        $this->postJson('/api/mensajeria/'.$quote->id.'/mensajes', ['text' => 'Estoy revisando el aviso.', 'clientId' => (string) Str::uuid()])->assertCreated();
    }

    #[Test]
    public function sorting_happens_before_the_quote_list_limit(): void
    {
        for ($i = 0; $i < 201; $i++) {
            $this->quote(['folio' => sprintf('COT-ORDER-%03d', $i), 'created_at' => now()->subDays(202 - $i)]);
        }
        $this->getJson('/api/cotizaciones?scope=all&sort=oldest')->assertOk()->assertJsonCount(200, 'data')
            ->assertJsonPath('data.0.folio', 'COT-ORDER-000')->assertJsonPath('data.199.folio', 'COT-ORDER-199');
        $this->getJson('/api/cotizaciones?scope=all&sort=newest')->assertOk()->assertJsonCount(200, 'data')
            ->assertJsonPath('data.0.folio', 'COT-ORDER-200')->assertJsonPath('data.199.folio', 'COT-ORDER-001');
        $this->getJson('/api/cotizaciones?sort=invalid')->assertUnprocessable();
    }

    #[Test]
    public function dashboard_pending_quotes_keep_statuses_and_oldest_order_and_exclude_delivered_quotes(): void
    {
        foreach (['solicitud_cotizaciones', 'en_elaboracion', 'pendiente_envio'] as $index => $status) {
            $this->quote(['folio' => 'COT-DASH-'.$index, 'status' => $status, 'created_at' => now()->subDays(3 - $index)]);
        }
        $this->quote(['status' => 'pendiente_envio', 'sent_at' => now()]);
        $this->getJson('/api/dashboard/quotes?scope=all&focus=pending&sort=oldest')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.status', 'solicitud_cotizaciones')
            ->assertJsonPath('data.1.status', 'en_elaboracion')->assertJsonPath('data.2.status', 'pendiente_envio');
    }
}
