<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable('wholesalers')) {
            return;
        }

        DB::statement('ALTER TABLE wholesalers DROP CONSTRAINT IF EXISTS wholesalers_integration_check');
        DB::statement("ALTER TABLE wholesalers ADD CONSTRAINT wholesalers_integration_check CHECK (integration IN ('api', 'xml', 'csv', 'ftp', 'scraping'))");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable('wholesalers')) {
            return;
        }

        DB::statement('ALTER TABLE wholesalers DROP CONSTRAINT IF EXISTS wholesalers_integration_check');
        DB::statement("ALTER TABLE wholesalers ADD CONSTRAINT wholesalers_integration_check CHECK (integration IN ('api', 'csv', 'ftp', 'scraping'))");
    }
};
