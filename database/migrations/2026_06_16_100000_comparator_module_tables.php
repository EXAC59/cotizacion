<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comparator_settings')) {
            Schema::create('comparator_settings', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->json('weights')->nullable();
                $table->json('warehouse_priority')->nullable();
                $table->json('preferred_wholesaler_ids')->nullable();
                $table->decimal('import_penalty', 8, 4)->default(0.08);
                $table->decimal('lead_day_penalty', 8, 4)->default(0.005);
                $table->unsignedInteger('min_stock_threshold')->default(1);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_comparator_preferences')) {
            Schema::create('user_comparator_preferences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('preferred_warehouse', 20)->default('CDMX');
                $table->boolean('auto_apply_best')->default(false);
                $table->json('preferred_wholesaler_ids')->nullable();
                $table->timestamps();

                $table->unique('user_id');
            });
        }

        Schema::table('quote_request_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('quote_request_lines', 'reference_cost')) {
                $table->decimal('reference_cost', 14, 2)->nullable()->after('unit');
            }
            if (! Schema::hasColumn('quote_request_lines', 'selected_wholesaler_id')) {
                $table->foreignUuid('selected_wholesaler_id')->nullable()->after('reference_cost')
                    ->constrained('wholesalers')->nullOnDelete();
            }
            if (! Schema::hasColumn('quote_request_lines', 'warehouse')) {
                $table->string('warehouse', 20)->nullable()->after('selected_wholesaler_id');
            }
            if (! Schema::hasColumn('quote_request_lines', 'comparator_offers_json')) {
                $table->json('comparator_offers_json')->nullable()->after('warehouse');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quote_request_lines', function (Blueprint $table) {
            if (Schema::hasColumn('quote_request_lines', 'comparator_offers_json')) {
                $table->dropColumn('comparator_offers_json');
            }
            if (Schema::hasColumn('quote_request_lines', 'warehouse')) {
                $table->dropColumn('warehouse');
            }
            if (Schema::hasColumn('quote_request_lines', 'selected_wholesaler_id')) {
                $table->dropConstrainedForeignId('selected_wholesaler_id');
            }
            if (Schema::hasColumn('quote_request_lines', 'reference_cost')) {
                $table->dropColumn('reference_cost');
            }
        });

        Schema::dropIfExists('user_comparator_preferences');
        Schema::dropIfExists('comparator_settings');
    }
};
