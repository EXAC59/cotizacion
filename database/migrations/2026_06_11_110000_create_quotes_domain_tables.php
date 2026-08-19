<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotes')) {
            Schema::create('quotes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('folio', 30)->unique();
                $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();
                $table->foreignUuid('request_id')->nullable()->constrained('quote_requests')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 20)->default('pendiente');
                $table->unsignedSmallInteger('validity_days')->default(15);
                $table->decimal('global_margin_percent', 5, 2)->default(30);
                $table->decimal('tax_percent', 5, 2)->default(16);
                $table->text('notes')->default('');
                $table->decimal('subtotal', 15, 4)->default(0);
                $table->decimal('tax_amount', 15, 4)->default(0);
                $table->decimal('total', 15, 4)->default(0);
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('quote_lines')) {
            Schema::create('quote_lines', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('quote_id')->constrained('quotes')->cascadeOnDelete();
                $table->unsignedSmallInteger('line_order')->default(0);
                $table->decimal('quantity', 12, 4);
                $table->string('product');
                $table->string('part_number', 80)->default('');
                $table->decimal('cost', 15, 4)->default(0);
                $table->decimal('margin_percent', 5, 2)->default(30);
                $table->decimal('sale_price', 15, 4)->default(0);
                $table->decimal('amount', 15, 4)->default(0);
                $table->string('warehouse', 80)->default('');
                $table->foreignUuid('selected_wholesaler_id')->nullable()->constrained('wholesalers')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('quote_line_offers')) {
            Schema::create('quote_line_offers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('quote_line_id')->constrained('quote_lines')->cascadeOnDelete();
                $table->foreignUuid('wholesaler_id')->constrained('wholesalers')->cascadeOnDelete();
                $table->decimal('cost', 15, 4);
                $table->integer('stock')->default(0);
                $table->string('warehouse', 80)->default('');
                $table->unsignedSmallInteger('lead_days')->default(0);
                $table->boolean('is_selected')->default(false);
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['quote_line_id', 'wholesaler_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_line_offers');
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
    }
};
