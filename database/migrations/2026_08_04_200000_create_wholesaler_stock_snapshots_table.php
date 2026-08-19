<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wholesaler_stock_snapshots')) {
            return;
        }

        Schema::create('wholesaler_stock_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('wholesaler_id')->constrained('wholesalers')->cascadeOnDelete();
            $table->string('part_number', 80);
            $table->string('product_name');
            $table->integer('stock')->default(0);
            $table->string('warehouse', 120)->default('');
            $table->string('source', 40)->default('poll');
            $table->timestamp('polled_at');
            $table->timestamps();

            $table->unique(
                ['wholesaler_id', 'part_number', 'warehouse'],
                'wholesaler_stock_snapshots_unique_sku_wh',
            );
            $table->index(['polled_at', 'stock']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesaler_stock_snapshots');
    }
};
