<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = Schema::getConnection()->getDriverName() === 'pgsql';

        if ($isPgsql) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS "pgcrypto"');
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'uuid')) {
                $table->uuid('uuid')->nullable();
            }
            if (! Schema::hasColumn('users', 'role_id')) {
                $table->unsignedSmallInteger('role_id')->nullable();
            }
            if (! Schema::hasColumn('users', 'active')) {
                $table->boolean('active')->default(true);
            }
        });

        if ($isPgsql) {
            DB::statement('UPDATE users SET uuid = gen_random_uuid() WHERE uuid IS NULL');
            DB::statement('ALTER TABLE users ALTER COLUMN uuid SET NOT NULL');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_uuid_unique ON users(uuid)');
            DB::statement('CREATE INDEX IF NOT EXISTS users_role_id_idx ON users(role_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS users_active_idx ON users(active)');
        } else {
            foreach (DB::table('users')->whereNull('uuid')->pluck('id') as $userId) {
                DB::table('users')->where('id', $userId)->update(['uuid' => (string) \Illuminate\Support\Str::uuid()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'uuid')) {
                $table->dropUnique(['uuid']);
                $table->dropColumn('uuid');
            }
            if (Schema::hasColumn('users', 'role_id')) {
                $table->dropIndex(['role_id']);
                $table->dropColumn('role_id');
            }
            if (Schema::hasColumn('users', 'active')) {
                $table->dropIndex(['active']);
                $table->dropColumn('active');
            }
        });
    }
};
