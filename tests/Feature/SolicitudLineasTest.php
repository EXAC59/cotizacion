<?php

namespace Tests\Feature;

use App\Models\QuoteRequest;
use App\Models\QuoteRequestLine;
use App\Services\SolicitudLecturaService;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudLineasTest extends AuthenticatedFeatureTestCase
{
    #[Test]
    public function it_replaces_lines_on_procesada_solicitud(): void
    {
        $service = app(SolicitudLecturaService::class);
        $request = $service->crearDesdeLineas([
            [
                'quantity' => 1,
                'product' => 'Original',
                'partNumber' => 'A-1',
                'brand' => 'Genérico',
                'description' => 'Original',
                'unit' => 'pza',
            ],
        ], [
            'source' => 'text',
            'raw_text' => '1 Original',
            'interpretacion_via' => 'parser',
        ]);

        $response = $this->putJson("/api/solicitudes/{$request->id}/lineas", [
            'lineas' => [
                [
                    'quantity' => 3,
                    'product' => 'Actualizado',
                    'partNumber' => 'B-2',
                    'brand' => 'Dell',
                    'description' => 'Actualizado',
                    'unit' => 'caja',
                ],
                [
                    'quantity' => 1,
                    'product' => 'Nueva línea',
                    'partNumber' => 'C-3',
                    'brand' => 'HP',
                    'unit' => 'pza',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('lineas_count', 2)
            ->assertJsonPath('lineas.0.product', 'Actualizado')
            ->assertJsonPath('lineas.0.quantity', 3)
            ->assertJsonPath('lineas.1.product', 'Nueva línea');

        $this->assertDatabaseCount('quote_request_lines', 2);
        $this->assertDatabaseHas('quote_request_lines', [
            'request_id' => $request->id,
            'product' => 'Actualizado',
            'part_number' => 'B-2',
        ]);
    }

    #[Test]
    public function it_returns_404_when_solicitud_does_not_exist(): void
    {
        $response = $this->putJson('/api/solicitudes/00000000-0000-4000-8000-000000000099/lineas', [
            'lineas' => [
                [
                    'quantity' => 1,
                    'product' => 'X',
                ],
            ],
        ]);

        $response->assertNotFound();
    }

    #[Test]
    public function it_rejects_line_edit_when_status_is_pendiente(): void
    {
        $request = QuoteRequest::query()->create([
            'source' => 'pdf',
            'status' => 'pendiente',
            'interpretacion_via' => 'parser',
        ]);

        QuoteRequestLine::query()->create([
            'request_id' => $request->id,
            'line_order' => 0,
            'quantity' => 1,
            'product' => 'Item',
            'part_number' => 'X',
            'brand' => 'Genérico',
            'description' => 'Item',
            'unit' => 'pza',
        ]);

        $response = $this->putJson("/api/solicitudes/{$request->id}/lineas", [
            'lineas' => [
                [
                    'quantity' => 2,
                    'product' => 'Otro',
                ],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Solo se pueden editar líneas de solicitudes procesadas.');
    }
}
