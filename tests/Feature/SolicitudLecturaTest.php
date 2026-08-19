<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\QuoteRequest;
use App\Services\DoclingClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudLecturaTest extends AuthenticatedFeatureTestCase
{

    #[Test]
    public function it_persists_lines_when_docling_upload_succeeds(): void
    {
        Storage::fake('local');

        $client = Client::query()->create([
            'company' => 'Cliente lectura',
            'rfc' => '',
            'contact_name' => '',
            'email' => '',
        ]);

        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | MARCA | UNIDAD |
| --- | --- | --- | --- | --- |
| 3 | Cable HDMI 2m | HDMI-200 | Belkin | pza |
MD;

        $this->mock(DoclingClient::class, function ($mock) use ($markdown) {
            $mock->shouldReceive('ping')->once()->andReturn(['ok' => true, 'status' => 200, 'error' => null]);
            $mock->shouldReceive('convertFileToMarkdown')->once()->andReturn([
                'document' => ['md_content' => $markdown],
            ]);
        });

        $file = UploadedFile::fake()->create('plantilla.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->post('/api/solicitudes/lectura', [
            'archivo' => $file,
            'via' => 'docling',
            'client_id' => $client->id,
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('modo', 'docling')
            ->assertJsonPath('lineas_count', 1)
            ->assertJsonPath('lineas.0.product', 'Cable HDMI 2m');

        $this->assertDatabaseCount('quote_requests', 1);
        $this->assertDatabaseCount('quote_request_lines', 1);

        $requestId = $response->json('request_id');
        $this->assertNotNull($requestId);
        $this->assertDatabaseHas('quote_requests', [
            'id' => $requestId,
            'status' => 'procesada',
            'client_id' => $client->id,
        ]);
    }

    #[Test]
    public function it_persists_lines_when_text_lectura_docling_succeeds(): void
    {
        Storage::fake('local');

        $client = Client::query()->create([
            'company' => 'Cliente texto',
            'rfc' => '',
            'contact_name' => '',
            'email' => '',
        ]);

        $this->mock(DoclingClient::class, function ($mock) {
            $mock->shouldReceive('ping')->once()->andReturn(['ok' => true, 'status' => 200, 'error' => null]);
            $mock->shouldReceive('convertFileToMarkdown')->once()->andReturn([
                'document' => ['md_content' => "2 Monitor Dell 24\n1 Teclado mecánico (KB-100) — Logitech"],
            ]);
        });

        $response = $this->postJson('/api/solicitudes/lectura-texto', [
            'raw_text' => "2 Monitor Dell 24\n1 Teclado mecánico (KB-100) — Logitech",
            'via' => 'docling',
            'client_id' => $client->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('modo', 'docling')
            ->assertJsonPath('lineas_count', 2)
            ->assertJsonPath('lineas.0.product', 'Monitor Dell 24');

        $this->assertDatabaseHas('quote_requests', [
            'id' => $response->json('request_id'),
            'source' => 'text',
            'status' => 'procesada',
            'client_id' => $client->id,
        ]);
    }

    #[Test]
    public function it_reprocesses_error_solicitud_with_stored_file(): void
    {
        Storage::fake('local');

        $storedPath = 'solicitudes/test.xlsx';
        Storage::disk('local')->put($storedPath, 'fake-xlsx-content');

        $request = QuoteRequest::query()->create([
            'source' => 'excel',
            'status' => 'error',
            'file_name' => 'test.xlsx',
            'file_path' => $storedPath,
            'error_message' => 'Fallo previo',
        ]);

        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | MARCA | UNIDAD |
| --- | --- | --- | --- | --- |
| 1 | Monitor 24 | MON-24 | Dell | pza |
MD;

        $this->mock(DoclingClient::class, function ($mock) use ($markdown) {
            $mock->shouldReceive('ping')->once()->andReturn(['ok' => true, 'status' => 200, 'error' => null]);
            $mock->shouldReceive('convertFileToMarkdown')->once()->andReturn([
                'document' => ['md_content' => $markdown],
            ]);
        });

        $response = $this->postJson("/api/solicitudes/{$request->id}/reprocesar");

        $response->assertOk()
            ->assertJsonPath('status', 'procesada')
            ->assertJsonPath('lineas_count', 1)
            ->assertJsonPath('lineas.0.product', 'Monitor 24');

        $request->refresh();
        $this->assertSame('procesada', $request->status);
        $this->assertNull($request->error_message);
    }

    #[Test]
    public function it_reprocesses_error_text_solicitud_without_docling(): void
    {
        Storage::fake('local');

        $storedPath = 'solicitudes/texto.txt';
        $rawText = '2 USB de 128gb kingston dtx / 128';
        Storage::disk('local')->put($storedPath, $rawText);

        $request = QuoteRequest::query()->create([
            'source' => 'text',
            'status' => 'error',
            'file_name' => 'texto-libre.txt',
            'file_path' => $storedPath,
            'raw_text' => $rawText,
            'error_message' => 'Unauthenticated.',
        ]);

        $response = $this->postJson("/api/solicitudes/{$request->id}/reprocesar");

        $response->assertOk()
            ->assertJsonPath('status', 'procesada')
            ->assertJsonPath('lineas_count', 1);

        $request->refresh();
        $this->assertSame('procesada', $request->status);
        $this->assertNull($request->error_message);
    }

    #[Test]
    public function it_rejects_reprocess_without_file_path(): void
    {
        $request = QuoteRequest::query()->create([
            'source' => 'pdf',
            'status' => 'error',
            'file_name' => 'sin-ruta.pdf',
            'file_path' => null,
            'error_message' => 'Fallo',
        ]);

        $this->postJson("/api/solicitudes/{$request->id}/reprocesar")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La solicitud no tiene archivo almacenado para reprocesar.');
    }

    #[Test]
    public function it_rejects_reprocess_when_status_is_not_error(): void
    {
        $request = QuoteRequest::query()->create([
            'source' => 'pdf',
            'status' => 'procesada',
            'file_name' => 'ok.pdf',
            'file_path' => 'solicitudes/ok.pdf',
        ]);

        $this->postJson("/api/solicitudes/{$request->id}/reprocesar")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Solo se pueden reprocesar solicitudes en estado error.');
    }
}
