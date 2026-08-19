<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_comparator_preferences', function (Blueprint $table) {
            if (! Schema::hasColumn('user_comparator_preferences', 'preferred_warehouses')) {
                $table->json('preferred_warehouses')->nullable()->after('preferred_warehouse');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_comparator_preferences', function (Blueprint $table) {
            if (Schema::hasColumn('user_comparator_preferences', 'preferred_warehouses')) {
                $table->dropColumn('preferred_warehouses');
            }
        });
    }
};
