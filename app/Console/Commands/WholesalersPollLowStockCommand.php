<?php

namespace App\Console\Commands;

use App\Services\Wholesalers\LowStock\LowStockPollService;
use Illuminate\Console\Command;

class WholesalersPollLowStockCommand extends Command
{
    protected $signature = 'wholesalers:poll-low-stock
                            {--only= : Codes separados por coma (ej. CVA,CT)}';

    protected $description = 'Consulta periódica de productos con stock bajo por mayorista integrado';

    public function handle(LowStockPollService $poller): int
    {
        $only = null;
        $raw = trim((string) $this->option('only'));
        if ($raw !== '') {
            $only = array_values(array_filter(array_map('trim', explode(',', $raw))));
        }

        $this->info('Poll de stock bajo…');
        $result = $poller->poll($only);

        $this->line('Umbral: '.$result['threshold']);
        $rows = [];
        foreach ($result['wholesalers'] as $row) {
            $rows[] = [
                $row['code'],
                (string) $row['saved'],
                $row['error'] ?? 'ok',
            ];
        }
        if ($rows === []) {
            $this->warn('Sin fuentes registradas o umbral en 0.');
        } else {
            $this->table(['Mayorista', 'Guardados', 'Estado'], $rows);
        }

        return self::SUCCESS;
    }
}
