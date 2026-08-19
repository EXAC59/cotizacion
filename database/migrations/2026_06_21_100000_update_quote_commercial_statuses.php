<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotes')) {
            return;
        }

        if (! Schema::hasColumn('quotes', 'invoice_number')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->string('invoice_number', 80)->nullable()->after('sent_at');
            });
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE quotes DROP CONSTRAINT IF EXISTS quotes_status_check');
            DB::statement("ALTER TABLE quotes ALTER COLUMN status SET DEFAULT 'en_elaboracion'");
        }

        foreach (config('quotes.legacy_status_map', []) as $old => $new) {
            DB::table('quotes')->where('status', $old)->update(['status' => $new]);
        }

        DB::table('quotes')
            ->whereNotIn('status', config('quotes.statuses', []))
            ->update(['status' => config('quotes.default_status', 'en_elaboracion')]);

        if ($driver === 'pgsql') {
            $statuses = implode("', '", config('quotes.statuses', []));
            DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_status_check CHECK (status IN ('{$statuses}'))");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotes')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE quotes DROP CONSTRAINT IF EXISTS quotes_status_check');
            DB::statement("ALTER TABLE quotes ALTER COLUMN status SET DEFAULT 'pendiente'");

            $legacyStatuses = ['pendiente', 'en_revision', 'enviada', 'aprobada', 'rechazada', 'comprada'];
            $statuses = implode("', '", $legacyStatuses);
            DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_status_check CHECK (status IN ('{$statuses}'))");
        }

        if (Schema::hasColumn('quotes', 'invoice_number')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->dropColumn('invoice_number');
            });
        }
    }
};
