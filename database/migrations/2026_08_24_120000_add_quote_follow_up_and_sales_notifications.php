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
            $table->string('follow_up_status', 32)->nullable()->after('invoice_number');
            $table->string('follow_up_invoice', 60)->nullable()->after('follow_up_status');
            $table->text('follow_up_comments')->nullable()->after('follow_up_invoice');
            $table->timestamp('follow_up_remind_at')->nullable()->after('follow_up_comments');
            $table->timestamp('follow_up_at')->nullable()->after('follow_up_remind_at');
            $table->foreignId('follow_up_by')->nullable()->after('follow_up_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('sales_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience', 16);
            $table->string('reason_code', 40);
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['audience', 'read_at']);
            $table->index(['recipient_id', 'read_at']);
            $table->index(['quote_id', 'reason_code', 'audience']);
        });

        // Dedupe: un solo aviso no leído por quote+reason+audience (PostgreSQL).
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX sales_notifications_unread_dedupe_uidx
                 ON sales_notifications (quote_id, reason_code, audience)
                 WHERE read_at IS NULL'
            );
        }

        Schema::create('quote_follow_up_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->timestamp('remind_at')->nullable();
            $table->string('invoice', 60)->nullable();
            $table->text('comments')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['quote_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_follow_up_events');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS sales_notifications_unread_dedupe_uidx');
        }

        Schema::dropIfExists('sales_notifications');

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('follow_up_by');
            $table->dropColumn([
                'follow_up_status',
                'follow_up_invoice',
                'follow_up_comments',
                'follow_up_remind_at',
                'follow_up_at',
            ]);
        });
    }
};
