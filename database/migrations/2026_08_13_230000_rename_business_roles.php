<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->where('slug', 'gerente_compras')->update(['name' => 'Compras']);
        DB::table('roles')->where('slug', 'ventas')->update(['name' => 'Ventas']);
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'gerente_compras')->update(['name' => 'Gerente de Compras']);
        DB::table('roles')->where('slug', 'ventas')->update(['name' => 'Personal de Ventas']);
    }
};
