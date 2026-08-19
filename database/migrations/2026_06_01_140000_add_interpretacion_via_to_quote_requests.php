<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('quote_requests', 'interpretacion_via')) {
                $table->string('interpretacion_via', 20)->nullable()->after('error_message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            if (Schema::hasColumn('quote_requests', 'interpretacion_via')) {
                $table->dropColumn('interpretacion_via');
            }
        });
    }
};
