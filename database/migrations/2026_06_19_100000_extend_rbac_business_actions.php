<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        if (! DB::table('roles')->where('slug', 'solo_lectura')->exists()) {
            DB::table('roles')->insert([
                'slug' => 'solo_lectura',
                'name' => 'Solo lectura',
                'description' => 'Consulta de inventarios y reportes',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $moduleIds = DB::table('modules')->pluck('id', 'slug');

        $newPermissions = [
            ['cotizaciones', 'approve', 'cotizaciones.approve'],
            ['cotizaciones', 'edit_margin', 'cotizaciones.edit_margin'],
            ['clientes', 'import', 'clientes.import'],
        ];

        foreach ($newPermissions as [$moduleSlug, $action, $code]) {
            if (! isset($moduleIds[$moduleSlug])) {
                continue;
            }

            $exists = DB::table('permissions')
                ->where('module_id', $moduleIds[$moduleSlug])
                ->where('action', $action)
                ->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'module_id' => $moduleIds[$moduleSlug],
                    'action' => $action,
                    'code' => $code,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->whereIn('code', [
            'cotizaciones.approve',
            'cotizaciones.edit_margin',
            'clientes.import',
        ])->delete();

        if (Schema::hasTable('roles')) {
            $roleId = DB::table('roles')->where('slug', 'solo_lectura')->value('id');
            if ($roleId && Schema::hasTable('role_permissions')) {
                DB::table('role_permissions')->where('role_id', $roleId)->delete();
            }
            DB::table('roles')->where('slug', 'solo_lectura')->delete();
        }
    }
};
