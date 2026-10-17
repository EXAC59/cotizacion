<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\AppSetting;
use App\Models\SalesNotification;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class QuoteMessagingTest extends AuthenticatedFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['messaging.enabled' => true]);
    }

    private function quote(): Quote
    {
        return Quote::create(['folio' => 'COT-CHAT-'.Str::random(6),
            'client_id' => Client::firstOrCreate(['company' => 'Messaging Client'])->id,
            'created_by' => $this->demoUser('ventas')->id, 'status' => 'solicitud_cotizaciones', 'tax_percent' => 16, 'total' => 100]);
    }

    private function claim(Quote $quote): void
    {
        $this->actingAsDemoUser('gerente_compras');
        $this->postJson("/api/mensajeria/{$quote->id}/tomar")->assertOk();
    }

    #[Test]
    public function saving_a_new_quote_creates_its_chat_before_purchasing_claims_it(): void
    {
        $client = Client::firstOrCreate(['company' => 'Chat automático']);
        $this->actingAsDemoUser('ventas');
        $saved = $this->postJson('/api/cotizaciones', ['clientId' => $client->id, 'status' => 'en_elaboracion',
            'customerObservations' => 'Confirmar entrega.', 'lines' => []])->assertCreated();
        $id = $saved->json('id');
        $this->assertDatabaseHas('quote_conversations', ['quote_id' => $id, 'purchasing_user_id' => null]);
        $this->postJson("/api/mensajeria/{$id}/abrir")->assertOk();
        $this->postJson("/api/mensajeria/{$id}/abrir")->assertOk();
        $this->assertDatabaseCount('quote_conversations', 1);
        $this->assertDatabaseCount('quote_chat_messages', 1);
        $this->postJson("/api/mensajeria/{$id}/mensajes", ['text' => 'Mensaje inicial de Ventas', 'clientId' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('sales_notifications', ['quote_id' => $id, 'recipient_id' => $this->demoUser('gerente_compras')->id, 'reason_code' => 'mensaje_chat']);
    }

    #[Test]
    public function conversation_mode_shares_quotes_with_sales_and_keeps_notices_unread_until_opened(): void
    {
        $quote = $this->quote(); $this->claim($quote);
        $sales = $this->demoUser('ventas'); $buyer = $this->demoUser('gerente_compras');
        $foreignSeller = User::factory()->create(['role_id' => $sales->role_id, 'active' => true]);
        $foreign = $this->quote(); $foreign->update(['created_by' => $foreignSeller->id, 'status' => 'en_elaboracion']);
        $notice = SalesNotification::create(['quote_id' => $quote->id, 'recipient_id' => $sales->id,
            'audience' => 'ventas', 'reason_code' => 'sin_avance', 'message' => 'Aviso solo de la campana']);
        $this->actingAs($buyer);
        $response = $this->getJson('/api/mensajeria?mode=conversations')->assertOk()->assertJsonPath('total', 2);
        foreach ($response->json('data') as $row) { $this->assertEmpty($row['notices']); $this->assertFalse($row['hasNotifications']); }
        $this->getJson('/api/mensajeria?mode=conversations&search='.$foreign->folio)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $foreign->id);
        $this->actingAs($sales);
        $this->getJson('/api/mensajeria?mode=conversations')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/mensajeria?mode=conversations&search='.$quote->folio)->assertOk()
            ->assertJsonPath('data.0.notificationCount', 1)->assertJsonPath('data.0.hasNotifications', true);
        $detail = $this->getJson('/api/mensajeria/'.$quote->id)->assertOk();
        $this->postJson('/api/mensajeria/'.$quote->id.'/leer', ['lastId' => (int) $detail->json('messages.0.id')])->assertOk();
        $this->assertDatabaseHas('sales_notifications', ['id' => $notice->id, 'read_at' => null]);
    }

    #[Test]
    public function shared_buyer_reading_is_personal_and_does_not_reassign_completed_quotes(): void
    {
        $quote = $this->quote(); $this->claim($quote);
        $buyer = $this->demoUser('gerente_compras');
        $second = User::factory()->create(['role_id' => $buyer->role_id, 'active' => true]);
        $quote->update(['status' => 'pendiente_envio', 'purchase_assigned_to' => null]);
        $this->actingAsDemoUser('ventas');
        $message = $this->postJson("/api/mensajeria/{$quote->id}/mensajes", ['text' => 'Consultar entrega', 'clientId' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->actingAs($second);
        $this->postJson("/api/mensajeria/{$quote->id}/abrir")->assertOk();
        $this->postJson("/api/mensajeria/{$quote->id}/leer", ['lastId' => (int) $message])->assertOk();
        $this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->assertJsonPath('quote.unreadCount', 0);
        $this->postJson("/api/mensajeria/{$quote->id}/mensajes", ['text' => 'Otra persona de Compras confirma la entrega.', 'clientId' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('quote_conversations', ['quote_id' => $quote->id, 'purchasing_user_id' => $buyer->id]);
        $this->assertNull($quote->fresh()->purchase_assigned_to);
        $this->assertDatabaseHas('sales_notifications', ['quote_id' => $quote->id, 'recipient_id' => $buyer->id, 'reason_code' => 'mensaje_chat', 'read_at' => null]);
        $this->actingAs($buyer);
        $this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->assertJsonPath('quote.unreadCount', 2);
        $second->update(['active' => false]); $this->actingAs($second);
        $this->getJson("/api/mensajeria/{$quote->id}")->assertUnauthorized();
        $this->postJson("/api/mensajeria/{$quote->id}/mensajes", ['text' => 'Bloqueado', 'clientId' => (string) Str::uuid()])->assertUnauthorized();
    }

    #[Test]
    public function disabled_and_unauthenticated_access_cannot_read_messages(): void
    {
        config(['messaging.enabled' => false]);
        $this->getJson('/api/mensajeria')->assertNotFound();
        config(['messaging.enabled' => true]);
        auth()->forgetGuards();
        $this->getJson('/api/mensajeria')->assertUnauthorized();
    }

    #[Test]
    public function claim_opens_one_shared_chat_and_a_second_buyer_can_reply_without_taking_it(): void
    {
        $quote = $this->quote();
        $this->claim($quote);
        $this->postJson("/api/mensajeria/{$quote->id}/tomar")->assertOk();
        $this->assertDatabaseCount('quote_conversations', 1);
        $buyer = $this->demoUser('gerente_compras');
        $other = User::factory()->create(['role_id' => $buyer->role_id, 'active' => true]);
        $this->actingAs($other);
        $this->postJson("/api/mensajeria/{$quote->id}/tomar")->assertStatus(423);
        $this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->assertJsonPath('quote.canChat', true)
            ->assertJsonPath('quote.canManageWorkflow', false);
        $this->postJson("/api/mensajeria/{$quote->id}/mensajes", ['text' => 'Apoyo de otro comprador', 'clientId' => (string) Str::uuid()])->assertCreated();
        $this->postJson("/api/mensajeria/{$quote->id}/seguimiento", ['action' => 'check', 'key' => 'stock', 'checked' => true])->assertForbidden();
        $this->getJson('/api/mensajeria')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.canChat', true);
        $this->assertSame($buyer->id, $quote->fresh()->purchase_assigned_to);
    }

    #[Test]
    public function completed_quotes_keep_the_chat_when_the_purchase_assignment_is_released(): void
    {
        $quote = $this->quote(); $this->claim($quote);
        $buyer = $this->demoUser('gerente_compras');
        $quote->update(['status' => 'pendiente_envio', 'purchase_assigned_to' => null, 'purchase_completed_at' => now()]);
        $this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->assertJsonPath('quote.canChat', true);
        $this->getJson('/api/mensajeria?section=history')->assertOk()->assertJsonPath('total', 1);
        $this->actingAsDemoUser('ventas');
        $this->postJson("/api/mensajeria/{$quote->id}/mensajes", ['text' => 'Continuamos el seguimiento.', 'clientId' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('sales_notifications', ['quote_id' => $quote->id, 'recipient_id' => $buyer->id, 'reason_code' => 'mensaje_chat']);
    }

    #[Test]
    public function the_assigned_salesperson_receives_chat_notices_even_when_admin_created_the_quote(): void
    {
        $quote = $this->quote();
        $seller = $this->demoUser('ventas');
        $quote->update(['created_by' => $this->demoUser('administrador')->id, 'follow_up_assigned_to' => $seller->id]);
        $this->claim($quote);
        $this->postJson("/api/mensajeria/{$quote->id}/mensajes", ['text' => 'Aviso para el vendedor asignado.', 'clientId' => (string) Str::uuid()])->assertCreated();
        $this->actingAs($seller);
        $notice = DB::table('sales_notifications')->where('quote_id', $quote->id)->where('reason_code', 'mensaje_chat')->where('recipient_id', $seller->id)->value('id');
        $response = $this->getJson('/api/notificaciones')->assertOk();
        $this->assertTrue(collect($response->json('data'))->contains(fn ($item) => $item['id'] === $notice));
        $this->patchJson("/api/notificaciones/{$notice}/leer")->assertOk();
        $this->assertDatabaseMissing('sales_notifications', ['id' => $notice, 'read_at' => null]);
    }

    #[Test]
    public function existing_purchase_claim_also_opens_the_conversation(): void
    {
        $quote = $this->quote();
        $this->actingAsDemoUser('gerente_compras');
        $this->postJson("/api/cotizaciones/{$quote->id}/compras/tomar")->assertOk();
        $this->assertDatabaseHas('quote_conversations', ['quote_id' => $quote->id]);
    }

    #[Test]
    public function messages_are_persistent_idempotent_and_shared_without_changing_the_responsible_user(): void
    {
        $quote = $this->quote(); $this->claim($quote);
        $this->actingAsDemoUser('ventas');
        $payload = ['text' => 'Confirmar disponibilidad.', 'clientId' => (string) Str::uuid()];
        $first = $this->postJson("/api/mensajeria/{$quote->id}/mensajes", $payload)->assertCreated()->json('id');
        $this->postJson("/api/mensajeria/{$quote->id}/mensajes", $payload)->assertCreated()->assertJsonPath('id', $first);
        $this->postJson("/api/mensajeria/{$quote->id}/mensajes", [...$payload, 'text' => 'Otro contenido'])->assertConflict();
        $this->postJson("/api/mensajeria/{$quote->id}/mensajes", ['text' => '   ', 'clientId' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(1, DB::table('quote_chat_messages')->where('kind', 'text')->count());
        $this->actingAsDemoUser('gerente_compras');
        $this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->assertJsonPath('quote.unreadCount', 1);
        $other = User::factory()->create(['role_id' => $this->demoUser('ventas')->role_id, 'active' => true]);
        $this->actingAs($other);
        $this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->assertJsonPath('quote.canManageWorkflow', false);
        $this->postJson("/api/mensajeria/{$quote->id}/mensajes", ['text' => 'Apoyo del equipo de Ventas', 'clientId' => (string) Str::uuid()])->assertCreated();
        $this->postJson("/api/mensajeria/{$quote->id}/seguimiento", ['action' => 'close', 'text' => 'Cerrar'])->assertForbidden();
        $this->assertSame($this->demoUser('ventas')->id, $quote->fresh()->created_by);
    }

    #[Test]
    public function messaging_uses_the_same_sections_and_notices_as_the_bell(): void
    {
        AppSetting::current()->update(['unanswered_quote_days' => 3]);
        $pending = $this->quote();
        $attention = $this->quote(); $this->claim($attention);
        $waiting = $this->quote(); $waiting->update(['status' => 'en_elaboracion', 'last_activity_at' => now()->subDays(4)]);
        $ready = $this->quote(); $ready->update(['status' => 'pendiente_envio', 'last_activity_at' => now()->subDays(8)]);
        $sent = $this->quote(); $this->claim($sent); $sent->update(['status' => 'enviada', 'sent_at' => now()]);
        foreach ([false, true] as $read) SalesNotification::create([
            'quote_id' => $waiting->id, 'audience' => 'compras', 'recipient_id' => $this->demoUser('gerente_compras')->id,
            'reason_code' => 'sin_avance', 'message' => $read ? 'Aviso leído' : 'Aviso pendiente', 'created_at' => now(),
            'read_at' => $read ? now() : null,
        ]);
        $sales = $this->demoUser('ventas');
        $request = \App\Models\QuoteRequest::create(['client_id' => $pending->client_id, 'created_by' => $sales->id,
            'source' => 'texto', 'status' => 'procesada', 'workflow_status' => 'en_elaboracion', 'file_name' => 'Solicitud propia', 'raw_text' => 'Solicitud']);
        $foreign = \App\Models\QuoteRequest::create(['client_id' => $pending->client_id,
            'created_by' => User::factory()->create(['role_id' => $sales->role_id, 'active' => true])->id,
            'source' => 'texto', 'status' => 'procesada', 'workflow_status' => 'en_elaboracion', 'file_name' => 'Solicitud ajena', 'raw_text' => 'Ajena']);
        foreach (['gerente_compras'] as $role) {
            $this->actingAsDemoUser($role);
            $bell = $this->getJson('/api/notificaciones')->assertOk()->json('groups');
            $all = $this->getJson('/api/mensajeria')->assertOk();
            $expectedIds = collect($bell)->flatten(1)->map(fn ($notice) => $notice['quoteId'] ?? $notice['requestId'])->unique()->sort()->values()->all();
            $this->assertSame($expectedIds, collect($all->json('data'))->pluck('id')->sort()->values()->all());
            foreach ($bell as $section => $notices) {
                $this->assertSame(count($notices), $all->json('sections.'.$section));
                $response = $this->getJson('/api/mensajeria?section='.$section)->assertOk();
                $expectedNoticeIds = collect($notices)->pluck('id')->sort()->values()->all();
                $actualNoticeIds = collect($response->json('data'))->pluck('notices')->flatten(1)->pluck('id')->sort()->values()->all();
                $this->assertSame($expectedNoticeIds, $actualNoticeIds);
            }
            $this->assertNotContains($foreign->id, $expectedIds);
        }
        $this->getJson('/api/mensajeria?section=quote_notices')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/mensajeria?section=quote_notices&search='.$waiting->folio)->assertOk()->assertJsonPath('total', 1)->assertJsonCount(2, 'data.0.notices');
        // Persistent new-request notices and operational fallbacks share Solicitudes nuevas without duplicates.
        $newRequests = $this->getJson('/api/mensajeria?section=new_requests')->assertOk()->assertJsonPath('total', 2);
        $this->assertEqualsCanonicalizing([$pending->id, $attention->id], collect($newRequests->json('data'))->pluck('id')->all());
        $this->getJson('/api/mensajeria?section=pending')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/mensajeria?section=history')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $sent->id);
        $this->getJson('/api/mensajeria?section=quote_notices&filter=unread&search='.$waiting->folio)->assertOk()->assertJsonCount(2, 'data.0.notices');
        $this->getJson('/api/mensajeria?section=pending&filter=reminders')->assertOk()->assertJsonPath('total', 2);
        AppSetting::current()->update(['unanswered_quote_days' => 5]);
        $this->getJson('/api/mensajeria?section=pending')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ready->id);
        $this->actingAs($sales);
        $this->getJson('/api/mensajeria?section=own_requests')->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('quote_conversations', 2);
        $this->assertDatabaseHas('sales_notifications', ['quote_id' => $waiting->id, 'message' => 'Aviso pendiente', 'read_at' => null]);
    }

    #[Test]
    public function messaging_and_bell_respect_dashboard_permission_for_operational_alerts(): void
    {
        $buyer = $this->demoUser('gerente_compras');
        $quote = $this->quote();
        $stale = $this->quote(); $stale->update(['status' => 'en_elaboracion', 'last_activity_at' => now()->subDays(8)]);
        $permissionIds = DB::table('permissions')->whereIn('module_id', DB::table('modules')->select('id')->where('slug', 'dashboard'))->pluck('id');
        DB::table('role_permissions')->where('role_id', $buyer->role_id)->whereIn('permission_id', $permissionIds)->delete();
        $this->actingAs($buyer);
        $bell = $this->getJson('/api/notificaciones')->assertOk();
        // Removing Dashboard permission suppresses operational alerts, but retains recipient notices.
        $bell->assertJsonCount(1, 'groups.new_requests')->assertJsonCount(0, 'groups.quote_notices')->assertJsonCount(0, 'groups.pending');
        $this->getJson('/api/mensajeria')->assertOk()->assertJsonPath('sections.new_requests', 1)->assertJsonPath('sections.pending', 0);
    }

    #[Test]
    public function sales_groups_owned_quotes_and_ready_to_send_without_requiring_a_bell_notice(): void
    {
        $sales = $this->demoUser('ventas');
        $own = $this->quote();
        $ready = $this->quote(); $ready->update(['status' => 'pendiente_envio']);
        $sent = $this->quote(); $sent->update(['status' => 'enviada', 'sent_at' => now()]);
        $otherSales = User::factory()->create(['role_id' => $sales->role_id, 'active' => true]);
        $foreign = $this->quote(); $foreign->update(['created_by' => $otherSales->id]);
        $reassigned = $this->quote(); $reassigned->update(['follow_up_assigned_to' => $otherSales->id]);
        $assigned = $this->quote(); $assigned->update(['created_by' => $this->demoUser('administrador')->id, 'follow_up_assigned_to' => $sales->id]);
        $this->actingAs($sales);
        $response = $this->getJson('/api/mensajeria')->assertOk()->assertJsonPath('total', 4)
            ->assertJsonPath('sections.own_quotes', 3)->assertJsonPath('sections.ready_to_send', 1)
            ->assertJsonPath('sections.own_requests', 0)->assertJsonPath('sections.quote_notices', 0);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($foreign->id, $ids); $this->assertNotContains($reassigned->id, $ids);
        $this->assertContains($assigned->id, $ids);
        $this->getJson('/api/mensajeria?section=ready_to_send')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ready->id);
        $this->postJson('/api/mensajeria/'.$own->id.'/abrir')->assertOk()->assertJsonPath('quote.canView', true)
            ->assertJsonPath('quote.canChat', true)->assertJsonCount(0, 'messages');
        $this->postJson('/api/mensajeria/'.$own->id.'/mensajes', ['text' => 'Antes de tener comprador', 'clientId' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseCount('quote_conversations', 1);
        $this->claim($own);
        $this->actingAs($sales);
        $this->postJson('/api/mensajeria/'.$own->id.'/mensajes', ['text' => 'Ya tenemos responsable.', 'clientId' => (string) Str::uuid()])->assertCreated();
        $ready->update(['sent_at' => now()]);
        $this->getJson('/api/mensajeria?section=ready_to_send')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/mensajeria?section=own_quotes')->assertOk()->assertJsonPath('total', 4);
    }

    #[Test]
    public function notifications_appear_in_the_quote_chat_and_reading_does_not_duplicate_or_clear_foreign_notices(): void
    {
        $quote = $this->quote(); $this->claim($quote);
        $sales = $this->demoUser('ventas'); $buyer = $this->demoUser('gerente_compras');
        $quote->update(['status' => 'pendiente_envio']);
        $first = SalesNotification::create(['quote_id' => $quote->id, 'recipient_id' => $sales->id, 'audience' => 'ventas',
            'reason_code' => 'lista_terminada', 'message' => 'Cotización lista para enviar.', 'sender_id' => $buyer->id]);
        $other = SalesNotification::create(['quote_id' => $quote->id, 'recipient_id' => $buyer->id, 'audience' => 'compras',
            'reason_code' => 'seguimiento_actualizado', 'message' => 'Aviso personal de Compras.']);
        $this->actingAs($sales);
        $detail = $this->getJson('/api/mensajeria/'.$quote->id)->assertOk()->assertJsonCount(1, 'notices')
            ->assertJsonPath('notices.0.id', $first->id)->assertJsonPath('notices.0.detail', 'Cotización lista para enviar.');
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('unreadCount', 2); // Opening event + ready notice.
        $this->postJson('/api/mensajeria/'.$quote->id.'/leer', ['noticeIds' => [$other->id]])->assertUnprocessable();
        $foreignQuote = $this->quote();
        $foreignNotice = SalesNotification::create(['quote_id' => $foreignQuote->id, 'recipient_id' => $sales->id,
            'audience' => 'ventas', 'reason_code' => 'en_elaboracion', 'message' => 'Otro folio.']);
        $this->postJson('/api/mensajeria/'.$quote->id.'/leer', ['noticeIds' => [$first->id, $foreignNotice->id]])->assertUnprocessable();
        $this->assertDatabaseHas('sales_notifications', ['id' => $first->id, 'read_at' => null]);
        $later = SalesNotification::create(['quote_id' => $quote->id, 'recipient_id' => $sales->id, 'audience' => 'ventas',
            'reason_code' => 'en_elaboracion', 'message' => 'Aviso posterior a la lectura de la pantalla.']);
        $this->postJson('/api/mensajeria/'.$quote->id.'/leer', ['lastId' => (int) $detail->json('messages.0.id'), 'noticeIds' => [$first->id]])
            ->assertOk()->assertJsonPath('unreadCount', 2);
        $this->getJson('/api/mensajeria/'.$quote->id)->assertOk()->assertJsonPath('notices.0.read', true);
        $this->getJson('/api/mensajeria/'.$quote->id)->assertOk();
        $this->assertDatabaseCount('quote_chat_messages', 1);
        $this->assertDatabaseHas('sales_notifications', ['id' => $other->id, 'read_at' => null]);
        $this->assertDatabaseHas('sales_notifications', ['id' => $later->id, 'read_at' => null]);
        $quote->update(['status' => 'enviada', 'sent_at' => now()]);
        $this->getJson('/api/mensajeria/'.$quote->id)->assertOk()->assertJsonPath('notices.0.id', $first->id);
    }

    #[Test]
    public function a_salesperson_can_read_their_notices_before_purchases_takes_the_quote(): void
    {
        $quote = $this->quote(); $sales = $this->demoUser('ventas');
        $notice = SalesNotification::create(['quote_id' => $quote->id, 'recipient_id' => $sales->id,
            'audience' => 'ventas', 'reason_code' => 'en_elaboracion', 'message' => 'Aviso en el seguimiento.']);
        $this->actingAs($sales);
        $this->postJson('/api/mensajeria/'.$quote->id.'/abrir')->assertOk()->assertJsonCount(1, 'notices');
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('unreadCount', 1);
        $this->postJson('/api/mensajeria/'.$quote->id.'/leer', ['noticeIds' => [$notice->id]])->assertOk()->assertJsonPath('unreadCount', 0);
        $this->assertDatabaseCount('quote_conversations', 1);
    }

    #[Test]
    public function bell_and_messaging_share_the_sixteen_pending_items_and_update_after_reading(): void
    {
        AppSetting::current()->update(['unanswered_quote_days' => 3]);
        $buyer = $this->demoUser('gerente_compras');
        for ($i = 0; $i < 4; $i++) {
            $request = $this->quote(); $this->claim($request);
            $stale = $this->quote(); $stale->update(['status' => 'en_elaboracion', 'last_activity_at' => now()->subDays(4)]);
        }
        for ($i = 0; $i < 8; $i++) {
            $quote = $this->quote(); $quote->update(['status' => 'en_elaboracion', 'purchase_assigned_to' => $buyer->id, 'last_activity_at' => now()]);
            $notice = SalesNotification::create(['quote_id' => $quote->id, 'recipient_id' => $buyer->id,
                'audience' => 'compras', 'reason_code' => 'en_elaboracion', 'message' => 'Aviso pendiente '.$i]);
        }
        $this->actingAs($buyer);
        $this->getJson('/api/notificaciones')->assertOk()->assertJsonPath('unreadCount', 8)->assertJsonPath('pendingCount', 16)
            ->assertJsonCount(4, 'purchaseRequests')->assertJsonCount(4, 'operational');
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('pendingCount', 16)->assertJsonPath('unreadCount', 16);
        $this->getJson('/api/mensajeria')->assertOk()->assertJsonPath('unreadCount', 16);
        $this->postJson('/api/mensajeria/'.$quote->id.'/leer', ['noticeIds' => [$notice->id]])->assertOk()->assertJsonPath('unreadCount', 15);
        $this->getJson('/api/notificaciones')->assertOk()->assertJsonPath('pendingCount', 15);
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('pendingCount', 15);
    }

    #[Test]
    public function both_counters_count_chat_messages_once_even_when_the_bell_has_one_grouped_notice(): void
    {
        $quote = $this->quote(); $this->claim($quote);
        $quote->update(['status' => 'en_elaboracion', 'last_activity_at' => now()]);
        $this->actingAsDemoUser('ventas');
        foreach (['Uno', 'Dos'] as $text) $last = $this->postJson('/api/mensajeria/'.$quote->id.'/mensajes',
            ['text' => $text, 'clientId' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->actingAsDemoUser('gerente_compras');
        $this->getJson('/api/notificaciones')->assertOk()->assertJsonPath('unreadCount', 1)->assertJsonPath('pendingCount', 2);
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('unreadCount', 2)->assertJsonPath('unreadMessageCount', 2);
        $this->postJson('/api/mensajeria/'.$quote->id.'/leer', ['lastId' => $last])->assertOk()->assertJsonPath('unreadCount', 0);
        $this->getJson('/api/notificaciones')->assertOk()->assertJsonPath('pendingCount', 0);
    }

    #[Test]
    public function pending_counter_counts_all_pages_and_preserves_shared_chat_notices(): void
    {
        $buyer = $this->demoUser('gerente_compras');
        $seller = $this->demoUser('ventas');
        for ($i = 0; $i < 31; $i++) {
            $quote = $this->quote();
            $this->claim($quote);
            $quote->update(['status' => 'en_elaboracion', 'last_activity_at' => now()->subDays(4)]);
            DB::table('quote_chat_messages')->insert(['quote_id' => $quote->id, 'sender_id' => $seller->id,
                'kind' => 'text', 'body' => "Pendiente {$i}", 'created_at' => now()]);
        }
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('unreadCount', 62)->assertJsonPath('unreadMessageCount', 31);
        $this->getJson('/api/mensajeria?section=pending&page=2')->assertOk()->assertJsonPath('total', 31)
            ->assertJsonCount(1, 'data')->assertJsonPath('sections.pending', 31);
        $this->getJson('/api/mensajeria?filter=unread&page=2')->assertOk()->assertJsonPath('total', 31)->assertJsonCount(1, 'data');
        $other = User::factory()->create(['role_id' => $buyer->role_id, 'active' => true]);
        $this->actingAs($other);
        // Shared conversations include the 31 opening events and 31 messages this buyer has not read.
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('unreadCount', 93)->assertJsonPath('unreadMessageCount', 62);
        $this->actingAs($buyer);
        $lastId = DB::table('quote_chat_messages')->where('quote_id', $quote->id)->max('id');
        $this->postJson("/api/mensajeria/{$quote->id}/leer", ['lastId' => $lastId])->assertOk();
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('unreadCount', 61)->assertJsonPath('unreadMessageCount', 30);
        $quote->update(['status' => 'pendiente_envio', 'purchase_assigned_to' => null]);
        DB::table('quote_chat_messages')->insert(['quote_id' => $quote->id, 'sender_id' => $seller->id,
            'kind' => 'text', 'body' => 'Seguimiento después de completar', 'created_at' => now()]);
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('unreadCount', 62)->assertJsonPath('unreadMessageCount', 31);
        $this->actingAs($seller);
        // Buyers' automatic opening events are unread; sellers' own messages are excluded.
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('unreadCount', 31);
        $otherSeller = User::factory()->create(['role_id' => $seller->role_id, 'active' => true]);
        $quote->update(['follow_up_assigned_to' => $otherSeller->id]);
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('unreadCount', 31);
        $quote->update(['follow_up_assigned_to' => $other->id]);
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('unreadCount', 31);
    }

    #[Test]
    public function read_is_personal_monotonic_and_does_not_clear_later_messages(): void
    {
        $quote = $this->quote(); $this->claim($quote);
        $this->actingAsDemoUser('ventas');
        $first = $this->postJson("/api/mensajeria/{$quote->id}/mensajes", ['text' => 'Primero', 'clientId' => (string) Str::uuid()])->assertCreated()->json('id');
        $second = $this->postJson("/api/mensajeria/{$quote->id}/mensajes", ['text' => 'Segundo', 'clientId' => (string) Str::uuid()])->assertCreated()->json('id');
        $this->actingAsDemoUser('gerente_compras');
        $this->postJson("/api/mensajeria/{$quote->id}/leer", ['lastId' => $first])->assertOk();
        $this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->assertJsonPath('quote.unreadCount', 1);
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('unreadCount', 1);
        $this->assertSame(1, DB::table('sales_notifications')->where('quote_id', $quote->id)->where('audience', 'compras')->where('reason_code', 'mensaje_chat')->whereNull('read_at')->count());
        $this->postJson("/api/mensajeria/{$quote->id}/leer", ['lastId' => $second])->assertOk();
        $this->postJson("/api/mensajeria/{$quote->id}/leer", ['lastId' => $first])->assertOk();
        $this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->assertJsonPath('quote.unreadCount', 0);
        $this->getJson('/api/mensajeria/pendientes')->assertOk()->assertJsonPath('unreadCount', 0);
        $other = $this->quote(); $this->claim($other);
        $foreign = DB::table('quote_chat_messages')->where('quote_id', $other->id)->value('id');
        $this->postJson("/api/mensajeria/{$quote->id}/leer", ['lastId' => $foreign])->assertUnprocessable();
    }

    #[Test]
    public function questions_answers_and_checks_respect_roles_and_commercial_validation(): void
    {
        $quote = $this->quote(); $this->claim($quote);
        $path = "/api/mensajeria/{$quote->id}/seguimiento";
        $this->postJson($path, ['action' => 'question', 'text' => '¿Se acepta un equivalente?'])->assertOk()->assertJsonPath('quote.stage', 'espera_ventas');
        $this->postJson($path, ['action' => 'resume', 'text' => 'No soy Ventas'])->assertConflict();
        $this->actingAsDemoUser('ventas');
        $this->postJson($path, ['action' => 'check', 'key' => 'stock', 'checked' => true])->assertConflict();
        $this->postJson($path, ['action' => 'resume', 'text' => 'Sí, se acepta.'])->assertOk()->assertJsonPath('quote.stage', 'en_proceso');
        $this->actingAsDemoUser('gerente_compras');
        $this->postJson($path, ['action' => 'ready'])->assertUnprocessable();
        foreach (['stock', 'price', 'delivery'] as $key) $this->postJson($path, ['action' => 'check', 'key' => $key, 'checked' => true])->assertOk();
        $this->postJson($path, ['action' => 'ready'])->assertUnprocessable();
        $this->assertSame('solicitud_cotizaciones', $quote->fresh()->status);
        $quote->update(['status' => 'pendiente_envio']);
        $this->postJson($path, ['action' => 'ready'])->assertOk()->assertJsonPath('quote.stage', 'lista');
        $this->actingAsDemoUser('ventas');
        $this->postJson($path, ['action' => 'sent'])->assertUnprocessable();
        $this->assertNull($quote->fresh()->sent_at);
        $quote->update(['sent_at' => now()]);
        $this->postJson($path, ['action' => 'close', 'text' => 'Seguimiento completado.'])->assertOk()->assertJsonPath('quote.stage', 'cerrada');
        $this->postJson("/api/mensajeria/{$quote->id}/mensajes", ['text' => 'Cerrado', 'clientId' => (string) Str::uuid()])->assertConflict();
    }

    #[Test]
    public function scheduled_followups_are_visible_in_messaging_without_an_unread_notification(): void
    {
        $quote = $this->quote(); $this->claim($quote);
        $quote->update(['status' => 'pendiente_envio']);
        $other = $this->quote(); $other->update(['status' => 'pendiente_envio']);
        $this->actingAsDemoUser('ventas');
        $path = "/api/cotizaciones/{$quote->id}/seguimiento";
        $firstDate = now()->addDays(2)->toDateString();
        $nextDate = now()->addDays(5)->toDateString();
        $this->patchJson($path, ['status' => 'negociacion', 'remindDate' => $firstDate])->assertOk();
        SalesNotification::query()->where('quote_id', $quote->id)->update(['read_at' => now()]);
        $this->getJson('/api/mensajeria?filter=reminders')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('reminderCount', 1)->assertJsonPath('data.0.id', $quote->id);
        $this->patchJson($path, ['status' => 'negociacion', 'remindDate' => $nextDate])->assertOk();
        $detail = $this->getJson("/api/cotizaciones/{$quote->id}")->assertOk()->assertJsonCount(2, 'followUpHistory');
        $this->assertSame($nextDate, substr($detail->json('followUp.remindAt'), 0, 10));
        $this->getJson('/api/mensajeria?filter=reminders')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $quote->id);
        $this->patchJson($path, ['status' => 'ganada', 'invoice' => 'FAC-CHAT-123'])->assertOk();
        $this->getJson("/api/cotizaciones/{$quote->id}")->assertOk()->assertJsonPath('followUp.status', 'ganada')
            ->assertJsonPath('followUp.invoice', 'FAC-CHAT-123')->assertJsonCount(3, 'followUpHistory');
        $this->getJson('/api/mensajeria?filter=reminders')->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('reminderCount', 0);
        $this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->assertJsonPath('quote.reminder', null);
    }

    #[Test]
    public function commercial_followups_keep_permissions_validation_and_history_in_messaging(): void
    {
        $quote = $this->quote(); $this->claim($quote);
        $quote->update(['status' => 'pendiente_envio']);
        $path = "/api/cotizaciones/{$quote->id}/seguimiento";
        $this->patchJson($path, ['status' => 'perdida', 'comments' => 'Eligió otro proveedor.'])->assertUnprocessable();
        $this->actingAsDemoUser('ventas');
        $this->patchJson($path, ['status' => 'negociacion', 'remindDate' => now()->subDay()->toDateString()])->assertUnprocessable();
        $this->patchJson($path, ['status' => 'ganada', 'invoice' => ''])->assertUnprocessable();
        $this->assertDatabaseCount('quote_follow_up_events', 0);
        $this->patchJson($path, ['status' => 'perdida', 'comments' => 'Eligió otro proveedor.'])->assertOk();
        $this->getJson("/api/cotizaciones/{$quote->id}")->assertOk()->assertJsonPath('followUp.status', 'perdida')
            ->assertJsonPath('followUp.comments', 'Eligió otro proveedor.')->assertJsonCount(1, 'followUpHistory');
        $this->actingAsDemoUser('gerente_compras');
        $detail = $this->getJson("/api/mensajeria/{$quote->id}")->assertOk();
        $this->assertTrue(collect($detail->json('notices'))->contains(fn ($notice) => str_contains($notice['detail'], 'Perdida')));
    }

    #[Test]
    public function history_is_paginated_and_lists_do_not_mark_conversations_read(): void
    {
        $quote = $this->quote(); $this->claim($quote);
        for ($i = 0; $i < 105; $i++) DB::table('quote_chat_messages')->insert(['quote_id' => $quote->id,
            'sender_id' => $this->demoUser('gerente_compras')->id, 'kind' => 'text', 'body' => "Mensaje {$i}", 'created_at' => now()]);
        $this->actingAsDemoUser('ventas');
        $this->getJson('/api/mensajeria')->assertOk();
        $this->assertDatabaseCount('quote_chat_reads', 0);
        $response = $this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->assertJsonCount(100, 'messages')->assertJsonPath('hasMore', true);
        $first = $response->json('messages.0.id');
        $this->getJson("/api/mensajeria/{$quote->id}?before={$first}")->assertOk()->assertJsonPath('hasMore', false);
    }
}
