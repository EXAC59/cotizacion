<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\SalesNotification;
use App\Models\User;
use App\Services\Sales\SalesNotificationService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class PurchaseRequestWorkflowTest extends AuthenticatedFeatureTestCase
{
    private function pendingQuote(): Quote
    {
        $client = Client::query()->create([
            'company' => 'Cliente Flujo Compras',
            'rfc' => 'CFC010101AAA',
        ]);

        return Quote::query()->create([
            'folio' => 'COT-COMPRAS-'.uniqid(),
            'client_id' => $client->id,
            'status' => 'solicitud_cotizaciones',
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function only_one_compras_user_can_take_a_request_and_release_is_logged(): void
    {
        $first = $this->demoUser('gerente_compras');
        $second = User::factory()->create([
            'role_id' => $first->role_id,
            'active' => true,
        ]);
        $quote = $this->pendingQuote();

        $this->actingAs($first);
        $this->postJson("/api/cotizaciones/{$quote->id}/compras/tomar")
            ->assertOk()
            ->assertJsonPath('purchaseAttentionStatus', 'en_atencion')
            ->assertJsonPath('purchaseAssignedToViewer', true);

        $this->actingAs($second);
        $this->postJson("/api/cotizaciones/{$quote->id}/compras/tomar")
            ->assertStatus(422)
            ->assertJsonValidationErrors('quoteId');

        $this->actingAs($first);
        $this->deleteJson("/api/cotizaciones/{$quote->id}/compras/responsable")
            ->assertOk()
            ->assertJsonPath('purchaseAttentionStatus', 'disponible');

        $this->assertDatabaseHas('quote_internal_notes', [
            'quote_id' => $quote->id,
        ]);
    }

    #[Test]
    public function stale_unclaimed_requests_are_escalated_to_administration_once(): void
    {
        $quote = $this->pendingQuote();
        $createdAt = now()->subDays(5);
        $quote->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        $this->artisan('sales:escalate-purchase-requests')->assertSuccessful();
        $this->artisan('sales:escalate-purchase-requests')->assertSuccessful();

        $admin = $this->demoUser('administrador');
        $this->assertDatabaseHas('sales_notifications', [
            'quote_id' => $quote->id,
            'recipient_id' => $admin->id,
            'audience' => SalesNotificationService::AUDIENCE_COMPRAS,
            'reason_code' => SalesNotificationService::REASON_SOLICITUD_COMPRAS_URGENTE,
        ]);
        $this->assertSame(
            1,
            SalesNotification::query()
                ->where('quote_id', $quote->id)
                ->where('reason_code', SalesNotificationService::REASON_SOLICITUD_COMPRAS_URGENTE)
                ->count(),
        );
        $this->assertNotNull($quote->fresh()->purchase_escalated_at);
    }
}
