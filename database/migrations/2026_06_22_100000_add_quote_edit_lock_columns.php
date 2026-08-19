<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotes')) {
            return;
        }

        Schema::table('quotes', function (Blueprint $table) {
            if (! Schema::hasColumn('quotes', 'locked_by')) {
                $table->foreignId('locked_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('quotes', 'locked_at')) {
                $table->timestamp('locked_at')->nullable()->after('locked_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotes')) {
            return;
        }

        Schema::table('quotes', function (Blueprint $table) {
            if (Schema::hasColumn('quotes', 'locked_by')) {
                $table->dropForeign(['locked_by']);
                $table->dropColumn('locked_by');
            }
            if (Schema::hasColumn('quotes', 'locked_at')) {
                $table->dropColumn('locked_at');
            }
        });
    }
};
