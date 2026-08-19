<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wholesalers')) {
            Schema::create('wholesalers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('code', 30)->unique();
                $table->string('name', 120);
                $table->string('integration', 20);
                $table->boolean('active')->default(true);
                $table->json('config_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inventory_items')) {
            Schema::create('inventory_items', function (Blueprint $table) {
                $table->id();
                $table->string('part_number', 80);
                $table->string('product_name');
                $table->integer('stock')->default(0);
                $table->string('warehouse', 80)->default('');
                $table->foreignUuid('wholesaler_id')->nullable()->constrained('wholesalers')->nullOnDelete();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['part_number', 'warehouse']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('wholesalers');
    }
};
