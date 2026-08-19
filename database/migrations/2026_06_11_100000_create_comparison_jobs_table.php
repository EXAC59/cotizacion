<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comparison_jobs')) {
            Schema::create('comparison_jobs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('part_number', 80);
                $table->decimal('quantity', 12, 4)->default(1);
                $table->string('preferred_warehouse', 80)->nullable();
                $table->string('status', 20)->default('procesando');
                $table->json('context')->nullable();
                $table->json('offers')->nullable();
                $table->json('best')->nullable();
                $table->text('error_message')->nullable();
                $table->string('n8n_execution_id', 100)->nullable();
                $table->boolean('demo_mode')->default(false);
                $table->timestamps();

                $table->index('status');
                $table->index('part_number');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comparison_jobs');
    }
};
