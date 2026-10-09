<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesDemoUsers;
use Tests\TestCase;

class SessionStatusTest extends TestCase
{
    use AuthenticatesDemoUsers;
    use RefreshDatabase;

    public function test_anonymous_bootstrap_is_not_an_http_error_and_does_not_open_protected_routes(): void
    {
        $response = $this->getJson('/api/session')->assertOk()->assertExactJson(['user' => null]);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->getJson('/api/user')->assertUnauthorized();
        $this->getJson('/api/clientes')->assertUnauthorized();
    }

    public static function roles(): array
    {
        return [['ventas'], ['gerente_compras'], ['administrador']];
    }

    #[DataProvider('roles')]
    public function test_cookie_session_returns_only_the_authenticated_user(string $role): void
    {
        $user = $this->demoUser($role);
        $this->actingAs($user, 'web')->getJson('/api/session')->assertOk()
            ->assertJsonPath('user.email', $user->email)->assertJsonPath('user.role', $role)
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('token');
    }

    public function test_valid_bearer_session_keeps_compatibility(): void
    {
        $user = $this->demoUser('ventas');
        $token = $user->createToken('session-test');
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)->getJson('/api/session')
            ->assertOk()->assertJsonPath('user.email', $user->email)->assertJsonMissingPath('token');
    }

    public function test_disabled_cookie_session_is_rejected_and_tokens_are_revoked(): void
    {
        $user = $this->demoUser('ventas');
        $user->forceFill(['active' => false])->save();
        $user->createToken('old-session-test');
        $this->actingAs($user, 'web')->getJson('/api/session')->assertUnauthorized()
            ->assertJsonMissingPath('user');
        $this->assertSame(0, $user->tokens()->count());
        $this->assertGuest('web');
    }

    public function test_disabled_bearer_session_cannot_leak_the_user(): void
    {
        $user = $this->demoUser('ventas');
        $user->forceFill(['active' => false])->save();
        $token = $user->createToken('disabled-session-test');
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)->getJson('/api/session')
            ->assertUnauthorized()->assertJsonMissingPath('user');
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_unknown_bearer_does_not_authenticate(): void
    {
        $this->withHeader('Authorization', 'Bearer invalid-session-token')->getJson('/api/session')
            ->assertOk()->assertExactJson(['user' => null]);
        $this->getJson('/api/clientes')->assertUnauthorized();
    }

    public function test_expired_bearer_does_not_authenticate(): void
    {
        $user = $this->demoUser('ventas');
        $token = $user->createToken('expired-session-test', ['*'], now()->subMinute());
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)->getJson('/api/session')
            ->assertOk()->assertExactJson(['user' => null]);
        $this->getJson('/api/user')->assertUnauthorized();
    }
}
