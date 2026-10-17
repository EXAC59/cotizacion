<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AuthenticatedFeatureTestCase;

class QuoteClosingResultTest extends AuthenticatedFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['messaging.enabled' => true]);
    }

    private function sentQuote(): Quote
    {
        $quote = Quote::create(['folio' => 'COT-CLOSE-'.Str::random(6),
            'client_id' => Client::firstOrCreate(['company' => 'Closing client'])->id,
            'created_by' => $this->demoUser('ventas')->id,
            'purchase_assigned_to' => $this->demoUser('gerente_compras')->id,
            'status' => 'enviada', 'sent_at' => now(), 'subtotal' => 300, 'tax_amount' => 48, 'tax_percent' => 16, 'total' => 348]);
        foreach ([100, 200] as $index => $amount) {
            $quote->lines()->create(['line_order' => $index, 'quantity' => 1, 'product' => 'Product '.$index,
                'part_number' => 'SKU-'.$index, 'cost' => 50, 'sale_price' => $amount, 'amount' => $amount]);
        }
        $this->actingAsDemoUser('ventas');
        return $quote;
    }

    private function payload(Quote $quote, array $data = []): array
    {
        $detail = $this->getJson("/api/cotizaciones/{$quote->id}/cierre")->assertOk()->json();
        return [...['status' => 'ganada', 'invoice' => 'FACT-001', 'version' => $detail['version'],
            'quoteVersion' => $detail['quoteVersion'], 'requestId' => (string) Str::uuid()], ...$data];
    }

    public function test_all_results_are_saved_and_announced_in_messaging_without_changing_the_quotation(): void
    {
        foreach (['ganada', 'perdida', 'negociacion', 'ganada_parcialmente'] as $status) {
            $quote = $this->sentQuote();
            $line = $quote->lines()->first();
            $payload = $this->payload($quote, ['status' => $status, 'comments' => 'Comentario del cliente', 'selectedLineIds' => [$line->id]]);
            $this->patchJson("/api/cotizaciones/{$quote->id}/cierre", $payload)->assertOk()
                ->assertJsonPath('status', $status)->assertJsonPath('version', 1)->assertJsonPath('total', 348);
            $this->assertDatabaseHas('quote_closing_results', ['quote_id' => $quote->id, 'status' => $status]);
            $this->assertDatabaseHas('quote_chat_messages', ['quote_id' => $quote->id, 'kind' => 'system']);
            $this->assertDatabaseHas('sales_notifications', ['quote_id' => $quote->id,
                'recipient_id' => $this->demoUser('gerente_compras')->id, 'reason_code' => 'cierre_cotizacion', 'read_at' => null]);
            $this->assertSame('enviada', $quote->fresh()->status);
            $this->assertNull($quote->fresh()->follow_up_status);
            $this->assertSame(2, $quote->lines()->count());
            $this->getJson('/api/cotizaciones')->assertOk()->assertJsonFragment(['closingStatus' => $status]);
            $this->actingAsDemoUser('gerente_compras');
            $detail = $this->getJson("/api/mensajeria/{$quote->id}")->assertOk();
            $this->assertStringContainsString('cambió el estatus de cierre', collect($detail->json('messages'))->pluck('text')->implode(' '));
            if ($status === 'ganada_parcialmente') {
                $this->assertSame('FACT-001', $quote->fresh()->closingResult->invoice);
                $this->assertStringContainsString('Factura: FACT-001', collect($detail->json('messages'))->pluck('text')->implode(' '));
                $this->getJson("/api/cotizaciones/{$quote->id}/cierre")->assertOk()->assertJsonPath('invoice', 'FACT-001');
                $this->assertSame($line->id, $quote->fresh()->closingResult->selected_lines[0]['id']);
                $this->assertSame('Product 0', $quote->fresh()->closingResult->selected_lines[0]['product']);
            }
        }
    }

    public function test_invoice_reason_and_negotiation_explanation_are_required(): void
    {
        $quote = $this->sentQuote();
        foreach (['ganada' => 'invoice', 'ganada_parcialmente' => 'invoice', 'perdida' => 'comments', 'negociacion' => 'comments'] as $status => $field) {
            $this->patchJson("/api/cotizaciones/{$quote->id}/cierre", $this->payload($quote, [
                'status' => $status, 'invoice' => '   ', 'comments' => '   ',
            ]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('quote_closing_results', 0);
        $this->assertDatabaseCount('quote_chat_messages', 0);
    }

    public function test_partial_wins_reject_no_lines_foreign_lines_duplicates_and_all_lines(): void
    {
        $quote = $this->sentQuote();
        $other = $this->sentQuote();
        foreach ([[], [$other->lines()->first()->id], [$quote->lines()->first()->id, $quote->lines()->first()->id], $quote->lines()->pluck('id')->all()] as $ids) {
            $response = $this->patchJson("/api/cotizaciones/{$quote->id}/cierre", $this->payload($quote, [
                'status' => 'ganada_parcialmente', 'selectedLineIds' => $ids,
            ]))->assertUnprocessable();
            $this->assertNotEmpty($response->json('errors'));
        }
        $this->assertDatabaseCount('quote_closing_results', 0);
    }

    public function test_retries_are_idempotent_and_stale_saves_cannot_overwrite_a_result(): void
    {
        $quote = $this->sentQuote();
        $payload = $this->payload($quote);
        $url = "/api/cotizaciones/{$quote->id}/cierre";
        $this->patchJson($url, $payload)->assertOk();
        $count = DB::table('quote_chat_messages')->count();
        $this->patchJson($url, $payload)->assertOk()->assertJsonPath('version', 1);
        $this->assertDatabaseCount('quote_chat_messages', $count);
        $this->patchJson($url, [...$payload, 'requestId' => (string) Str::uuid()])->assertConflict();
        $this->patchJson($url, $this->payload($quote, ['status' => 'negociacion', 'comments' => 'Revisar condiciones']))
            ->assertOk()->assertJsonPath('version', 2)->assertJsonPath('invoice', '');
        $this->assertDatabaseHas('quote_closing_results', ['quote_id' => $quote->id, 'invoice' => null, 'status' => 'negociacion']);
    }

    public function test_changed_line_prices_require_reopening_the_panel(): void
    {
        $quote = $this->sentQuote();
        $payload = $this->payload($quote);
        $quote->forceFill(['updated_at' => now()->addMinute()])->saveQuietly();
        $this->patchJson("/api/cotizaciones/{$quote->id}/cierre", $payload)->assertConflict();
    }

    public function test_only_purchasing_the_responsible_seller_or_admin_can_save_and_only_after_sending(): void
    {
        $quote = $this->sentQuote();
        $payload = $this->payload($quote);
        $url = "/api/cotizaciones/{$quote->id}/cierre";
        $this->actingAsDemoUser('gerente_compras');
        $this->getJson($url)->assertOk()->assertJsonPath('canEdit', true);
        $foreign = User::factory()->create(['role_id' => $this->demoUser('ventas')->role_id, 'active' => true]);
        $this->actingAs($foreign);
        $this->patchJson($url, $payload)->assertForbidden();
        $this->actingAsDemoUser('administrador');
        $this->patchJson($url, $payload)->assertOk();
        $quote->update(['sent_at' => null, 'status' => 'pendiente_envio']);
        $this->getJson($url)->assertUnprocessable();
        $this->patchJson($url, $payload)->assertForbidden();
    }

    public function test_purchasing_can_register_a_close_even_when_another_buyer_is_assigned(): void
    {
        $quote = $this->sentQuote();
        $buyer = User::factory()->create(['role_id' => $this->demoUser('gerente_compras')->role_id, 'active' => true]);
        $this->actingAs($buyer);
        $this->getJson("/api/cotizaciones/{$quote->id}/cierre")->assertOk()->assertJsonPath('canEdit', true);
        $this->patchJson("/api/cotizaciones/{$quote->id}/cierre", $this->payload($quote))->assertOk()->assertJsonPath('status', 'ganada');
        $this->assertSame($this->demoUser('gerente_compras')->id, $quote->fresh()->purchase_assigned_to);
        $this->assertDatabaseHas('sales_notifications', ['quote_id' => $quote->id,
            'recipient_id' => $this->demoUser('ventas')->id, 'reason_code' => 'cierre_cotizacion']);
    }

    public function test_only_purchasing_and_admin_receive_profit_and_margin(): void
    {
        $quote = $this->sentQuote();
        $url = "/api/cotizaciones/{$quote->id}/cierre";
        foreach (['gerente_compras', 'administrador'] as $role) {
            $this->actingAsDemoUser($role);
            $this->getJson($url)->assertOk()->assertJsonPath('financials.profit', 200)
                ->assertJsonPath('financials.marginPercent', 200)
                ->assertJsonPath('lines.0.financials.profit', 50)->assertJsonPath('lines.0.financials.marginPercent', 100)
                ->assertJsonPath('lines.1.financials.profit', 150)->assertJsonPath('lines.1.financials.marginPercent', 300);
        }
        $this->actingAsDemoUser('ventas');
        foreach ([$this->getJson($url.'?includeCosts=1'), $this->patchJson($url, $this->payload($quote))] as $response) {
            $response->assertOk()->assertJsonMissingPath('financials')->assertJsonMissingPath('lines.0.financials')
                ->assertJsonMissingPath('lines.0.cost')->assertJsonMissingPath('lines.0.marginPercent')
                ->assertJsonPath('lines.0.salePrice', 100)->assertJsonPath('taxAmount', 48)->assertJsonPath('total', 348);
        }
    }

    public function test_financials_handle_quantities_and_zero_cost_without_division_by_zero(): void
    {
        $quote = $this->sentQuote();
        $quote->lines()->orderBy('line_order')->first()->update(['quantity' => 2, 'sale_price' => 100, 'amount' => 200]);
        $quote->lines()->reorder('line_order', 'desc')->first()->update(['cost' => 0]);
        $this->actingAsDemoUser('gerente_compras');
        $this->getJson("/api/cotizaciones/{$quote->id}/cierre")->assertOk()
            ->assertJsonPath('financials.profit', 300)->assertJsonPath('financials.marginPercent', 300)
            ->assertJsonPath('lines.0.financials.profit', 100)->assertJsonPath('lines.1.financials.marginPercent', 0);
    }

    public function test_closing_notices_reach_offline_team_members_and_past_participants(): void
    {
        $quote = $this->sentQuote();
        $seller = User::factory()->create(['role_id' => $this->demoUser('ventas')->role_id, 'active' => true]);
        $buyer = User::factory()->create(['role_id' => $this->demoUser('gerente_compras')->role_id, 'active' => true]);
        $inactive = User::factory()->create(['role_id' => $buyer->role_id, 'active' => false]);
        $admin = $this->demoUser('administrador');
        DB::table('quote_chat_messages')->insert(['quote_id' => $quote->id, 'sender_id' => $admin->id,
            'kind' => 'text', 'body' => 'Participación anterior', 'created_at' => now()]);
        $this->patchJson("/api/cotizaciones/{$quote->id}/cierre", $this->payload($quote))->assertOk();
        foreach ([$seller, $buyer, $admin] as $recipient) {
            $this->assertDatabaseHas('sales_notifications', ['quote_id' => $quote->id, 'recipient_id' => $recipient->id,
                'reason_code' => 'cierre_cotizacion', 'read_at' => null]);
            $this->actingAs($recipient);
            $this->getJson('/api/notificaciones')->assertOk()->assertJsonFragment(['reasonCode' => 'cierre_cotizacion']);
            $this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->assertJsonPath('quote.canChat', true);
            $this->getJson('/api/mensajeria?mode=conversations&search='.$quote->folio)->assertOk()->assertJsonPath('total', 1);
        }
        $this->assertDatabaseMissing('sales_notifications', ['quote_id' => $quote->id, 'recipient_id' => $inactive->id]);
        $this->assertSame($this->demoUser('ventas')->id, app(\App\Services\Quotes\QuoteMessagingService::class)->sales($quote)->id);
    }

    public function test_new_chat_messages_notify_members_who_never_opened_the_chat(): void
    {
        $quote = $this->sentQuote();
        $seller = User::factory()->create(['role_id' => $this->demoUser('ventas')->role_id, 'active' => true]);
        $buyer = User::factory()->create(['role_id' => $this->demoUser('gerente_compras')->role_id, 'active' => true]);
        $this->postJson("/api/mensajeria/{$quote->id}/mensajes", ['text' => 'Aviso compartido para el equipo', 'clientId' => (string) Str::uuid()])->assertCreated();
        foreach ([$seller, $buyer] as $recipient) {
            $this->assertDatabaseHas('sales_notifications', ['quote_id' => $quote->id, 'recipient_id' => $recipient->id, 'reason_code' => 'mensaje_chat', 'read_at' => null]);
            $this->actingAs($recipient);
            $this->getJson('/api/notificaciones')->assertOk()->assertJsonFragment(['reasonCode' => 'mensaje_chat']);
            $this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->assertJsonPath('quote.canChat', true);
        }
    }

    public function test_status_changes_notify_both_teams_and_keep_the_transition_history(): void
    {
        $quote = $this->sentQuote();
        $buyer = User::factory()->create(['role_id' => $this->demoUser('gerente_compras')->role_id, 'active' => true]);
        $seller = User::factory()->create(['role_id' => $this->demoUser('ventas')->role_id, 'active' => true]);
        $url = "/api/cotizaciones/{$quote->id}/cierre";
        $firstChange = 'de Enviada al cliente a Negociación';
        $secondChange = 'de Negociación a Ganada';
        $this->patchJson($url, $this->payload($quote, ['status' => 'negociacion', 'comments' => 'Confirmar entrega']))->assertOk();
        $firstTime = DB::table('sales_notifications')->where('quote_id', $quote->id)->where('recipient_id', $buyer->id)->value('created_at');
        $this->travel(1)->hours();
        $payload = $this->payload($quote);
        $this->patchJson($url, $payload)->assertOk();
        $this->patchJson($url, $payload)->assertOk();
        $this->assertDatabaseCount('quote_chat_messages', 2);
        foreach ([$buyer, $seller] as $recipient) {
            $this->actingAs($recipient);
            $notice = collect($this->getJson('/api/notificaciones')->assertOk()->json('data'))
                ->first(fn ($item) => ($item['quoteId'] ?? null) === $quote->id && $item['reasonCode'] === 'cierre_cotizacion');
            $this->assertNotNull($notice);
            $this->assertFalse($notice['read']);
            $this->assertStringContainsString($secondChange, $notice['message']);
            $this->assertStringContainsString('FACT-001', $notice['message']);
            $this->assertTrue(\Illuminate\Support\Carbon::parse($notice['createdAt'])->gt($firstTime));
            $chat = $this->getJson("/api/mensajeria/{$quote->id}")->assertOk();
            $messages = collect($chat->json('messages'))->pluck('text')->implode(' ');
            $this->assertStringContainsString($firstChange, $messages);
            $this->assertStringContainsString($secondChange, $messages);
            $this->getJson('/api/mensajeria?section=all&filter=unread&search='.$quote->folio)
                ->assertOk()->assertJsonPath('total', 1);
            $this->assertSame(2, app(\App\Services\Quotes\QuoteMessagingService::class)->unreadMessageCounts($recipient)[$quote->id] ?? 0);
            $this->patchJson('/api/notificaciones/'.$notice['id'].'/leer')->assertOk();
        }
        $this->assertSame($this->demoUser('gerente_compras')->id, $quote->fresh()->purchase_assigned_to);
    }

    public function test_updating_details_without_a_status_change_says_what_was_updated(): void
    {
        $quote = $this->sentQuote();
        $url = "/api/cotizaciones/{$quote->id}/cierre";
        $this->patchJson($url, $this->payload($quote))->assertOk();
        $this->patchJson($url, $this->payload($quote, ['invoice' => 'FACT-002']))->assertOk();
        $this->actingAsDemoUser('gerente_compras');
        $messages = collect($this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->json('messages'))->pluck('text')->all();
        $this->assertStringContainsString('actualizó los datos del cierre', $messages[1]);
        $this->assertStringContainsString('FACT-002', $messages[1]);
        $this->assertStringNotContainsString('de Ganada a Ganada', $messages[1]);
    }
}
