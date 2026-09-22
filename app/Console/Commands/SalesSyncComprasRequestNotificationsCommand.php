<?php

namespace App\Console\Commands;

use App\Services\Sales\SalesNotificationService;
use Illuminate\Console\Command;

class SalesSyncComprasRequestNotificationsCommand extends Command
{
    protected $signature = 'sales:sync-compras-request-notifications
        {--limit=500 : Máximo de cotizaciones en solicitud que se revisarán}';

    protected $description = 'Crea avisos faltantes para Compras por cotizaciones en estado Solicitud de cotización';

    public function handle(SalesNotificationService $notifications): int
    {
        $result = $notifications->syncComprasRequestNotifications((int) $this->option('limit'));

        $this->info(sprintf(
            'Solicitudes revisadas: %d. Avisos creados: %d.',
            $result['reviewed'],
            $result['created'],
        ));

        if ($result['created'] > 0) {
            logger()->info('sales:sync-compras-request-notifications', $result);
        }

        return self::SUCCESS;
    }
}
