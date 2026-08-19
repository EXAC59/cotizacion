<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('id')->primary();
                $table->decimal('default_margin_percent', 5, 2)->default(30);
                $table->decimal('tax_percent', 5, 2)->default(16);
                $table->unsignedSmallInteger('quote_validity_days')->default(15);
                $table->integer('min_stock_alert')->default(5);
                $table->char('currency_code', 3)->default('MXN');
                $table->string('company_name')->nullable();
                $table->string('company_rfc', 20)->nullable();
                $table->timestamp('updated_at')->useCurrent();
            });

            DB::table('app_settings')->insert([
                'id' => 1,
                'default_margin_percent' => config('quote_pricing.default_margin_percent', 30),
                'tax_percent' => config('quote_pricing.default_tax_percent', 16),
                'quote_validity_days' => config('quote_pricing.default_validity_days', 15),
                'min_stock_alert' => 5,
                'currency_code' => 'MXN',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
