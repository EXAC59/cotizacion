<?php

namespace Tests\Unit;

use App\Exceptions\DoclingConversionException;
use App\Exceptions\N8nWebhookException;
use App\Services\DoclingClient;
use App\Services\N8nClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Ensaya el transporte real de los adaptadores sin ejecutar servicios externos. */
class IntegrationTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'n8n.webhook_url' => 'http://n8n.test/webhook/lectura',
            'n8n.comparator_webhook_url' => 'http://n8n.test/webhook/comparador',
            'docling.base_url' => 'http://docling.test',
        ]);
    }

    public function test_text_reading_sends_the_request_context_as_json(): void
    {
        Http::fake(['http://n8n.test/webhook/lectura' => Http::response(['accepted' => true], 202)]);
        $payload = ['request_id' => 'fixture-request', 'raw_text' => '2 Monitores', 'client_id' => 'fixture-client'];
        $this->assertSame(['accepted' => true], app(N8nClient::class)->dispararLectura($payload));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->data() === $payload && $request->hasHeader('Accept', 'application/json'));
        Http::assertSentCount(1);
    }

    public function test_file_reading_sends_multipart_and_keeps_the_callback_request_id(): void
    {
        Http::fake(['http://n8n.test/webhook/lectura*' => Http::response(['accepted' => true], 202)]);
        $path = tempnam(sys_get_temp_dir(), 'lectura-fixture-');
        file_put_contents($path, 'Documento de prueba sin datos de clientes.');
        try {
            app(N8nClient::class)->dispararLecturaArchivo($path, ['request_id' => 'fixture-request']);
            Http::assertSent(fn ($request) => $request->url() === 'http://n8n.test/webhook/lectura?request_id=fixture-request'
                && $request->isMultipart() && $request->hasFile('archivo', 'Documento de prueba sin datos de clientes.')
                && collect($request->data())->firstWhere('name', 'request_id')['contents'] === 'fixture-request');
            Http::assertSentCount(1);
        } finally {
            unlink($path);
        }
    }

    public function test_comparator_sends_live_mode_and_quantity_without_changing_the_contract(): void
    {
        config(['quote_comparator.demo_offers' => false]);
        Http::fake(['http://n8n.test/webhook/comparador' => Http::response(['accepted' => true], 202)]);
        app(N8nClient::class)->dispararComparador('fixture-job', 'SKU-001', 2, 'CDMX', ['fixture' => true]);
        Http::assertSent(fn ($request) => $request['job_id'] === 'fixture-job' && $request['part_number'] === 'SKU-001'
            && $request['partNumber'] === 'SKU-001' && (float) $request['quantity'] === 2.0
            && $request['preferred_warehouse'] === 'CDMX' && $request['preferredWarehouse'] === 'CDMX'
            && $request['demo_mode'] === false && $request['use_mock'] === false && $request['context'] === ['fixture' => true]);
    }

    public function test_n8n_unreachable_reports_a_controlled_integration_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('Fixture: servicio no disponible.'));
        $this->expectException(N8nWebhookException::class);
        app(N8nClient::class)->dispararLectura(['raw_text' => 'Fixture']);
    }

    public function test_n8n_rejecting_a_request_is_not_reported_as_accepted(): void
    {
        Http::fake(['http://n8n.test/webhook/lectura' => Http::response(['message' => 'Fixture: acceso denegado.'], 401)]);
        $this->expectException(N8nWebhookException::class);
        app(N8nClient::class)->dispararLectura(['raw_text' => 'Fixture']);
    }

    public function test_docling_ping_and_conversion_use_the_expected_multipart_contract(): void
    {
        $markdown = '| CANTIDAD | PRODUCTO | NO.PARTE | MARCA |\n| 1 | Monitor | MON-01 | Genérico |';
        Http::fake([
            'http://docling.test/docs' => Http::response('Docs', 200),
            'http://docling.test/v1/convert/file' => Http::response(['status' => 'success', 'document' => ['md_content' => $markdown]]),
        ]);
        $path = tempnam(sys_get_temp_dir(), 'docling-fixture-');
        file_put_contents($path, 'Documento de prueba.');
        try {
            $this->assertTrue(app(DoclingClient::class)->ping()['ok']);
            $result = app(DoclingClient::class)->convertFileToMarkdown($path, false);
            $this->assertSame($markdown, $result['document']['md_content']);
            Http::assertSent(fn ($request) => $request->url() === 'http://docling.test/v1/convert/file'
                && $request->isMultipart() && $request->hasFile('files', 'Documento de prueba.')
                && collect($request->data())->firstWhere('name', 'to_formats')['contents'] === 'md'
                && collect($request->data())->firstWhere('name', 'do_ocr')['contents'] === 'false');
            Http::assertSentCount(2);
        } finally {
            unlink($path);
        }
    }

    public function test_docling_failed_conversion_cannot_be_accepted_as_a_valid_document(): void
    {
        Http::fake(['http://docling.test/v1/convert/file' => Http::response(['status' => 'failure', 'errors' => [['error_message' => 'Fixture: documento ilegible.']]])]);
        $path = tempnam(sys_get_temp_dir(), 'docling-fixture-');
        file_put_contents($path, 'Documento de prueba.');
        try {
            $this->expectException(DoclingConversionException::class);
            app(DoclingClient::class)->convertFileToMarkdown($path);
        } finally {
            unlink($path);
        }
    }
}
