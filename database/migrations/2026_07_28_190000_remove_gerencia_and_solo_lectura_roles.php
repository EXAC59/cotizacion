<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Roles que ya no se usan en el producto. */
    private const REMOVED_SLUGS = ['gerencia', 'solo_lectura'];

    public function up(): void
    {
        $roleIds = DB::table('roles')
            ->whereIn('slug', self::REMOVED_SLUGS)
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        // Reasignar usuarios huérfanos al rol ventas si existiera; si no, dejar null.
        $fallbackRoleId = DB::table('roles')->where('slug', 'ventas')->value('id');

        DB::table('users')
            ->whereIn('role_id', $roleIds)
            ->update(['role_id' => $fallbackRoleId]);

        if (DB::getSchemaBuilder()->hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        }

        DB::table('roles')->whereIn('id', $roleIds)->delete();
    }

    public function down(): void
    {
        // No recreamos gerencia / solo_lectura: fueron retirados a propósito.
    }
};
