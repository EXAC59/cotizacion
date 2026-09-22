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

        DB::statement('DROP INDEX IF EXISTS sales_notifications_unread_dedupe_uidx');
        DB::statement(
            'CREATE UNIQUE INDEX sales_notifications_unread_dedupe_uidx
             ON sales_notifications (quote_id, reason_code, audience, COALESCE(recipient_id, 0))
             WHERE read_at IS NULL'
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS sales_notifications_unread_dedupe_uidx');
        DB::statement(
            'CREATE UNIQUE INDEX sales_notifications_unread_dedupe_uidx
             ON sales_notifications (quote_id, reason_code, audience)
             WHERE read_at IS NULL'
        );
    }
};
