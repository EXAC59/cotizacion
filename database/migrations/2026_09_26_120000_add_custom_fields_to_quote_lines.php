<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('quote_lines', function (Blueprint $table) {
            $table->string('unit', 30)->default('No');
            $table->boolean('is_custom')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('quote_lines', function (Blueprint $table) {
            $table->dropColumn(['unit', 'is_custom']);
        });
    }
};
