<?php

namespace App\Console\Commands;

use App\Services\Sales\SalesNotificationService;
use Illuminate\Console\Command;

class SalesEscalatePurchaseRequestsCommand extends Command
{
    protected $signature = 'sales:escalate-purchase-requests {--limit=200}';

    protected $description = 'Notifica a Administración solicitudes de cotización que Compras no ha tomado';

    public function handle(SalesNotificationService $notifications): int
    {
        $result = $notifications->escalateUnclaimedPurchaseRequests((int) $this->option('limit'));
        $this->info("Solicitudes escaladas: {$result['escalated']}. Avisos creados: {$result['created']}.");

        if ($result['escalated'] > 0) {
            logger()->warning('sales:escalate-purchase-requests', $result);
        }

        return self::SUCCESS;
    }
}
