<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\QuoteRequest;
use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerCatalogSync;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudLineasComparatorTest extends AuthenticatedFeatureTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        app(WholesalerCatalogSync::class)->sync();
    }

    #[Test]
    public function it_persists_reference_cost_and_wholesaler_on_request_lines(): void
    {
        $client = Client::query()->create([
            'company' => 'Cliente comparador',
            'rfc' => '',
            'contact_name' => '',
            'email' => '',
        ]);
        $wholesaler = Wholesaler::query()->where('active', true)->firstOrFail();

        $request = QuoteRequest::query()->create([
            'client_id' => $client->id,
            'source' => 'pdf',
            'status' => 'procesada',
        ]);

        $request->lines()->create([
            'quantity' => 2,
            'product' => 'Switch',
            'part_number' => 'C9200L-24T-4G-E',
            'brand' => 'Cisco',
            'description' => 'Switch 24p',
            'unit' => 'pza',
        ]);

        $response = $this->putJson("/api/solicitudes/{$request->id}/lineas", [
            'lineas' => [
                [
                    'quantity' => 2,
                    'product' => 'Switch',
                    'partNumber' => 'C9200L-24T-4G-E',
                    'brand' => 'Cisco',
                    'description' => 'Switch 24p',
                    'unit' => 'pza',
                    'referenceCost' => 28500.50,
                    'selectedWholesalerId' => $wholesaler->id,
                    'warehouse' => 'CDMX',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('lineas.0.referenceCost', 28500.5)
            ->assertJsonPath('lineas.0.selectedWholesalerId', $wholesaler->id)
            ->assertJsonPath('lineas.0.warehouse', 'CDMX');

        $this->assertDatabaseHas('quote_request_lines', [
            'request_id' => $request->id,
            'reference_cost' => 28500.50,
            'selected_wholesaler_id' => $wholesaler->id,
            'warehouse' => 'CDMX',
        ]);
    }
}
