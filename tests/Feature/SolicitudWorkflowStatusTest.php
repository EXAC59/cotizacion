<?php

namespace Tests\Feature;

use App\Models\Client;
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
    public function nace_en_elaboracion(): void
    {
        $request = $this->crearSolicitud();

        $this->assertSame('en_elaboracion', $request->fresh()->workflow_status);
    }

    #[Test]
    public function el_api_expone_workflow_status(): void
    {
        $request = $this->crearSolicitud();

        $response = $this->getJson("/api/solicitudes/{$request->id}");

        $response->assertOk()
            ->assertJsonPath('workflow_status', 'en_elaboracion')
            ->assertJsonPath('workflow_status_label', 'En elaboración');
    }

    #[Test]
    public function guardar_lineas_pasa_a_lista_terminada(): void
    {
        $request = $this->crearSolicitud();

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

        $response->assertOk()
            ->assertJsonPath('workflow_status', 'pendiente_envio');
        $this->assertSame('pendiente_envio', $request->fresh()->workflow_status);
    }

    #[Test]
    public function guardar_lineas_puede_permanecer_en_elaboracion(): void
    {
        $request = $this->crearSolicitud(['workflow_status' => 'pendiente_envio']);

        $response = $this->putJson("/api/solicitudes/{$request->id}/lineas", [
            'workflow_status' => 'en_elaboracion',
            'lineas' => [
                [
                    'quantity' => 2,
                    'product' => 'Tornillo',
                    'partNumber' => 'TN-01',
                    'brand' => 'Genérico',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('workflow_status', 'en_elaboracion');
        $this->assertSame('en_elaboracion', $request->fresh()->workflow_status);
    }

    #[Test]
    public function guardar_lineas_rechaza_otro_estatus(): void
    {
        $request = $this->crearSolicitud();

        $this->putJson("/api/solicitudes/{$request->id}/lineas", [
            'workflow_status' => 'enviada',
            'lineas' => [
                [
                    'quantity' => 1,
                    'product' => 'Tornillo',
                    'partNumber' => 'TN-01',
                    'brand' => 'Genérico',
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('workflow_status');
    }

    #[Test]
    public function el_filtro_workflow_status_funciona(): void
    {
        $lista = $this->crearSolicitud(['workflow_status' => 'pendiente_envio', 'raw_text' => 'a']);
        $enviada = $this->crearSolicitud(['workflow_status' => 'enviada', 'raw_text' => 'b']);
        $this->crearSolicitud(['workflow_status' => 'en_elaboracion', 'raw_text' => 'c']);

        $response = $this->getJson('/api/solicitudes?workflow_status=enviada');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $enviada->id)
            ->assertJsonPath('data.0.workflow_status', 'enviada');
    }

    #[Test]
    public function crear_cotizacion_vinculada_marca_la_solicitud_enviada(): void
    {
        $client = Client::query()->create([
            'company' => 'Empresa Test',
            'rfc' => 'EMP010101ABC',
        ]);

        $request = $this->crearSolicitud([
            'client_id' => $client->id,
            'status' => 'procesada',
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

        $this->assertSame('enviada', $request->fresh()->workflow_status);
        $this->assertNotNull($quote->request_id);
    }
}
