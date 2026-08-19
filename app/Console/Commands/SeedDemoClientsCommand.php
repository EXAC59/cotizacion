<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;

class SeedDemoClientsCommand extends Command
{
    protected $signature = 'clients:seed-demo';

    protected $description = 'Inserta o actualiza los 3 clientes demo con UUID fijos';

    public function handle(): int
    {
        $demos = [
            [
                'id' => 'a1000001-0001-4000-8000-000000000001',
                'company' => 'ACME Tecnología S.A. de C.V.',
                'rfc' => 'ACM010101ABC',
                'address' => 'Av. Reforma 100, CDMX',
                'contact_name' => 'Juan Pérez',
                'email' => 'compras@acme.mx',
                'whatsapp' => '+52 55 1234 5678',
                'payment_terms' => '30 días',
            ],
            [
                'id' => 'a1000001-0001-4000-8000-000000000002',
                'company' => 'Redes del Norte',
                'rfc' => 'RDN020202XYZ',
                'address' => 'Monterrey, NL',
                'contact_name' => 'Laura Martínez',
                'email' => 'laura@redesnorte.com',
                'whatsapp' => '+52 81 9876 5432',
                'payment_terms' => 'Contado',
            ],
            [
                'id' => 'a1000001-0001-4000-8000-000000000003',
                'company' => 'Grupo Industrial Vega',
                'rfc' => 'GIV030303VEG',
                'address' => 'Guadalajara, JAL',
                'contact_name' => 'Roberto Sánchez',
                'email' => 'roberto@vegaindustrial.mx',
                'whatsapp' => '+52 33 5555 1212',
                'payment_terms' => '45 días',
            ],
        ];

        foreach ($demos as $demo) {
            Client::query()->updateOrCreate(
                ['id' => $demo['id']],
                $demo,
            );
        }

        $this->info('Clientes demo: '.Client::query()->count('*'));

        return self::SUCCESS;
    }
}
