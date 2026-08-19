<?php

namespace App\Console\Commands;

use App\Services\DoclingClient;
use Illuminate\Console\Command;

class DoclingPingCommand extends Command
{
    protected $signature = 'docling:ping';

    protected $description = 'Comprueba que Docling Serve responde (Docker puerto 5001)';

    public function handle(DoclingClient $docling): int
    {
        $this->line('URL: '.$docling->baseUrl());

        $ping = $docling->ping();

        if ($ping['ok']) {
            $this->info('Docling OK (HTTP '.$ping['status'].')');

            return self::SUCCESS;
        }

        $this->error('Docling no responde: '.($ping['error'] ?? 'error desconocido'));
        $this->line('Levanta: docker compose -f docker-compose.lectura.yml up -d');

        return self::FAILURE;
    }
}
