<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\AuthenticatedFeatureTestCase;

class QuoteCompletionPricingTest extends AuthenticatedFeatureTestCase
{
    private function payload(string $status = 'pendiente_envio'): array
    {
        return [
            'clientId' => Client::firstOrCreate(['company' => 'Cliente precios'], ['email' => 'cliente@example.test'])->id,
            'status' => $status,
            'lines' => [
                ['product' => 'Producto cotizado', 'quantity' => 1, 'cost' => 100, 'marginPercent' => 30],
                ['product' => 'Producto sin precio', 'quantity' => 5, 'cost' => 0, 'marginPercent' => 30],
            ],
        ];
    }

    public function test_completion_rejects_any_zero_price_even_with_a_positive_total_or_spoofed_sale_price(): void
    {
        foreach ([
            ['cost' => 0],
            ['cost' => 0, 'salePrice' => 1000, 'amount' => 5000],
            ['cost' => 0.00001, 'marginPercent' => 0],
        ] as $invalidLine) {
            $payload = $this->payload();
            $payload['lines'][1] = [...$payload['lines'][1], ...$invalidLine];
            $this->postJson('/api/cotizaciones', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('lines.1.salePrice');
        }
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_incomplete_drafts_remain_saveable_but_completion_requires_pricing_every_line(): void
    {
        foreach (['solicitud_cotizaciones', 'en_elaboracion'] as $status) {
            $this->postJson('/api/cotizaciones', $this->payload($status))->assertCreated()
                ->assertJsonPath('status', $status)->assertJsonPath('lines.1.salePrice', 0);
        }

        $payload = $this->payload('en_elaboracion');
        $id = $this->postJson('/api/cotizaciones', $payload)->assertCreated()->json('id');
        $this->postJson("/api/cotizaciones/$id/bloqueo")->assertOk();
        $this->postJson('/api/cotizaciones', [...$payload, 'id' => $id, 'status' => 'pendiente_envio'])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.1.salePrice');
        $this->assertSame('en_elaboracion', Quote::findOrFail($id)->status);
        $this->assertSame(0.0, (float) Quote::findOrFail($id)->lines()->orderBy('line_order')->get()[1]->sale_price);

        $payload['lines'][1]['cost'] = 200;
        $this->postJson('/api/cotizaciones', [...$payload, 'id' => $id, 'status' => 'pendiente_envio'])
            ->assertCreated()->assertJsonPath('status', 'pendiente_envio')->assertJsonPath('lines.1.salePrice', 260);
    }

    public function test_completed_statuses_cannot_bypass_prices_and_a_completed_quote_needs_lines(): void
    {
        foreach (['enviada', 'aceptada', 'facturada'] as $status) {
            $this->postJson('/api/cotizaciones', [...$this->payload($status), 'invoiceNumber' => 'FAC-001'])
                ->assertUnprocessable()->assertJsonValidationErrors('lines.1.salePrice');
        }
        $this->postJson('/api/cotizaciones', [...$this->payload(), 'lines' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_derived_and_manual_positive_prices_can_complete_a_quote(): void
    {
        $payload = $this->payload();
        $payload['lines'][1] = [...$payload['lines'][1], 'usesGlobalMargin' => false, 'salePrice' => 50];
        $this->postJson('/api/cotizaciones', $payload)->assertCreated()
            ->assertJsonPath('status', 'pendiente_envio')->assertJsonPath('lines.0.salePrice', 130)
            ->assertJsonPath('lines.1.salePrice', 50)->assertJsonPath('subtotal', 380);
    }

    public function test_existing_zero_priced_quotes_cannot_be_sent_by_email_or_whatsapp(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $id = $this->postJson('/api/cotizaciones', $this->payload('en_elaboracion'))->assertCreated()->json('id');
        // Simulate an old record that was marked ready without the new guard.
        $quote = Quote::findOrFail($id);
        $quote->update(['status' => 'pendiente_envio']);
        $this->postJson("/api/cotizaciones/$id/enviar", ['to' => 'cliente@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.1.salePrice');
        $this->postJson("/api/cotizaciones/$id/enviar-whatsapp", ['template' => 'cotizacion_lista'])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.1.salePrice');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame('pendiente_envio', $quote->fresh()->status);
        $this->assertNull($quote->fresh()->sent_at);
    }
}
