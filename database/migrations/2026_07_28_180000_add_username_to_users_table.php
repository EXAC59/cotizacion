<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('username', 64)->nullable()->after('email');
            });
        }

        $rows = DB::table('users')->select(['id', 'email', 'username'])->orderBy('id')->get();
        $used = [];
        foreach ($rows as $row) {
            if (is_string($row->username) && trim($row->username) !== '') {
                $used[strtolower(trim($row->username))] = true;

                continue;
            }

            $local = explode('@', (string) $row->email)[0] ?? '';
            $base = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]+/', '', $local));
            if ($base === '') {
                $base = 'user'.$row->id;
            }

            $candidate = $base;
            $n = 1;
            while (isset($used[$candidate])) {
                $candidate = $base.$n;
                $n++;
            }
            $used[$candidate] = true;
            DB::table('users')->where('id', $row->id)->update(['username' => $candidate]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username', 'users_username_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'username')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_unique');
            $table->dropColumn('username');
        });
    }
};
