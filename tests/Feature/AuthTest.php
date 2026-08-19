<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AuthenticatesDemoUsers;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use AuthenticatesDemoUsers;
    use RefreshDatabase;

    #[Test]
    public function it_rejects_unauthenticated_api_access(): void
    {
        $this->getJson('/api/clientes')->assertUnauthorized();
    }

    #[Test]
    public function unauthenticated_user_endpoint_returns_401_not_server_error(): void
    {
        $this->get('/api/user')->assertUnauthorized();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    #[Test]
    public function it_logs_in_with_valid_credentials(): void
    {
        $this->seedDemoUsers();

        $response = $this->postJson('/api/login', [
            'username' => 'maria',
            'password' => 'demo',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'maria@empresa.com')
            ->assertJsonPath('user.username', 'maria')
            ->assertJsonPath('user.role', 'ventas')
            ->assertJsonMissingPath('token');

        $this->assertAuthenticated();
        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.email', 'maria@empresa.com');
    }

    #[Test]
    public function it_rejects_invalid_credentials(): void
    {
        $this->seedDemoUsers();

        $this->postJson('/api/login', [
            'username' => 'maria',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    #[Test]
    public function failed_logins_do_not_lock_out_the_account(): void
    {
        $this->seedDemoUsers();

        for ($i = 0; $i < 8; $i++) {
            $response = $this->postJson('/api/login', [
                'username' => 'maria',
                'password' => 'wrong-password',
            ]);

            $response->assertUnprocessable();
            $message = (string) ($response->json('errors.username.0') ?? $response->json('message') ?? '');
            $this->assertStringNotContainsString('Demasiados intentos', $message);
            $this->assertStringNotContainsString('Too Many Attempts', $message);
        }

        $this->postJson('/api/login', [
            'username' => 'maria',
            'password' => 'demo',
        ])->assertOk()->assertJsonPath('user.username', 'maria');
    }

    #[Test]
    public function it_still_accepts_email_login_for_compatibility(): void
    {
        $this->seedDemoUsers();

        $this->postJson('/api/login', [
            'email' => 'maria@empresa.com',
            'password' => 'demo',
        ])->assertOk()->assertJsonPath('user.username', 'maria');
    }

    #[Test]
    public function authenticated_user_can_list_clients(): void
    {
        $this->actingAsDemoUser('ventas');

        $this->getJson('/api/clientes')->assertOk();
    }

    #[Test]
    public function it_returns_current_user(): void
    {
        $user = $this->demoUser('gerente_compras');

        $this->actingAs($user)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.email', 'compras@cotizacion.test')
            ->assertJsonPath('user.role', 'gerente_compras');
    }
}
