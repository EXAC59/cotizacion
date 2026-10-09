<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE quote_requests IN ACCESS EXCLUSIVE MODE');
            }

            $sequence = 0;
            foreach (DB::table('quote_requests')->orderBy('created_at')->orderBy('id')->cursor() as $request) {
                $sequence++;
                DB::table('quote_requests')
                    ->where('id', $request->id)
                    ->update(['folio' => sprintf('SC-%06d', $sequence)]);
            }

            Schema::table('quote_requests', function (Blueprint $table) {
                $table->dropIndex('quote_requests_folio_index');
                $table->unique('folio', 'quote_requests_folio_unique');
            });
        });
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->dropUnique('quote_requests_folio_unique');
            $table->index('folio');
        });
    }
};
