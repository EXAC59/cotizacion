<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_user_views', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('first_opened_at');
            $table->timestamp('last_opened_at');
            $table->unique(['quote_id', 'user_id']);
            $table->index(['user_id', 'quote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_user_views');
    }
};
