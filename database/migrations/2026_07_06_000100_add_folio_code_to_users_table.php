<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'folio_code')) {
                $table->string('folio_code', 12)->nullable()->after('name');
            }
        });

        $used = [];
        $rows = DB::table('users')->select(['id', 'name', 'folio_code'])->orderBy('id')->get();

        foreach ($rows as $row) {
            $explicit = $this->sanitizeCode($row->folio_code);
            $base = $explicit ?? $this->deriveCodeFromName($row->name) ?? 'USR'.(string) $row->id;
            $code = $this->ensureUniqueCode($base, $used);

            DB::table('users')->where('id', $row->id)->update(['folio_code' => $code]);
            $used[$code] = true;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('folio_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'folio_code')) {
                $table->dropUnique('users_folio_code_unique');
                $table->dropColumn('folio_code');
            }
        });
    }

    private function ensureUniqueCode(string $candidate, array $used): string
    {
        $base = $this->sanitizeCode($candidate) ?? 'USR';
        if (! isset($used[$base])) {
            return $base;
        }

        for ($attempt = 1; $attempt <= 9999; $attempt++) {
            $suffix = (string) $attempt;
            $maxBaseLength = 12 - strlen($suffix);
            $trimmedBase = substr($base, 0, max(2, $maxBaseLength));
            $next = $this->sanitizeCode($trimmedBase.$suffix);
            if ($next !== null && ! isset($used[$next])) {
                return $next;
            }
        }

        return 'USR'.Str::upper(Str::random(9));
    }

    private function deriveCodeFromName(?string $name): ?string
    {
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $firstWord = trim(Str::of($name)->squish()->explode(' ')->first() ?? '');
        $firstCode = $this->sanitizeCode(Str::upper(Str::ascii($firstWord)));
        if ($firstCode !== null) {
            return $firstCode;
        }

        return $this->sanitizeCode(Str::upper(Str::ascii($name)));
    }

    private function sanitizeCode(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = Str::upper((string) preg_replace('/[^A-Z0-9]+/i', '', Str::ascii($value)));
        if ($clean === '') {
            return null;
        }

        $clean = substr($clean, 0, 12);
        $length = strlen($clean);

        if ($length < 2 || $length > 12) {
            return null;
        }

        return $clean;
    }
};
