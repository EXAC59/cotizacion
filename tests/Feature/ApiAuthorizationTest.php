<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AuthenticatesDemoUsers;
use Tests\TestCase;

class ApiAuthorizationTest extends TestCase
{
    use AuthenticatesDemoUsers;
    use RefreshDatabase;

    #[Test]
    public function ventas_cannot_dispatch_comparator_or_list_mayoristas(): void
    {
        $this->actingAsDemoUser('ventas');

        config(['n8n.comparator_webhook_url' => null]);

        $this->postJson('/api/comparador/disparar', [
            'partNumber' => 'CZ103AL',
            'quantity' => 1,
            'preferredWarehouse' => 'CDMX',
        ])->assertForbidden();

        $this->getJson('/api/mayoristas')->assertForbidden();
    }

    #[Test]
    public function ventas_can_use_ct_autocomplete_endpoint(): void
    {
        $this->actingAsDemoUser('ventas');

        $this->getJson('/api/mayoristas/ct/autocomplete?q=ab')
            ->assertOk()
            ->assertJsonStructure(['data', 'count']);
    }

    #[Test]
    public function ventas_cannot_update_comparator_settings(): void
    {
        $this->actingAsDemoUser('ventas');

        $this->patchJson('/api/configuracion/comparador', [
            'minStockThreshold' => 10,
        ])->assertForbidden();
    }

    #[Test]
    public function ventas_can_view_dashboard(): void
    {
        $this->actingAsDemoUser('ventas');

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('monthlyRealizedProfit', 0)
            ->assertJsonPath('monthlyPotentialProfit', 0)
            ->assertJsonPath('alerts.lowStock', []);
    }

    #[Test]
    public function gerente_compras_can_view_reports(): void
    {
        $this->actingAsDemoUser('gerente_compras');

        $this->getJson('/api/reportes')->assertOk();
    }

    #[Test]
    public function ventas_cannot_update_rbac_permissions(): void
    {
        $this->actingAsDemoUser('ventas');

        $this->putJson('/api/rbac/permissions', [
            'rolePermissions' => [],
        ])->assertForbidden();
    }

    #[Test]
    public function unauthenticated_users_cannot_update_rbac_permissions(): void
    {
        $this->putJson('/api/rbac/permissions', [
            'rolePermissions' => [],
        ])->assertUnauthorized();
    }

    #[Test]
    public function administrator_can_update_rbac_permissions(): void
    {
        $this->actingAsDemoUser('administrador');

        $payload = [
            'rolePermissions' => [
                'administrador' => [
                    'dashboard' => ['view'],
                    'solicitudes' => ['view'],
                    'cotizaciones' => ['view'],
                    'clientes' => ['view'],
                    'mayoristas' => ['view'],
                    'reportes' => ['view'],
                    'configuracion' => ['view', 'edit'],
                    'admin' => ['view', 'manage'],
                ],
                'gerente_compras' => [
                    'dashboard' => ['view'],
                    'solicitudes' => ['view'],
                    'cotizaciones' => [],
                    'clientes' => [],
                    'mayoristas' => [],
                    'reportes' => [],
                    'configuracion' => [],
                    'admin' => [],
                ],
                'ventas' => [
                    'dashboard' => ['view'],
                    'solicitudes' => ['view', 'create'],
                    'cotizaciones' => ['view', 'create', 'edit', 'send'],
                    'clientes' => ['view', 'create'],
                    'mayoristas' => ['view'],
                    'reportes' => [],
                    'configuracion' => ['view'],
                    'admin' => [],
                ],
            ],
        ];

        $this->putJson('/api/rbac/permissions', $payload)
            ->assertOk()
            ->assertJsonPath('rolePermissions.gerente_compras.solicitudes', ['view']);

        $gerenteRoleId = DB::table('roles')->where('slug', 'gerente_compras')->value('id');
        $solicitudesViewId = DB::table('permissions')->where('code', 'solicitudes.view')->value('id');

        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $gerenteRoleId,
            'permission_id' => $solicitudesViewId,
        ]);
    }
}
