<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->unsignedSmallInteger('id', true);
                $table->string('slug', 40)->unique();
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('modules')) {
            Schema::create('modules', function (Blueprint $table) {
                $table->unsignedSmallInteger('id', true);
                $table->string('slug', 40)->unique();
                $table->string('name', 80);
                $table->unsignedSmallInteger('sort_order')->default(0);
            });
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedSmallInteger('module_id');
                $table->string('action', 20);
                $table->string('code', 60)->unique();
                $table->string('description')->nullable();
                $table->unique(['module_id', 'action']);

                $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->unsignedSmallInteger('role_id');
                $table->unsignedInteger('permission_id');
                $table->primary(['role_id', 'permission_id']);

                $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
                $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            });
        }

        $this->seedCatalog();
        $this->seedDefaultRolePermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('roles');
    }

    private function seedCatalog(): void
    {
        if (DB::table('roles')->exists()) {
            return;
        }

        DB::table('roles')->insert([
            ['slug' => 'administrador', 'name' => 'Administrador', 'description' => 'Acceso total y administración de usuarios', 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'gerente_compras', 'name' => 'Gerente de Compras', 'description' => 'Solicitudes, mayoristas, precios a ventas', 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'ventas', 'name' => 'Personal de Ventas', 'description' => 'Cotizaciones, clientes, envío', 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'gerencia', 'name' => 'Gerencia', 'description' => 'Dashboard y reportes', 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'solo_lectura', 'name' => 'Solo lectura', 'description' => 'Consulta de inventarios y reportes', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('modules')->insert([
            ['slug' => 'dashboard', 'name' => 'Dashboard', 'sort_order' => 1],
            ['slug' => 'solicitudes', 'name' => 'Solicitudes', 'sort_order' => 2],
            ['slug' => 'cotizaciones', 'name' => 'Cotizaciones', 'sort_order' => 3],
            ['slug' => 'clientes', 'name' => 'Clientes', 'sort_order' => 4],
            ['slug' => 'mayoristas', 'name' => 'Mayoristas', 'sort_order' => 5],
            ['slug' => 'reportes', 'name' => 'Reportes', 'sort_order' => 6],
            ['slug' => 'configuracion', 'name' => 'Configuración', 'sort_order' => 7],
            ['slug' => 'admin', 'name' => 'Administración', 'sort_order' => 8],
        ]);

        $moduleIds = DB::table('modules')->pluck('id', 'slug');

        $definitions = [
            ['dashboard', 'view', 'dashboard.view'],
            ['solicitudes', 'view', 'solicitudes.view'],
            ['solicitudes', 'create', 'solicitudes.create'],
            ['solicitudes', 'edit', 'solicitudes.edit'],
            ['solicitudes', 'delete', 'solicitudes.delete'],
            ['cotizaciones', 'view', 'cotizaciones.view'],
            ['cotizaciones', 'create', 'cotizaciones.create'],
            ['cotizaciones', 'edit', 'cotizaciones.edit'],
            ['cotizaciones', 'delete', 'cotizaciones.delete'],
            ['cotizaciones', 'send', 'cotizaciones.send'],
            ['cotizaciones', 'approve', 'cotizaciones.approve'],
            ['cotizaciones', 'edit_margin', 'cotizaciones.edit_margin'],
            ['clientes', 'view', 'clientes.view'],
            ['clientes', 'create', 'clientes.create'],
            ['clientes', 'edit', 'clientes.edit'],
            ['clientes', 'delete', 'clientes.delete'],
            ['clientes', 'import', 'clientes.import'],
            ['mayoristas', 'view', 'mayoristas.view'],
            ['mayoristas', 'edit', 'mayoristas.edit'],
            ['reportes', 'view', 'reportes.view'],
            ['configuracion', 'view', 'configuracion.view'],
            ['configuracion', 'edit', 'configuracion.edit'],
            ['admin', 'view', 'admin.view'],
            ['admin', 'manage', 'admin.manage'],
        ];

        foreach ($definitions as [$moduleSlug, $action, $code]) {
            DB::table('permissions')->insert([
                'module_id' => $moduleIds[$moduleSlug],
                'action' => $action,
                'code' => $code,
            ]);
        }
    }

    private function seedDefaultRolePermissions(): void
    {
        if (DB::table('role_permissions')->exists()) {
            return;
        }

        $defaults = config('rbac.default_role_permissions', []);

        foreach ($defaults as $roleSlug => $modules) {
            if ($roleSlug === 'administrador') {
                $this->grantAllPermissions($roleSlug);

                continue;
            }

            $this->syncRolePermissions($roleSlug, $modules);
        }
    }

    private function grantAllPermissions(string $roleSlug): void
    {
        $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
        if (! $roleId) {
            return;
        }

        $permissionIds = DB::table('permissions')->pluck('id');
        $rows = $permissionIds->map(fn ($permissionId) => [
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ])->all();

        DB::table('role_permissions')->insert($rows);
    }

    /**
     * @param  array<string, list<string>>  $modules
     */
    private function syncRolePermissions(string $roleSlug, array $modules): void
    {
        $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
        if (! $roleId) {
            return;
        }

        $moduleIds = DB::table('modules')->pluck('id', 'slug');
        $permissionIds = [];

        foreach ($modules as $moduleSlug => $actions) {
            if (! isset($moduleIds[$moduleSlug])) {
                continue;
            }

            foreach ($actions as $action) {
                $permissionId = DB::table('permissions')
                    ->where('module_id', $moduleIds[$moduleSlug])
                    ->where('action', $action)
                    ->value('id');

                if ($permissionId) {
                    $permissionIds[] = $permissionId;
                }
            }
        }

        foreach (array_unique($permissionIds) as $permissionId) {
            DB::table('role_permissions')->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }
};
