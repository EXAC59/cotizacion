<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_closing_results', function (Blueprint $table) {
            $table->foreignUuid('quote_id')->primary()->constrained('quotes')->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('invoice', 60)->nullable();
            $table->text('comments')->nullable();
            $table->json('selected_lines')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('request_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_closing_results');
    }
};
