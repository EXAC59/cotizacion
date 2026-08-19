<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardUsersSeeder extends Seeder
{
    /**
     * Usuarios alineados con las cuentas demo del frontend (RBAC local).
     *
     * @var list<array{name: string, username: string, email: string, password: string, role: string, folio_code: string}>
     */
    private const DEMO_USERS = [
        ['name' => 'Administrador del Sistema', 'username' => 'admin', 'email' => 'admin@cotizacion.test', 'password' => 'admin123', 'role' => 'administrador', 'folio_code' => 'ADMIN'],
        ['name' => 'Luis Ramírez', 'username' => 'compras', 'email' => 'compras@cotizacion.test', 'password' => 'compras123', 'role' => 'gerente_compras', 'folio_code' => 'LUIS'],
        ['name' => 'María González', 'username' => 'maria', 'email' => 'maria@empresa.com', 'password' => 'demo', 'role' => 'ventas', 'folio_code' => 'MARIA'],
    ];

    public function run(): void
    {
        foreach (self::DEMO_USERS as $demo) {
            $roleId = DB::table('roles')->where('slug', $demo['role'])->value('id');

            $existing = User::query()->where('email', '=', $demo['email'])->first();

            User::query()->updateOrCreate(
                ['email' => $demo['email']],
                [
                    'name' => $demo['name'],
                    'username' => $demo['username'],
                    'password' => $demo['password'],
                    'active' => true,
                    'role_id' => $roleId,
                    'uuid' => $existing?->uuid ?? (string) Str::uuid(),
                    'folio_code' => $demo['folio_code'],
                ],
            );
        }
    }
}
