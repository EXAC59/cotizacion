<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MailVerifyCommand extends Command
{
    protected $signature = 'mail:verify {email? : Destinatario de prueba}';

    protected $description = 'Verifica la configuración SMTP enviando (o simulando) un correo de prueba';

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        $host = (string) config('mail.mailers.smtp.host');
        $from = (string) config('mail.from.address');
        $to = $this->argument('email') ?: $from;

        $this->table(['Clave', 'Valor'], [
            ['MAIL_MAILER', $mailer],
            ['MAIL_HOST', $host !== '' ? $host : '(vacío)'],
            ['MAIL_PORT', (string) config('mail.mailers.smtp.port')],
            ['MAIL_FROM', $from !== '' ? $from : '(vacío)'],
            ['QUEUE', (string) config('queue.default')],
        ]);

        if ($mailer === 'log' || $mailer === 'array') {
            $this->warn("Mailer «{$mailer}»: no envía SMTP real. En VPS usa MAIL_MAILER=smtp y completa MAIL_*.");

            return self::SUCCESS;
        }

        if ($host === '' || $from === '') {
            $this->error('Falta MAIL_HOST o MAIL_FROM_ADDRESS. Completa SMTP en .env y reinicia api/queue.');

            return self::FAILURE;
        }

        if (! $this->argument('email') && ! $this->confirm("¿Enviar correo de prueba a {$to}?", true)) {
            $this->info('Omitido (sin envío). Configuración parece presente.');

            return self::SUCCESS;
        }

        try {
            Mail::raw('Prueba SMTP Cotización (artisan mail:verify)', function ($message) use ($to, $from) {
                $message->to($to)
                    ->from($from, (string) config('mail.from.name'))
                    ->subject('Prueba SMTP — Cotización');
            });
            $this->info("Correo de prueba enviado a {$to}.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Fallo SMTP: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
