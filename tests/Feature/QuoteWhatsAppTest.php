<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteInternalNote;
use Illuminate\Support\Facades\Http;
use Tests\AuthenticatedFeatureTestCase;

class QuoteWhatsAppTest extends AuthenticatedFeatureTestCase
{
    private function quote(string $status = 'pendiente_envio'): Quote
    {
        $client = Client::query()->create(['company' => 'Cliente de prueba', 'whatsapp' => '5512345678']);
        $quote = Quote::query()->create([
            'folio' => 'COT-WA-001', 'client_id' => $client->id,
            'status' => $status, 'created_by' => auth()->id(),
        ]);
        $quote->lines()->create([
            'line_order' => 1, 'quantity' => 1, 'product' => 'Producto de prueba',
            'cost' => 100, 'margin_percent' => 30, 'sale_price' => 130, 'amount' => 130,
        ]);

        return $quote;
    }

    public function test_failed_meta_send_does_not_mark_quote_as_sent(): void
    {
        config(['whatsapp.access_token' => 'fake', 'whatsapp.phone_number_id' => '123', 'whatsapp.waba_id' => '456']);
        Http::preventStrayRequests();
        Http::fake(['*/message_templates*' => Http::response(['data' => []])]);
        $quote = $this->quote();
        $this->postJson('/api/cotizaciones/'.$quote->id.'/enviar-whatsapp', ['template' => 'cotizacion_lista'])->assertUnprocessable();
        $this->assertSame('pendiente_envio', $quote->fresh()->status);
        $this->assertNull($quote->fresh()->sent_at);
    }

    public function test_cannot_send_an_unfinished_quote(): void
    {
        Http::fake();
        $quote = $this->quote('en_elaboracion');
        $this->postJson('/api/cotizaciones/'.$quote->id.'/enviar-whatsapp', ['template' => 'cotizacion_lista'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_records_signed_delivery_status_once(): void
    {
        $quote = $this->quote('enviada');
        QuoteInternalNote::query()->create([
            'quote_id' => $quote->id, 'user_id' => auth()->id(), 'created_at' => now(),
            'body' => 'WhatsApp: Meta aceptó el envío de cotizacion_lista. Mensaje: wamid.test.',
        ]);
        config(['whatsapp.app_secret' => 'test-secret', 'whatsapp.phone_number_id' => '123']);
        $body = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '123'],
            'statuses' => [['id' => 'wamid.test', 'status' => 'delivered']],
        ]]]]]]);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret')];
        $this->call('POST', '/api/whatsapp/webhook', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/whatsapp/webhook', [], [], [], $headers, $body)->assertOk();
        $this->assertSame(1, QuoteInternalNote::query()->where('quote_id', $quote->id)->where('body', 'WhatsApp: mensaje wamid.test — entregado.')->count());
    }
}
