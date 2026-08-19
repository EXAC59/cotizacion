<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\QuoteRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudRevisionTest extends AuthenticatedFeatureTestCase
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
    public function se_crea_sin_revisor(): void
    {
        $request = $this->crearSolicitud();

        $this->assertNull($request->reviewed_by);
        $this->assertNull($request->reviewed_at);
    }

    #[Test]
    public function marca_como_revisada_al_abrir_el_detalle(): void
    {
        $request = $this->crearSolicitud();

        $response = $this->getJson("/api/solicitudes/{$request->id}");

        $response->assertOk()
            ->assertJsonPath('reviewed_by_name', 'Administrador del Sistema')
            ->assertJsonPath('reviewed_by', (string) $this->demoUser('administrador')->id)
            ->assertJsonPath('reviewed_at', $request->fresh()->reviewed_at->toIso8601String());
    }

    #[Test]
    public function el_primer_revisor_gana(): void
    {
        $request = $this->crearSolicitud();

        // El administrador abre el detalle primero.
        $this->getJson("/api/solicitudes/{$request->id}")->assertOk();
        $adminId = (string) $this->demoUser('administrador')->id;

        // Un usuario de ventas abre después: el revisor no cambia.
        $this->actingAs($this->demoUser('ventas'));

        $response = $this->getJson("/api/solicitudes/{$request->id}");

        $response->assertOk()
            ->assertJsonPath('reviewed_by', $adminId)
            ->assertJsonPath('reviewed_by_name', 'Administrador del Sistema');
    }

    #[Test]
    public function el_dashboard_lista_solo_las_en_elaboracion(): void
    {
        $client = Client::query()->create([
            'company' => 'Revision Corp',
            'rfc' => 'REV010101ABC',
        ]);

        $enElaboracion = $this->crearSolicitud([
            'client_id' => $client->id,
            'file_name' => 'pedido.pdf',
        ]);
        $preciosListos = $this->crearSolicitud([
            'client_id' => $client->id,
            'status' => 'precios_listos',
            'file_name' => 'precios.xlsx',
        ]);
        $pendienteEnvio = $this->crearSolicitud([
            'client_id' => $client->id,
            'workflow_status' => 'pendiente_envio',
            'file_name' => 'lista.pdf',
        ]);
        $enviada = $this->crearSolicitud([
            'client_id' => $client->id,
            'workflow_status' => 'enviada',
            'file_name' => 'enviada.pdf',
        ]);

        $response = $this->getJson('/api/dashboard');

        $response->assertOk();
        $pendingIds = collect($response->json('alerts.pendingReviewRequests'))
            ->pluck('id')
            ->all();

        $this->assertContains($enElaboracion->id, $pendingIds);
        $this->assertContains($preciosListos->id, $pendingIds);
        $this->assertNotContains($pendienteEnvio->id, $pendingIds);
        $this->assertNotContains($enviada->id, $pendingIds);

        // Las sin revisar no deben filtrarse por estado terminal de flujo.
        $pendingStatuses = collect($response->json('alerts.pendingReviewRequests'))
            ->map(fn ($item) => QuoteRequest::query()->find($item['id'])->status)
            ->filter(fn ($status) => in_array($status, ['procesando', 'error'], true));

        $this->assertCount(0, $pendingStatuses);
    }

    #[Test]
    public function no_permite_editar_lineas_una_vez_enviada(): void
    {
        $request = $this->crearSolicitud(['workflow_status' => 'enviada']);

        $this->actingAs($this->demoUser('ventas'))
            ->putJson("/api/solicitudes/{$request->id}/lineas", [
                'lineas' => [
                    [
                        'quantity' => 2,
                        'product' => 'Tornillo',
                        'partNumber' => 'T-01',
                        'brand' => 'Genérico',
                        'description' => 'Tornillo de prueba',
                        'unit' => 'pza',
                    ],
                ],
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function permite_editar_lineas_en_elaboracion(): void
    {
        $request = $this->crearSolicitud();

        $this->actingAs($this->demoUser('ventas'))
            ->putJson("/api/solicitudes/{$request->id}/lineas", [
                'lineas' => [
                    [
                        'quantity' => 3,
                        'product' => 'Tuerca',
                        'partNumber' => 'T-02',
                        'brand' => 'Genérico',
                        'description' => 'Tuerca de prueba',
                        'unit' => 'pza',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('workflow_status', 'pendiente_envio');
    }
}
