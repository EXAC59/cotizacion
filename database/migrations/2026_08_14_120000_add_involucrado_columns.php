<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->text('involucrado')->nullable()->after('updated_at');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->text('involucrado')->nullable()->after('customer_observations');
        });
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->dropColumn('involucrado');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('involucrado');
        });
    }
};