<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clients')) {
            Schema::create('clients', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('company');
                $table->string('rfc', 20)->default('');
                $table->text('address')->default('');
                $table->string('contact_name')->default('');
                $table->string('email')->default('');
                $table->string('whatsapp', 30)->default('');
                $table->string('payment_terms', 120)->default('');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('quote_requests')) {
            Schema::create('quote_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('source', 10);
                $table->string('status', 20)->default('pendiente');
                $table->string('file_name')->nullable();
                $table->string('file_path', 500)->nullable();
                $table->text('raw_text')->nullable();
                $table->string('n8n_workflow_id', 100)->nullable();
                $table->string('interpretacion_via', 20)->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('pricing_uploaded_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('quote_request_lines')) {
            Schema::create('quote_request_lines', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('request_id')->constrained('quote_requests')->cascadeOnDelete();
                $table->unsignedSmallInteger('line_order')->default(0);
                $table->decimal('quantity', 12, 4);
                $table->string('product');
                $table->string('part_number', 80)->default('');
                $table->string('brand', 80)->default('');
                $table->text('description')->default('');
                $table->string('unit', 20)->default('pza');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_request_lines');
        Schema::dropIfExists('quote_requests');
        Schema::dropIfExists('clients');
    }
};
