<?php

namespace App\Console\Commands;

use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerLookupService;
use Illuminate\Console\Command;

class WholesalersHealthCommand extends Command
{
    protected $signature = 'wholesalers:health {partNumber : Número de parte conocido para probar CT y CVA}';

    protected $description = 'Prueba CT y CVA y devuelve un resumen operativo sin exponer credenciales';

    public function handle(WholesalerLookupService $lookup): int
    {
        $partNumber = (string) $this->argument('partNumber');
        $failed = 0;

        foreach (['CT', 'CVA'] as $code) {
            $wholesaler = Wholesaler::query()->where('code', $code)->first();
            if ($wholesaler === null) {
                $this->error("{$code}: no existe en la base de datos");
                $failed++;
                continue;
            }

            $startedAt = microtime(true);
            try {
                $offer = $lookup->lookupForWholesaler($wholesaler, $partNumber)[0] ?? null;
                $elapsed = (int) round((microtime(true) - $startedAt) * 1000);
                $error = is_array($offer) ? ($offer['error'] ?? null) : ($offer?->error ?? null);

                if ($error !== null) {
                    $this->warn("{$code}: ERROR ({$elapsed} ms) {$error}");
                    $failed++;
                } else {
                    $this->info("{$code}: OK ({$elapsed} ms)");
                }
            } catch (\Throwable $e) {
                $this->error("{$code}: EXCEPTION {$e->getMessage()}");
                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
