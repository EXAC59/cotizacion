<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AuthenticatesDemoUsers;
use Tests\TestCase;

class RbacPermissionsTest extends TestCase
{
    use AuthenticatesDemoUsers;
    use RefreshDatabase;

    #[Test]
    public function it_returns_role_permission_matrix(): void
    {
        $this->actingAsDemoUser('ventas');

        $response = $this->getJson('/api/rbac/permissions');

        $response->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonStructure([
                'rolePermissions' => [
                    'administrador' => ['dashboard', 'solicitudes'],
                    'gerente_compras' => ['dashboard', 'solicitudes'],
                    'ventas' => ['dashboard', 'solicitudes'],
                ],
            ])
            ->assertJsonPath('rolePermissions.gerente_compras.reportes.0', 'view');

        $gerenteCotizaciones = $response->json('rolePermissions.gerente_compras.cotizaciones');
        $this->assertContains('approve', $gerenteCotizaciones);
        $this->assertContains('edit_margin', $gerenteCotizaciones);

        $ventasCotizaciones = $response->json('rolePermissions.ventas.cotizaciones');
        $this->assertContains('send', $ventasCotizaciones);
        $this->assertNotContains('approve', $ventasCotizaciones);
        $this->assertNotContains('edit_margin', $ventasCotizaciones);

        $ventasMayoristas = $response->json('rolePermissions.ventas.mayoristas');
        $this->assertSame([], $ventasMayoristas);

        $this->assertArrayNotHasKey('gerencia', $response->json('rolePermissions'));
        $this->assertArrayNotHasKey('solo_lectura', $response->json('rolePermissions'));
    }

    #[Test]
    public function it_updates_non_admin_role_permissions(): void
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
                    'configuracion' => ['view'],
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

        $response = $this->putJson('/api/rbac/permissions', $payload);

        $response->assertOk()
            ->assertJsonPath('rolePermissions.gerente_compras.solicitudes', ['view']);

        $ventasCotizaciones = $response->json('rolePermissions.ventas.cotizaciones');
        $this->assertEqualsCanonicalizing(
            ['view', 'create', 'edit', 'send'],
            $ventasCotizaciones,
        );

        $gerenteRoleId = DB::table('roles')->where('slug', 'gerente_compras')->value('id');
        $solicitudesViewId = DB::table('permissions')->where('code', 'solicitudes.view')->value('id');

        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $gerenteRoleId,
            'permission_id' => $solicitudesViewId,
        ]);

        $this->assertDatabaseMissing('role_permissions', [
            'role_id' => $gerenteRoleId,
            'permission_id' => DB::table('permissions')->where('code', 'solicitudes.create')->value('id'),
        ]);
    }

    #[Test]
    public function it_resets_permissions_to_defaults(): void
    {
        $this->actingAsDemoUser('administrador');

        $this->putJson('/api/rbac/permissions', [
            'rolePermissions' => [
                'administrador' => ['dashboard' => ['view'], 'solicitudes' => ['view'], 'cotizaciones' => ['view'], 'clientes' => ['view'], 'mayoristas' => ['view'], 'reportes' => ['view'], 'configuracion' => ['view'], 'admin' => ['view', 'manage']],
                'gerente_compras' => ['dashboard' => [], 'solicitudes' => [], 'cotizaciones' => [], 'clientes' => [], 'mayoristas' => [], 'reportes' => [], 'configuracion' => [], 'admin' => []],
                'ventas' => ['dashboard' => [], 'solicitudes' => [], 'cotizaciones' => [], 'clientes' => [], 'mayoristas' => [], 'reportes' => [], 'configuracion' => [], 'admin' => []],
            ],
        ])->assertOk();

        $response = $this->postJson('/api/rbac/permissions/reset');

        $response->assertOk()
            ->assertJsonPath('rolePermissions.gerente_compras.reportes.0', 'view');

        $ventasCotizaciones = $response->json('rolePermissions.ventas.cotizaciones');
        $this->assertContains('send', $ventasCotizaciones);

        $this->assertArrayNotHasKey('solo_lectura', $response->json('rolePermissions'));
    }
}
