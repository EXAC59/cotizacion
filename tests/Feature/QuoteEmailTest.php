<?php

namespace Tests\Feature;

use App\Jobs\SendQuoteEmailJob;
use App\Mail\QuoteSentMail;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\Quote;
use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerCatalogSync;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class QuoteEmailTest extends AuthenticatedFeatureTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        app(WholesalerCatalogSync::class)->sync();
        Wholesaler::query()->update(['active' => true]);
    }

    /**
     * @return array{client: Client, quoteId: string}
     */
    private function createQuoteWithClientEmail(string $email = 'cliente@acme.test'): array
    {
        $client = Client::query()->create([
            'company' => 'Acme SA',
            'rfc' => 'ACM010101ABC',
            'email' => $email,
        ]);

        AppSetting::current()->update([
            'company_name' => 'Exacto LP',
            'company_email' => 'ventas@exacto.test',
            'quote_signature_email' => 'firma@exacto.test',
        ]);

        $create = $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-MAIL-0001',
            'clientId' => $client->id,
            'status' => 'en_elaboracion',
            'validityDays' => 15,
            'globalMarginPercent' => 30,
            'taxPercent' => 16,
            'lines' => [
                [
                    'quantity' => 2,
                    'product' => 'Switch Cisco',
                    'partNumber' => 'C9200L-24T-4G-E',
                    'cost' => 1000,
                    'marginPercent' => 30,
                    'salePrice' => 1300,
                    'amount' => 2600,
                    'warehouse' => 'CDMX',
                ],
            ],
        ]);

        $create->assertCreated();

        return [
            'client' => $client,
            'quoteId' => (string) $create->json('id'),
        ];
    }

    #[Test]
    public function it_queues_email_send_for_saved_quote(): void
    {
        Queue::fake();

        ['quoteId' => $quoteId] = $this->createQuoteWithClientEmail();

        $response = $this->postJson("/api/cotizaciones/{$quoteId}/enviar", [
            'message' => 'Adjuntamos su cotización.',
        ]);

        $response->assertAccepted();
        $response->assertJson([
            'queued' => true,
            'message' => 'Cotización encolada para envío por correo.',
        ]);

        Queue::assertPushed(SendQuoteEmailJob::class, function (SendQuoteEmailJob $job) use ($quoteId) {
            return $job->quoteId === $quoteId
                && $job->toEmail === 'cliente@acme.test'
                && $job->message === 'Adjuntamos su cotización.';
        });
    }

    #[Test]
    public function job_sends_mail_with_pdf_and_marks_quote_as_sent(): void
    {
        Mail::fake();

        ['quoteId' => $quoteId] = $this->createQuoteWithClientEmail('compras@acme.test');

        $job = new SendQuoteEmailJob($quoteId, 'compras@acme.test', 'Asunto test', 'Hola');
        $job->handle(app(\App\Services\Quotes\QuotePdfService::class));

        Mail::assertSent(QuoteSentMail::class, function (QuoteSentMail $mail) {
            return count($mail->attachments()) === 1;
        });

        $quote = Quote::query()->findOrFail($quoteId);
        $this->assertSame('enviada', $quote->status);
        $this->assertNotNull($quote->sent_at);
    }

    #[Test]
    public function it_rejects_send_when_quote_has_no_lines(): void
    {
        $client = Client::query()->create([
            'company' => 'Vacía SA',
            'rfc' => 'VAC010101ABC',
            'email' => 'vacia@test.com',
        ]);

        $create = $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-EMPTY-001',
            'clientId' => $client->id,
            'status' => 'en_elaboracion',
            'lines' => [],
        ]);

        $create->assertCreated();
        $quoteId = (string) $create->json('id');

        $this->postJson("/api/cotizaciones/{$quoteId}/enviar")
            ->assertUnprocessable()
            ->assertJsonFragment([
                'message' => 'La cotización no tiene partidas para enviar.',
            ]);
    }

    #[Test]
    public function it_rejects_send_when_client_has_no_email_and_to_is_missing(): void
    {
        ['quoteId' => $quoteId] = $this->createQuoteWithClientEmail('');

        $this->postJson("/api/cotizaciones/{$quoteId}/enviar")
            ->assertUnprocessable()
            ->assertJsonFragment([
                'message' => 'El cliente no tiene correo electrónico. Indica un destinatario.',
            ]);
    }

    #[Test]
    public function it_accepts_override_recipient_when_client_has_no_email(): void
    {
        Queue::fake();

        ['quoteId' => $quoteId] = $this->createQuoteWithClientEmail('');

        $this->postJson("/api/cotizaciones/{$quoteId}/enviar", [
            'to' => 'override@acme.test',
        ])->assertAccepted();

        Queue::assertPushed(SendQuoteEmailJob::class, fn (SendQuoteEmailJob $job) => $job->toEmail === 'override@acme.test');
    }

    #[Test]
    public function it_returns_404_for_invalid_quote_id(): void
    {
        $this->postJson('/api/cotizaciones/no-es-un-uuid/enviar')
            ->assertNotFound();
    }
}
