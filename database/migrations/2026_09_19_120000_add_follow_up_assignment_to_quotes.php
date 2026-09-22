<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('follow_up_assigned_to')
                ->nullable()
                ->after('follow_up_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('follow_up_assigned_by')
                ->nullable()
                ->after('follow_up_assigned_to')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('follow_up_assigned_at')->nullable()->after('follow_up_assigned_by');

            $table->index(['follow_up_assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['follow_up_assigned_to', 'status']);
            $table->dropConstrainedForeignId('follow_up_assigned_to');
            $table->dropConstrainedForeignId('follow_up_assigned_by');
            $table->dropColumn('follow_up_assigned_at');
        });
    }
};
