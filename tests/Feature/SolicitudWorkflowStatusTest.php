<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Services\Quotes\QuotePersistenceService;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudWorkflowStatusTest extends AuthenticatedFeatureTestCase
{
    private function crearSolicitud(array $overrides = []): QuoteRequest
    {
        return QuoteRequest::query()->create(array_merge([
            'source' => 'text',
            'status' => 'procesada',
            'raw_text' => 'productos',
        ], $overrides));
    }

    #[Test]
    public function nace_en_elaboracion_por_default_de_columna(): void
    {
        $request = $this->crearSolicitud();

        $this->assertSame('en_elaboracion', $request->fresh()->workflow_status);
    }

    #[Test]
    public function el_api_sigue_exponiendo_workflow_status_por_compatibilidad(): void
    {
        $request = $this->crearSolicitud();

        $response = $this->getJson("/api/solicitudes/{$request->id}");

        $response->assertOk()
            ->assertJsonPath('workflow_status', 'en_elaboracion');
    }

    #[Test]
    public function guardar_lineas_crea_cotizacion_sin_marcar_workflow_enviada(): void
    {
        $client = Client::query()->create([
            'company' => 'Empresa Workflow',
            'rfc' => 'EWF010101ABC',
        ]);
        $request = $this->crearSolicitud([
            'client_id' => $client->id,
            'status' => 'procesada',
            'workflow_status' => 'en_elaboracion',
        ]);

        $response = $this->putJson("/api/solicitudes/{$request->id}/lineas", [
            'lineas' => [
                [
                    'quantity' => 2,
                    'product' => 'Tornillo',
                    'partNumber' => 'TN-01',
                    'brand' => 'Genérico',
                ],
            ],
        ]);

        $response->assertOk();
        $quoteId = $response->json('quote_id');
        $this->assertNotEmpty($quoteId);

        $quote = Quote::query()->findOrFail($quoteId);
        $this->assertNull($quote->sent_at);
        $this->assertNotSame('enviada', $quote->status);
        $this->assertSame('en_elaboracion', $request->fresh()->workflow_status);

        $this->putJson("/api/solicitudes/{$request->id}/lineas", [
            'lineas' => [
                [
                    'quantity' => 3,
                    'product' => 'Tornillo',
                    'partNumber' => 'TN-01',
                    'brand' => 'Genérico',
                ],
            ],
        ])->assertStatus(403);
    }

    #[Test]
    public function workflow_status_en_body_se_ignora(): void
    {
        $client = Client::query()->create([
            'company' => 'Empresa Ignore WF',
            'rfc' => 'EIW010101ABC',
        ]);
        $request = $this->crearSolicitud([
            'client_id' => $client->id,
            'workflow_status' => 'en_elaboracion',
        ]);

        $response = $this->putJson("/api/solicitudes/{$request->id}/lineas", [
            'workflow_status' => 'pendiente_envio',
            'lineas' => [
                [
                    'quantity' => 1,
                    'product' => 'Tornillo',
                    'partNumber' => 'TN-01',
                    'brand' => 'Genérico',
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertSame('en_elaboracion', $request->fresh()->workflow_status);
    }

    #[Test]
    public function el_filtro_workflow_status_ya_no_aplica(): void
    {
        $this->crearSolicitud(['workflow_status' => 'enviada', 'raw_text' => 'b']);
        $this->crearSolicitud(['workflow_status' => 'en_elaboracion', 'raw_text' => 'c']);

        $response = $this->getJson('/api/solicitudes?workflow_status=enviada');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    #[Test]
    public function crear_cotizacion_vinculada_no_marca_solicitud_enviada(): void
    {
        $client = Client::query()->create([
            'company' => 'Empresa Test',
            'rfc' => 'EMP010101ABC',
        ]);

        $request = $this->crearSolicitud([
            'client_id' => $client->id,
            'status' => 'procesada',
            'workflow_status' => 'en_elaboracion',
        ]);

        $persistence = app(QuotePersistenceService::class);

        $quote = $persistence->save([
            'clientId' => $client->id,
            'requestId' => $request->id,
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'Tornillo',
                    'partNumber' => 'TN-01',
                    'cost' => 10,
                    'marginPercent' => 30,
                    'salePrice' => 13,
                    'amount' => 13,
                ],
            ],
        ]);

        $this->assertSame('en_elaboracion', $request->fresh()->workflow_status);
        $this->assertNull($quote->sent_at);
        $this->assertNotSame('enviada', $quote->status);
        $this->assertNotNull($quote->request_id);
    }
}
