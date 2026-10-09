<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthenticatedFeatureTestCase;

/** El flujo vigente usa compras/tomar y seguimiento/tomar, conservando dueño y folio. */
class AssignToSalesTest extends AuthenticatedFeatureTestCase
{
    public static function retiredAssignmentRoutes(): array
    {
        $cases = [];
        foreach (['ventas', 'gerente_compras', 'administrador'] as $role) {
            foreach ([
                ['GET', '/api/usuarios/ventas'], ['GET', '/api/usuarios/compras'],
                ['POST', '/api/cotizaciones/:quote/asignar-ventas'], ['POST', '/api/cotizaciones/:quote/asignar-compras'],
                ['POST', '/api/solicitudes/:request/asignar-ventas'], ['POST', '/api/solicitudes/:request/asignar-compras'],
            ] as [$method, $route]) {
                $cases[$role.' '.$route] = [$role, $method, $route];
            }
        }
        return $cases;
    }

    #[DataProvider('retiredAssignmentRoutes')]
    public function test_retired_routes_cannot_transfer_ownership_or_regenerate_folios(string $role, string $method, string $route): void
    {
        $owner = $this->demoUser('ventas');
        $recipient = $this->demoUser('gerente_compras');
        $client = Client::query()->create(['company' => 'Contrato de asignación', 'rfc' => 'CAS010101AAA']);
        $request = QuoteRequest::query()->create([
            'source' => 'text', 'status' => 'procesada', 'created_by' => $owner->id, 'client_id' => $client->id,
        ]);
        $quote = Quote::query()->create([
            'folio' => 'COT-CONTRATO-001', 'client_id' => $client->id, 'request_id' => $request->id,
            'created_by' => $owner->id, 'status' => 'solicitud_cotizaciones',
            'validity_days' => 15, 'global_margin_percent' => 30, 'tax_percent' => 16,
            'subtotal' => 0, 'tax_amount' => 0, 'total' => 0,
        ]);
        $requestFolio = $request->folio;
        $this->actingAsDemoUser($role);
        $this->json($method, str_replace([':quote', ':request'], [$quote->id, $request->id], $route), [
            'recipientId' => $recipient->id, 'message' => 'Intento de transferencia por ruta retirada.',
        ])->assertNotFound();
        $this->assertSame($owner->id, $quote->fresh()->created_by);
        $this->assertSame('COT-CONTRATO-001', $quote->fresh()->folio);
        $this->assertSame($owner->id, $request->fresh()->created_by);
        $this->assertSame($requestFolio, $request->fresh()->folio);
        $this->assertDatabaseCount('quote_internal_notes', 0);
        $this->assertDatabaseCount('sales_notifications', 0);
    }
}
