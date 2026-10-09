<?php

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsAppCloudService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WhatsAppCloudServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('whatsapp.access_token', 'fake-token');
        config()->set('whatsapp.phone_number_id', '123');
        config()->set('whatsapp.waba_id', '456');
        config()->set('whatsapp.app_id', '789');
        Http::preventStrayRequests();
    }

    private function approved(): array
    {
        return ['data' => [[
            'id' => 'template-1', 'name' => 'cotizacion_lista', 'language' => config('whatsapp.language'), 'status' => 'APPROVED',
            'components' => [
                ['type' => 'HEADER', 'format' => 'DOCUMENT'],
                ['type' => 'BODY', 'text' => config('whatsapp.templates.cotizacion_lista.body')],
            ],
        ]]];
    }

    public function test_normalizes_mexican_and_international_numbers(): void
    {
        $service = app(WhatsAppCloudService::class);
        $this->assertSame('525512345678', $service->normalizePhone('55 1234 5678'));
        $this->assertSame('525512345678', $service->normalizePhone('+52 1 55 1234 5678'));
        $this->assertSame('14155552671', $service->normalizePhone('+1 415 555 2671'));
        $this->expectException(ValidationException::class);
        $service->normalizePhone('cliente sin número');
    }

    public function test_uploads_private_pdf_and_sends_approved_template_to_client(): void
    {
        Http::fake([
            '*/456/message_templates*' => Http::response($this->approved()),
            '*/123/media' => Http::response(['id' => 'pdf-1']),
            '*/123/messages' => Http::response(['messages' => [['id' => 'wamid.test']]]),
        ]);
        $id = app(WhatsAppCloudService::class)->sendPdf('5512345678', 'cotizacion_lista', ['Cliente', 'COT-001', 'Empresa'], '%PDF-test', 'COT-001.pdf');
        $this->assertSame('wamid.test', $id);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages')
            && $request['to'] === '525512345678'
            && $request['template']['components'][0]['parameters'][0]['document']['id'] === 'pdf-1'
            && $request['template']['components'][1]['parameters'][1]['text'] === 'COT-001');
    }

    public function test_does_not_send_unapproved_template(): void
    {
        $templates = $this->approved();
        $templates['data'][0]['status'] = 'PENDING';
        Http::fake(['*/message_templates*' => Http::response($templates)]);
        try {
            app(WhatsAppCloudService::class)->sendPdf('5512345678', 'cotizacion_lista', [], '%PDF-test', 'quote.pdf');
            $this->fail('Una plantilla pendiente no debe enviarse.');
        } catch (ValidationException) {
            Http::assertSentCount(1);
        }
    }

    public function test_rejects_approved_template_with_different_variables(): void
    {
        $templates = $this->approved();
        $templates['data'][0]['components'][1]['text'] = 'Hola {{1}}, cotización {{2}} por {{3}} pesos';
        Http::fake(['*/message_templates*' => Http::response($templates)]);
        $this->assertFalse(app(WhatsAppCloudService::class)->catalog()[0]['canSend']);
    }

    public function test_creates_template_with_resumable_pdf_example(): void
    {
        Http::fake([
            '*/456/message_templates*' => Http::sequence()->push(['data' => []])->push(['id' => 'new', 'status' => 'PENDING']),
            '*/789/uploads' => Http::response(['id' => 'upload:sample']),
            '*/upload:sample' => Http::response(['h' => 'sample-handle']),
        ]);
        $result = app(WhatsAppCloudService::class)->createTemplate('cotizacion_lista', '%PDF-test');
        $this->assertSame('PENDING', $result['status']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/message_templates')
            && $request['components'][0]['example']['header_handle'] === ['sample-handle']);
    }

    public function test_unconfigured_account_lists_drafts_without_calling_meta(): void
    {
        config()->set('whatsapp.access_token', null);
        Http::fake();
        $this->assertCount(3, app(WhatsAppCloudService::class)->catalog());
        Http::assertNothingSent();
    }
}
