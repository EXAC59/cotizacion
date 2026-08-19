<?php



namespace Tests\Concerns;



use App\Models\User;

use Database\Seeders\DashboardUsersSeeder;



trait AuthenticatesDemoUsers

{

    protected function seedDemoUsers(): void

    {

        $this->seed(DashboardUsersSeeder::class);

    }



    protected function actingAsDemoUser(string $roleSlug = 'administrador'): static

    {

        $this->seedDemoUsers();



        $user = User::query()

            ->whereRelation('role', 'slug', $roleSlug)

            ->first();



        if (! $user) {

            $user = User::query()->where('email', '=', 'admin@cotizacion.test')->firstOrFail();

        }



        return $this->actingAs($user);

    }



    protected function demoUser(string $roleSlug): User

    {

        $this->seedDemoUsers();



        return User::query()

            ->whereRelation('role', 'slug', $roleSlug)

            ->firstOrFail();

    }

}

