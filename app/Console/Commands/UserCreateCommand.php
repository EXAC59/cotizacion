<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class UserCreateCommand extends Command
{
    protected $signature = 'user:create
        {email : Correo del usuario}
        {--name= : Nombre completo}
        {--password= : Contraseña (si se omite, se genera)}
        {--role=administrador : Rol RBAC}
        {--folio= : Código de folio (2-12)}';

    protected $description = 'Crea o actualiza un usuario real en la base (producción)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: Str::before($email, '@')));
        $roleSlug = (string) $this->option('role');
        $password = (string) ($this->option('password') ?: Str::password(12));
        $folio = User::normalizeFolioCode((string) $this->option('folio'))
            ?? User::deriveFolioCodeFromName($name)
            ?? 'USER';

        $roleId = Role::query()->where('slug', $roleSlug)->value('id');
        if (! $roleId) {
            $this->error("Rol desconocido: {$roleSlug}");

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'role_id' => $roleId,
                'active' => true,
                'uuid' => $existing?->uuid ?? (string) Str::uuid(),
                'folio_code' => $folio,
            ],
        );

        $this->info($existing ? 'Usuario actualizado.' : 'Usuario creado.');
        $this->table(['Campo', 'Valor'], [
            ['email', $user->email],
            ['name', $user->name],
            ['role', $roleSlug],
            ['folio', $user->folio_code],
            ['password', $this->option('password') ? '(la indicada)' : $password],
        ]);

        return self::SUCCESS;
    }
}
