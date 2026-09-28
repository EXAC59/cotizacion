<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('quote_lines', 'unit')) {
            Schema::table('quote_lines', function (Blueprint $table) {
                $table->dropColumn('unit');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('quote_lines', 'unit')) {
            Schema::table('quote_lines', function (Blueprint $table) {
                $table->string('unit', 30)->default('No');
            });
        }
    }
};
