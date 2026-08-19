<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->string('folio', 30)->nullable()->after('id');
            $table->index('folio');
        });

        $this->backfillFolios();
    }

    public function down(): void
    {
        Schema::table('quote_requests', function (Blueprint $table) {
            $table->dropIndex(['folio']);
            $table->dropColumn('folio');
        });
    }

    private function backfillFolios(): void
    {
        $prefix = strtoupper(trim((string) config('solicitudes.folio_prefix', 'SOL')) ?: 'SOL');
        $pad = max(1, (int) config('solicitudes.folio_sequence_pad', 4));
        $year = now()->format('Y');

        $rows = DB::table('quote_requests')
            ->whereNull('folio')
            ->orderBy('created_at')
            ->get(['id']);

        $maxSeq = 0;
        $existing = DB::table('quote_requests')
            ->where('folio', 'like', $prefix.'-'.$year.'-%')
            ->pluck('folio');
        foreach ($existing as $folio) {
            if (preg_match('/-(\d+)$/', (string) $folio, $matches)) {
                $maxSeq = max($maxSeq, (int) $matches[1]);
            }
        }

        foreach ($rows as $row) {
            $maxSeq++;
            DB::table('quote_requests')
                ->where('id', $row->id)
                ->update(['folio' => sprintf('%s-%s-%0'.$pad.'d', $prefix, $year, $maxSeq)]);
        }
    }
};