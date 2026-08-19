<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            if (Schema::hasTable('quotes')) {
                DB::statement('ALTER TABLE quotes ALTER COLUMN status TYPE VARCHAR(40)');
            }
            if (Schema::hasTable('quote_status_events')) {
                DB::statement('ALTER TABLE quote_status_events ALTER COLUMN from_status TYPE VARCHAR(40)');
                DB::statement('ALTER TABLE quote_status_events ALTER COLUMN to_status TYPE VARCHAR(40)');
            }

            return;
        }

        // sqlite / mysql (tests / local): change via schema when supported
        if (Schema::hasTable('quotes') && $driver === 'mysql') {
            DB::statement('ALTER TABLE quotes MODIFY status VARCHAR(40) NOT NULL');
        }
        if (Schema::hasTable('quote_status_events') && $driver === 'mysql') {
            DB::statement('ALTER TABLE quote_status_events MODIFY from_status VARCHAR(40) NULL');
            DB::statement('ALTER TABLE quote_status_events MODIFY to_status VARCHAR(40) NOT NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            if (Schema::hasTable('quote_status_events')) {
                DB::statement('ALTER TABLE quote_status_events ALTER COLUMN from_status TYPE VARCHAR(20)');
                DB::statement('ALTER TABLE quote_status_events ALTER COLUMN to_status TYPE VARCHAR(20)');
            }
            if (Schema::hasTable('quotes')) {
                DB::statement('ALTER TABLE quotes ALTER COLUMN status TYPE VARCHAR(20)');
            }
        }
    }
};
