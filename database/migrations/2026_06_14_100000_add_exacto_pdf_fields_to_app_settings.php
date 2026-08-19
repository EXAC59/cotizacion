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
            $columns = [
                'company_legal_name' => fn () => $table->string('company_legal_name')->nullable()->after('company_name'),
                'company_tagline' => fn () => $table->string('company_tagline')->nullable()->after('company_legal_name'),
                'company_branches' => fn () => $table->text('company_branches')->nullable()->after('company_address'),
                'quote_signature_name' => fn () => $table->string('quote_signature_name')->nullable()->after('quote_terms'),
                'quote_signature_email' => fn () => $table->string('quote_signature_email', 120)->nullable()->after('quote_signature_name'),
                'quote_footer_address' => fn () => $table->text('quote_footer_address')->nullable()->after('quote_signature_email'),
                'bank_accounts' => fn () => $table->json('bank_accounts')->nullable()->after('quote_footer_address'),
            ];

            foreach ($columns as $name => $add) {
                if (! Schema::hasColumn('app_settings', $name)) {
                    $add();
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        Schema::table('app_settings', function (Blueprint $table) {
            foreach ([
                'company_legal_name',
                'company_tagline',
                'company_branches',
                'quote_signature_name',
                'quote_signature_email',
                'quote_footer_address',
                'bank_accounts',
            ] as $column) {
                if (Schema::hasColumn('app_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
