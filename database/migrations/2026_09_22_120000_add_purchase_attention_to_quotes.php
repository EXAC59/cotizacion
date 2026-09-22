<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('purchase_assigned_to')
                ->nullable()
                ->after('last_activity_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('purchase_assigned_by')
                ->nullable()
                ->after('purchase_assigned_to')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('purchase_assigned_at')->nullable()->after('purchase_assigned_by');
            $table->timestamp('purchase_completed_at')->nullable()->after('purchase_assigned_at');
            $table->timestamp('purchase_escalated_at')->nullable()->after('purchase_completed_at');

            $table->index(['status', 'purchase_assigned_to']);
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropIndex(['status', 'purchase_assigned_to']);
            $table->dropConstrainedForeignId('purchase_assigned_to');
            $table->dropConstrainedForeignId('purchase_assigned_by');
            $table->dropColumn([
                'purchase_assigned_at',
                'purchase_completed_at',
                'purchase_escalated_at',
            ]);
        });
    }
};
