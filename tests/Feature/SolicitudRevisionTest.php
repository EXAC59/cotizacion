<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
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
    public function api_ya_no_marca_pendiente_de_revision(): void
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
        $this->assertFalse($rowVentas['needs_external_review']);
        $this->assertFalse($rowCompras['needs_external_review']);
        $this->assertNull($rowVentas['reviewed_by_name']);
        $this->assertNull($rowCompras['reviewed_by_name']);
    }

    #[Test]
    public function abrir_detalle_ya_no_marca_revisada(): void
    {
        $admin = $this->demoUser('administrador');
        $request = $this->crearSolicitud([
            'created_by' => $this->demoUser('ventas')->id,
        ]);

        $response = $this->actingAs($admin)->getJson("/api/solicitudes/{$request->id}");

        $response->assertOk()
            ->assertJsonPath('needs_external_review', false)
            ->assertJsonPath('reviewed_by', null)
            ->assertJsonPath('reviewed_by_name', null);

        $this->assertNull($request->fresh()->reviewed_by);
    }

    #[Test]
    public function no_permite_editar_lineas_si_ya_hay_cotizacion(): void
    {
        $client = Client::query()->create([
            'company' => 'Cliente Bloqueo Quote',
            'rfc' => 'CBQ010101AAA',
        ]);
        $request = $this->crearSolicitud([
            'client_id' => $client->id,
            'created_by' => $this->demoUser('ventas')->id,
        ]);
        Quote::query()->create([
            'folio' => 'COT-BLOCK-'.uniqid(),
            'client_id' => $client->id,
            'request_id' => $request->id,
            'status' => 'solicitud_cotizaciones',
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
            'created_by' => $this->demoUser('gerente_compras')->id,
        ]);

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
    public function permite_editar_lineas_sin_cotizacion(): void
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
            ->assertJsonPath('needs_external_review', false)
            ->assertJsonPath('reviewed_by', null);
    }
}
