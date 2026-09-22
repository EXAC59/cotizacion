<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('last_opened_at');
            $table->foreignId('last_activity_by')->nullable()->after('last_activity_at')->constrained('users')->nullOnDelete();
            $table->index(['status', 'last_activity_at']);
        });

        DB::table('quotes')
            ->whereNull('last_activity_at')
            ->update([
                'last_activity_at' => DB::raw('updated_at'),
                'last_activity_by' => DB::raw('created_by'),
            ]);
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['status', 'last_activity_at']);
            $table->dropConstrainedForeignId('last_activity_by');
            $table->dropColumn('last_activity_at');
        });
    }
};
