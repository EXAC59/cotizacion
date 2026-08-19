<?php

namespace App\Console\Commands;

use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Console\Command;

class SolicitudAlertarAtascadasCommand extends Command
{
    protected $signature = 'solicitud:alertar-atascadas';

    protected $description = 'Registra en log las solicitudes en estado procesando por más del umbral configurado';

    public function handle(DashboardAnalyticsService $analytics): int
    {
        $stuck = $analytics->stuckProcessingRequests();

        if ($stuck === []) {
            $this->info('Sin solicitudes atascadas en procesando.');

            return self::SUCCESS;
        }

        $this->warn('Solicitudes atascadas: '.count($stuck));

        foreach ($stuck as $row) {
            $message = sprintf(
                'id=%s archivo=%s minutos=%d cliente=%s',
                $row['id'],
                $row['fileName'] ?? '—',
                $row['minutesStuck'],
                $row['clientName'] ?? '—',
            );
            $this->line($message);
            logger()->warning('Solicitud atascada en procesando', $row);
        }

        return self::SUCCESS;
    }
}
