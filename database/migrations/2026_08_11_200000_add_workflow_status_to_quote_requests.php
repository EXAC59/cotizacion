<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('quote_requests', 'workflow_status')) {
                $table->string('workflow_status', 30)->default('en_elaboracion')->after('status');
            }
        });

        // Backfill: solicitudes que ya tienen cotización vinculada → enviada.
        DB::table('quote_requests')
            ->whereIn('id', function ($query) {
                $query->select('request_id')->from('quotes')->whereNotNull('request_id');
            })
            ->update(['workflow_status' => 'enviada']);
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            if (Schema::hasColumn('quote_requests', 'workflow_status')) {
                $table->dropColumn('workflow_status');
            }
        });
    }
};
