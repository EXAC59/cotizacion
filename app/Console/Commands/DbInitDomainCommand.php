<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class DbInitDomainCommand extends Command
{
    protected $signature = 'db:init-domain
                            {--fresh : Ejecuta el SQL aunque las tablas de dominio ya existan}';

    protected $description = 'Carga el esquema de dominio (roles, solicitudes, clientes, etc.) en PostgreSQL';

    public function handle(): int
    {
        if (config('database.default') !== 'pgsql') {
            $this->error('DB_CONNECTION debe ser pgsql. Revisa tu archivo .env');

            return self::FAILURE;
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->error('No hay conexión a PostgreSQL: '.$e->getMessage());

            return self::FAILURE;
        }

        $path = database_path('sql/001_objetivo_general_usuarios_permisos.sql');

        if (! is_readable($path)) {
            $this->error("No se encuentra el archivo SQL: {$path}");

            return self::FAILURE;
        }

        if (! $this->option('fresh') && $this->domainTablesExist()) {
            $this->info('Las tablas de dominio ya existen. Usa --fresh para volver a cargar el SQL.');

            return self::SUCCESS;
        }

        $sql = file_get_contents($path);
        $statements = $this->splitSqlStatements($sql);

        $this->info('Aplicando esquema de dominio en '.config('database.connections.pgsql.database').'...');

        $bar = $this->output->createProgressBar(count($statements));
        $bar->start();

        foreach ($statements as $statement) {
            try {
                DB::unprepared($statement);
            } catch (Throwable $e) {
                $bar->finish();
                $this->newLine(2);
                $this->error('Error al ejecutar SQL: '.$e->getMessage());
                $this->line(mb_substr(trim($statement), 0, 200).'...');

                return self::FAILURE;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Esquema de dominio aplicado correctamente.');
        $this->table(
            ['Tabla', 'Registros'],
            collect(['roles', 'permissions', 'clients', 'quote_requests', 'users'])
                ->map(fn (string $table) => [
                    $table,
                    (string) DB::table($table)->count(),
                ])
                ->all()
        );

        return self::SUCCESS;
    }

    private function domainTablesExist(): bool
    {
        $result = DB::selectOne(
            "SELECT EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = 'quote_requests'
            ) AS exists"
        );

        return (bool) ($result->exists ?? false);
    }

    /**
     * @return list<string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
        $buffer = '';
        $statements = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            $buffer .= $line."\n";

            if (str_ends_with(rtrim($line), ';')) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }
}
