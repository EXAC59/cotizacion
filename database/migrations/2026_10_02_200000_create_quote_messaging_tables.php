<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_conversations', function (Blueprint $table) {
            $table->foreignUuid('quote_id')->primary()->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('purchasing_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stage', 24)->default('en_proceso');
            $table->json('checks')->nullable();
            $table->timestamps();
        });
        Schema::create('quote_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_id')->nullable();
            $table->string('kind', 16)->default('text');
            $table->text('body');
            $table->timestamp('created_at');
            $table->index(['quote_id', 'id']);
            $table->unique(['quote_id', 'sender_id', 'client_id']);
        });
        Schema::create('quote_chat_reads', function (Blueprint $table) {
            $table->foreignUuid('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_message_id')->default(0);
            $table->timestamp('updated_at');
            $table->primary(['quote_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_chat_reads');
        Schema::dropIfExists('quote_chat_messages');
        Schema::dropIfExists('quote_conversations');
    }
};
