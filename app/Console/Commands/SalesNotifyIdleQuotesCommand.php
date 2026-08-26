<?php

namespace App\Console\Commands;

use App\Services\Sales\SalesNotificationService;
use Illuminate\Console\Command;

class SalesNotifyIdleQuotesCommand extends Command
{
    protected $signature = 'sales:notify-idle-quotes {--limit=100 : Máximo de cotizaciones a revisar}';

    protected $description = 'Crea avisos automáticos a ventas por cotizaciones en elaboración sin avance (≥ N días)';

    public function handle(SalesNotificationService $notifications): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $result = $notifications->autoNotifyIdleQuotes($limit);

        $this->info(sprintf(
            'Avisos automáticos: %d creados, %d omitidos.',
            $result['created'],
            $result['skipped'],
        ));

        foreach ($result['notifiedQuoteIds'] as $quoteId) {
            $this->line("  quote={$quoteId}");
        }

        if ($result['created'] > 0) {
            logger()->info('sales:notify-idle-quotes', $result);
        }

        return self::SUCCESS;
    }
}
