<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permitir CSV de todos los almacenes CT+CVA (~350+ caracteres).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comparison_jobs')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE comparison_jobs ALTER COLUMN preferred_warehouse TYPE TEXT');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE comparison_jobs MODIFY preferred_warehouse TEXT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('comparison_jobs')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE comparison_jobs ALTER COLUMN preferred_warehouse TYPE VARCHAR(80) USING LEFT(preferred_warehouse, 80)');

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE comparison_jobs MODIFY preferred_warehouse VARCHAR(80) NULL');
        }
    }
};
