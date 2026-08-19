<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        Schema::table('app_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('app_settings', 'company_address')) {
                $table->text('company_address')->nullable()->after('company_rfc');
            }
            if (! Schema::hasColumn('app_settings', 'company_phone')) {
                $table->string('company_phone', 40)->nullable()->after('company_address');
            }
            if (! Schema::hasColumn('app_settings', 'company_email')) {
                $table->string('company_email', 120)->nullable()->after('company_phone');
            }
            if (! Schema::hasColumn('app_settings', 'company_website')) {
                $table->string('company_website', 255)->nullable()->after('company_email');
            }
            if (! Schema::hasColumn('app_settings', 'quote_terms')) {
                $table->text('quote_terms')->nullable()->after('company_website');
            }
            if (! Schema::hasColumn('app_settings', 'logo_path')) {
                $table->string('logo_path', 255)->nullable()->after('quote_terms');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        Schema::table('app_settings', function (Blueprint $table) {
            $columns = [
                'company_address',
                'company_phone',
                'company_email',
                'company_website',
                'quote_terms',
                'logo_path',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('app_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
