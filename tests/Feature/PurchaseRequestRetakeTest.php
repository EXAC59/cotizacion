<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\AuthenticatedFeatureTestCase;

class PurchaseRequestRetakeTest extends AuthenticatedFeatureTestCase
{
    private function requestQuote(): Quote
    {
        return Quote::create(['folio' => 'COT-TAKE-'.Str::random(6),
            'client_id' => Client::firstOrCreate(['company' => 'Attention client'])->id,
            'created_by' => $this->demoUser('ventas')->id, 'status' => 'solicitud_cotizaciones']);
    }

    public function test_any_buyer_can_resume_an_unlocked_request_and_advance_it(): void
    {
        config(['messaging.enabled' => true]);
        $quote = $this->requestQuote();
        $quote->update(['purchase_assigned_to' => $this->demoUser('gerente_compras')->id]);
        $buyer = User::factory()->create(['role_id' => $this->demoUser('gerente_compras')->role_id, 'active' => true]);
        $this->actingAs($buyer);
        $this->postJson("/api/cotizaciones/{$quote->id}/compras/tomar")->assertOk()->assertJsonPath('purchaseAssignedToViewer', true);
        $this->assertDatabaseHas('quotes', ['id' => $quote->id, 'purchase_assigned_to' => $buyer->id, 'locked_by' => $buyer->id]);
        $this->getJson("/api/cotizaciones/{$quote->id}")->assertOk()->assertJsonPath('ownedByViewer', true);
        $this->postJson('/api/cotizaciones', ['id' => $quote->id, 'clientId' => $quote->client_id,
            'status' => 'en_elaboracion', 'taxPercent' => 16,
            'lines' => [['product' => 'Equipo listo para cotizar', 'quantity' => 1, 'cost' => 100, 'marginPercent' => 10]],
        ])->assertCreated()->assertJsonPath('status', 'en_elaboracion');
        $this->assertSame($this->demoUser('ventas')->id, $quote->fresh()->created_by);
        $this->assertSame($quote->folio, $quote->fresh()->folio);
    }

    public function test_a_buyer_cannot_replace_someone_who_is_actively_editing(): void
    {
        $quote = $this->requestQuote();
        $first = $this->demoUser('gerente_compras');
        $quote->update(['purchase_assigned_to' => $first->id, 'locked_by' => $first->id, 'locked_at' => now()]);
        $next = User::factory()->create(['role_id' => $first->role_id, 'active' => true]);
        $this->actingAs($next);
        $this->postJson("/api/cotizaciones/{$quote->id}/compras/tomar")->assertStatus(423);
        $this->assertSame($first->id, $quote->fresh()->purchase_assigned_to);
        $quote->update(['locked_at' => now()->subHour()]);
        $this->postJson("/api/cotizaciones/{$quote->id}/compras/tomar")->assertOk();
        $this->assertSame($next->id, $quote->fresh()->purchase_assigned_to);
    }

    public function test_buyer_can_take_an_admin_created_request_and_the_team_gets_a_chat_notice(): void
    {
        config(['messaging.enabled' => true]);
        $quote = $this->requestQuote();
        $quote->update(['created_by' => $this->demoUser('administrador')->id]);
        $this->actingAsDemoUser('gerente_compras');
        $this->postJson("/api/cotizaciones/{$quote->id}/compras/tomar")->assertOk();
        $this->assertDatabaseHas('quote_conversations', ['quote_id' => $quote->id]);
        $this->assertDatabaseHas('sales_notifications', ['quote_id' => $quote->id,
            'recipient_id' => $this->demoUser('ventas')->id, 'reason_code' => 'mensaje_chat']);
        $this->assertNull(app(\App\Services\Quotes\QuoteMessagingService::class)->sales($quote));
        $this->actingAsDemoUser('ventas');
        $this->getJson("/api/mensajeria/{$quote->id}")->assertOk()->assertJsonPath('quote.canChat', true);
    }
}
