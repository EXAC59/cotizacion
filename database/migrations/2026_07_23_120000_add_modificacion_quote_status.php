<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotes')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE quotes DROP CONSTRAINT IF EXISTS quotes_status_check');

        $statuses = implode("', '", config('quotes.statuses', [
            'solicitud_cotizaciones',
            'en_elaboracion',
            'pendiente_envio',
            'enviada',
            'modificacion',
            'aceptada',
            'facturada',
        ]));

        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_status_check CHECK (status IN ('{$statuses}'))");
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotes')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        DB::table('quotes')
            ->where('status', 'modificacion')
            ->update(['status' => 'enviada']);

        DB::statement('ALTER TABLE quotes DROP CONSTRAINT IF EXISTS quotes_status_check');

        $legacy = [
            'solicitud_cotizaciones',
            'en_elaboracion',
            'pendiente_envio',
            'enviada',
            'aceptada',
            'facturada',
        ];
        $statuses = implode("', '", $legacy);
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_status_check CHECK (status IN ('{$statuses}'))");
    }
};
