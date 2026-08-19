<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('role_permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('modules')) {
            return;
        }

        $roleId = DB::table('roles')->where('slug', 'ventas')->value('id');
        $moduleId = DB::table('modules')->where('slug', 'mayoristas')->value('id');

        if (! $roleId || ! $moduleId) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('module_id', $moduleId)
            ->pluck('id')
            ->all();

        if ($permissionIds === []) {
            return;
        }

        DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('modules')) {
            return;
        }

        $roleId = DB::table('roles')->where('slug', 'ventas')->value('id');
        $moduleId = DB::table('modules')->where('slug', 'mayoristas')->value('id');

        if (! $roleId || ! $moduleId) {
            return;
        }

        $viewPermissionId = DB::table('permissions')
            ->where('module_id', $moduleId)
            ->where('action', 'view')
            ->value('id');

        if (! $viewPermissionId) {
            return;
        }

        $exists = DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $viewPermissionId)
            ->exists();

        if (! $exists) {
            DB::table('role_permissions')->insert([
                'role_id' => $roleId,
                'permission_id' => $viewPermissionId,
            ]);
        }
    }
};
