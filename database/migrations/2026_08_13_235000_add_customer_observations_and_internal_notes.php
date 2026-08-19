<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('quotes', 'customer_observations')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->text('customer_observations')->default('')->after('notes');
            });
        }

        if (! Schema::hasTable('quote_internal_notes')) {
            Schema::create('quote_internal_notes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('quote_id')->constrained('quotes')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('body');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['quote_id', 'created_at']);
            });

            DB::table('quotes')
                ->whereNotNull('notes')
                ->where('notes', '!=', '')
                ->orderBy('created_at')
                ->get(['id', 'created_by', 'notes', 'created_at'])
                ->each(function ($quote): void {
                    DB::table('quote_internal_notes')->insert([
                        'id' => (string) Str::uuid(),
                        'quote_id' => $quote->id,
                        'user_id' => $quote->created_by,
                        'body' => $quote->notes,
                        'created_at' => $quote->created_at ?? now(),
                    ]);
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_internal_notes');
        if (Schema::hasColumn('quotes', 'customer_observations')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->dropColumn('customer_observations');
            });
        }
    }
};
