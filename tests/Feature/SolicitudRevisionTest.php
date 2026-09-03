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
    public function listado_marca_pendiente_solo_si_creador_es_ventas(): void
    {
        $ventas = $this->demoUser('ventas');
        $compras = $this->demoUser('gerente_compras');

        $deVentas = $this->crearSolicitud([
            'created_by' => $ventas->id,
            'folio' => 'SOL-VENTAS-PEND',
        ]);
        $deCompras = $this->crearSolicitud([
            'created_by' => $compras->id,
            'folio' => 'SOL-COMPRAS-NA',
        ]);

        $this->actingAs($compras);
        $rows = collect($this->getJson('/api/solicitudes?scope=all')->assertOk()->json('data'));

        $rowVentas = $rows->firstWhere('id', $deVentas->id);
        $rowCompras = $rows->firstWhere('id', $deCompras->id);

        $this->assertNotNull($rowVentas);
        $this->assertNotNull($rowCompras);

        $this->assertTrue($rowVentas['needs_external_review']);
        $this->assertTrue($rowVentas['assigned_to_sales']);
        $this->assertNull($rowVentas['reviewed_by_name']);

        $this->assertFalse($rowCompras['needs_external_review']);
        $this->assertFalse($rowCompras['assigned_to_sales']);
        $this->assertNull($rowCompras['reviewed_by_name']);
    }

    #[Test]
    public function se_crea_sin_revisor(): void
    {
        $request = $this->crearSolicitud();

        $this->assertNull($request->reviewed_by);
        $this->assertNull($request->reviewed_at);
    }

    #[Test]
    public function marca_como_revisada_al_abrir_el_detalle_si_no_es_el_creador(): void
    {
        $admin = $this->demoUser('administrador');
        $request = $this->crearSolicitud([
            'created_by' => $this->demoUser('ventas')->id,
        ]);

        $response = $this->actingAs($admin)->getJson("/api/solicitudes/{$request->id}");

        $response->assertOk()
            ->assertJsonPath('reviewed_by_name', 'Administrador del Sistema')
            ->assertJsonPath('reviewed_by', (string) $admin->id)
            ->assertJsonPath('reviewed_at', $request->fresh()->reviewed_at->toIso8601String());
    }

    #[Test]
    public function no_marca_revisada_si_abre_quien_la_creo(): void
    {
        $ventas = $this->demoUser('ventas');
        $request = $this->crearSolicitud(['created_by' => $ventas->id]);

        $this->actingAs($ventas)
            ->getJson("/api/solicitudes/{$request->id}")
            ->assertOk()
            ->assertJsonPath('reviewed_by', null)
            ->assertJsonPath('reviewed_by_name', null)
            ->assertJsonPath('reviewed_at', null);

        $this->assertNull($request->fresh()->reviewed_by);
        $this->assertNull($request->fresh()->reviewed_at);
    }

    #[Test]
    public function no_muestra_revisada_si_el_unico_revisor_fue_el_creador(): void
    {
        $ventas = $this->demoUser('ventas');
        $request = $this->crearSolicitud([
            'created_by' => $ventas->id,
            'reviewed_by' => $ventas->id,
            'reviewed_at' => now(),
        ]);

        $row = collect($this->getJson('/api/solicitudes')->json('data'))
            ->firstWhere('id', $request->id);

        $this->assertNotNull($row);
        $this->assertNull($row['reviewed_by']);
        $this->assertNull($row['reviewed_by_name']);
        $this->assertNull($row['reviewed_at']);
    }

    #[Test]
    public function el_primer_revisor_gana(): void
    {
        $admin = $this->demoUser('administrador');
        $request = $this->crearSolicitud([
            'created_by' => $this->demoUser('ventas')->id,
        ]);

        // El administrador abre el detalle primero.
        $this->actingAs($admin)->getJson("/api/solicitudes/{$request->id}")->assertOk();
        $adminId = (string) $admin->id;

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
        $ventas = $this->demoUser('ventas');

        $enElaboracion = $this->crearSolicitud([
            'client_id' => $client->id,
            'created_by' => $ventas->id,
            'file_name' => 'pedido.pdf',
        ]);
        $preciosListos = $this->crearSolicitud([
            'client_id' => $client->id,
            'created_by' => $ventas->id,
            'status' => 'precios_listos',
            'file_name' => 'precios.xlsx',
        ]);
        $pendienteEnvio = $this->crearSolicitud([
            'client_id' => $client->id,
            'created_by' => $ventas->id,
            'workflow_status' => 'pendiente_envio',
            'file_name' => 'lista.pdf',
        ]);
        $enviada = $this->crearSolicitud([
            'client_id' => $client->id,
            'created_by' => $ventas->id,
            'workflow_status' => 'enviada',
            'file_name' => 'enviada.pdf',
        ]);
        $deCompras = $this->crearSolicitud([
            'client_id' => $client->id,
            'created_by' => $this->demoUser('gerente_compras')->id,
            'file_name' => 'compras.pdf',
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
        $this->assertNotContains($deCompras->id, $pendingIds);

        // Las sin revisar no deben filtrarse por estado terminal de flujo.
        $pendingStatuses = collect($response->json('alerts.pendingReviewRequests'))
            ->map(fn ($item) => QuoteRequest::query()->find($item['id'])->status)
            ->filter(fn ($status) => in_array($status, ['procesando', 'error'], true));

        $this->assertCount(0, $pendingStatuses);
    }

    #[Test]
    public function el_dashboard_excluye_solicitudes_revisadas_por_otro_usuario(): void
    {
        $client = Client::query()->create([
            'company' => 'Revision Corp',
            'rfc' => 'REV020202ABC',
        ]);
        $ventas = $this->demoUser('ventas');
        $admin = $this->demoUser('administrador');

        $sinRevision = $this->crearSolicitud([
            'client_id' => $client->id,
            'created_by' => $ventas->id,
            'file_name' => 'pendiente.pdf',
        ]);
        $revisadaPorOtro = $this->crearSolicitud([
            'client_id' => $client->id,
            'created_by' => $ventas->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'file_name' => 'revisada.pdf',
        ]);
        $marcadaPorCreador = $this->crearSolicitud([
            'client_id' => $client->id,
            'created_by' => $ventas->id,
            'reviewed_by' => $ventas->id,
            'reviewed_at' => now(),
            'file_name' => 'auto.pdf',
        ]);

        $response = $this->getJson('/api/dashboard');
        $pendingIds = collect($response->json('alerts.pendingReviewRequests'))
            ->pluck('id')
            ->all();

        $this->assertContains($sinRevision->id, $pendingIds);
        $this->assertContains($marcadaPorCreador->id, $pendingIds);
        $this->assertNotContains($revisadaPorOtro->id, $pendingIds);
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
        $ventas = $this->demoUser('ventas');
        $request = $this->crearSolicitud(['created_by' => $ventas->id]);

        $this->actingAs($ventas)
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
            ->assertJsonPath('workflow_status', 'pendiente_envio')
            ->assertJsonPath('needs_external_review', true)
            ->assertJsonPath('reviewed_by', null);
    }
}
