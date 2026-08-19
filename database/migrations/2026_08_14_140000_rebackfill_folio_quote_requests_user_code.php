<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = strtoupper(trim((string) config('solicitudes.folio_prefix', 'SOL')) ?: 'SOL');
        $pad = max(1, (int) config('solicitudes.folio_sequence_pad', 4));

        $users = User::query()
            ->whereNotNull('id')
            ->get(['id', 'name', 'folio_code'])
            ->mapWithKeys(fn (User $u) => [$u->id => $u->resolveQuoteFolioCode()]);

        $rows = DB::table('quote_requests')
            ->orderBy('created_at')
            ->get(['id', 'created_by']);

        $maxByPrefix = [];

        foreach ($rows as $row) {
            $code = $users[$row->created_by] ?? null;
            $folioPrefix = $code !== null
                ? "{$prefix}-{$code}"
                : "{$prefix}-".now()->format('Y');
            $maxByPrefix[$folioPrefix] = ($maxByPrefix[$folioPrefix] ?? 0) + 1;

            DB::table('quote_requests')
                ->where('id', $row->id)
                ->update([
                    'folio' => sprintf('%s-%0'.$pad.'d', $folioPrefix, $maxByPrefix[$folioPrefix]),
                ]);
        }
    }

    public function down(): void
    {
        DB::table('quote_requests')
            ->whereNotNull('folio')
            ->update(['folio' => null]);
    }
};
