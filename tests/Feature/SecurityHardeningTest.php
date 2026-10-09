<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyN8nWebhookSecret;
use App\Models\AppSetting;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AuthenticatesDemoUsers;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use AuthenticatesDemoUsers;
    use RefreshDatabase;

    private function enforceCsrf(): void
    {
        // Laravel omite CSRF durante PHPUnit: se activa para comprobar el control real.
        $this->app->bind(ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
    }

    public function test_login_requires_a_csrf_token(): void
    {
        $this->enforceCsrf();
        $this->postJson('/api/login', ['username' => 'maria', 'password' => 'demo'])
            ->assertStatus(419);
    }

    public function test_login_with_valid_csrf_token_preserves_session_login(): void
    {
        $this->seedDemoUsers();
        $this->enforceCsrf();
        $this->withSession(['_token' => 'security-test-token'])
            ->withHeader('X-CSRF-TOKEN', 'security-test-token')
            ->postJson('/api/login', ['username' => 'maria', 'password' => 'demo'])
            ->assertOk()->assertJsonPath('user.username', 'maria');
        $this->assertAuthenticated();
    }

    public function test_whatsapp_admin_mutations_require_csrf(): void
    {
        $this->actingAsDemoUser();
        $this->enforceCsrf();
        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/whatsapp/plantillas', [])->assertStatus(419);
    }

    public function test_signed_callbacks_remain_exempt_from_browser_csrf(): void
    {
        $this->enforceCsrf();
        config(['n8n.webhook_secret' => 'test-secret']);
        $this->postJson('/api/n8n/lectura', [])->assertUnauthorized();
        $this->postJson('/api/whatsapp/webhook', [])->assertStatus(503);
    }

    public function test_login_limit_normalizes_identifier_case_and_spaces(): void
    {
        $this->seedDemoUsers();
        foreach (['maria', 'MARIA', ' maria ', 'Maria', 'maria'] as $username) {
            $this->postJson('/api/login', ['username' => $username, 'password' => 'invalid'])
                ->assertUnprocessable();
        }
        $this->postJson('/api/login', ['username' => 'MARIA', 'password' => 'invalid'])
            ->assertStatus(429);
    }

    public function test_login_ip_limit_blocks_password_spraying_across_identifiers(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/login', ['username' => 'unknown-'.$i, 'password' => 'invalid'])
                ->assertUnprocessable();
        }
        $this->postJson('/api/login', ['username' => 'unknown-next', 'password' => 'invalid'])
            ->assertStatus(429);
    }

    public function test_inactive_user_cannot_reuse_an_existing_session(): void
    {
        $user = $this->demoUser('ventas');
        $user->update(['active' => false]);
        $this->actingAs($user)->getJson('/api/user')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_inactive_user_bearer_tokens_are_revoked(): void
    {
        $user = $this->demoUser('ventas');
        $token = $user->createToken('security-regression')->plainTextToken;
        $user->update(['active' => false]);
        $this->withToken($token)->getJson('/api/clientes')->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_admin_password_reset_revokes_tokens_and_database_sessions(): void
    {
        $this->actingAsDemoUser();
        $user = \App\Models\User::where('username', 'maria')->firstOrFail();
        $user->createToken('existing-access');
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            'id' => 'old-victim-session', 'user_id' => $user->id,
            'payload' => 'test', 'last_activity' => now()->timestamp,
        ]);
        $id = $user->uuid ?: $user->id;
        $this->putJson('/api/admin/users/'.$id, [
            'password' => 'changed-password', 'password_confirmation' => 'changed-password',
        ])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'old-victim-session']);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_disabling_an_account_revokes_tokens_immediately(): void
    {
        $this->actingAsDemoUser();
        $user = \App\Models\User::where('username', 'maria')->firstOrFail();
        $user->createToken('existing-access');
        $this->putJson('/api/admin/users/'.($user->uuid ?: $user->id), ['active' => false])
            ->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_n8n_refuses_requests_when_secret_is_missing(): void
    {
        foreach ([null, '', '   '] as $secret) {
            config(['n8n.webhook_secret' => $secret]);
            $this->getJson('/api/n8n/mayoristas')->assertStatus(503);
        }
    }

    public function test_n8n_accepts_only_the_configured_shared_secret(): void
    {
        config(['n8n.webhook_secret' => 'security-test-secret']);
        $this->getJson('/api/n8n/mayoristas')->assertUnauthorized();
        $this->withHeader('X-N8N-Webhook-Secret', 'wrong')
            ->getJson('/api/n8n/mayoristas')->assertUnauthorized();

        $request = Request::create('/api/n8n/lectura', 'POST');
        $request->headers->set('X-N8N-Webhook-Secret', 'security-test-secret');
        $response = (new VerifyN8nWebhookSecret)->handle($request, fn () => response()->noContent());
        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_logo_cannot_use_an_executable_extension_even_with_valid_image_bytes(): void
    {
        Storage::fake('public');
        $this->actingAsDemoUser();
        $settings = AppSetting::current();
        $settings->update(['logo_path' => 'company/logo.png']);
        Storage::disk('public')->put('company/logo.png', 'existing-logo');
        $png = UploadedFile::fake()->image('logo.png');
        $disguised = new UploadedFile($png->getPathname(), 'logo.php', 'image/png', null, true);

        $this->post('/api/configuracion/comercial/logo', ['logo' => $disguised], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('logo');
        Storage::disk('public')->assertExists('company/logo.png');
        Storage::disk('public')->assertMissing('company/logo.php');
    }

    public function test_valid_logo_is_stored_with_a_server_determined_image_extension(): void
    {
        Storage::fake('public');
        $this->actingAsDemoUser();
        $this->post('/api/configuracion/comercial/logo', ['logo' => UploadedFile::fake()->image('company.png')], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertSame('company/logo.png', AppSetting::current()->logo_path);
        Storage::disk('public')->assertExists('company/logo.png');
    }

    private function registerProxyProbe(): void
    {
        Route::get('/security-proxy-probe', fn (Request $request) => response()->json([
            'ip' => $request->ip(), 'secure' => $request->secure(), 'host' => $request->getHost(), 'port' => $request->getPort(),
        ]));
    }

    public function test_untrusted_clients_cannot_spoof_proxy_identity_or_https(): void
    {
        $this->registerProxyProbe();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.8', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'attacker.test', 'X-Forwarded-Port' => '1234'])
            ->getJson('/security-proxy-probe')->assertOk()
            ->assertJsonPath('ip', '203.0.113.4')->assertJsonPath('secure', false)
            ->assertJsonPath('host', 'localhost')->assertJsonPath('port', 80);
    }

    public function test_trusted_internal_proxy_resolves_client_without_accepting_spoofed_leftmost_ip(): void
    {
        $this->registerProxyProbe();
        $this->withServerVariables(['REMOTE_ADDR' => '172.16.2.8'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.8, 203.0.113.4, 172.16.2.5', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'attacker.test', 'X-Forwarded-Port' => '1234'])
            ->getJson('/security-proxy-probe')->assertOk()
            ->assertJsonPath('ip', '203.0.113.4')->assertJsonPath('secure', true)
            ->assertJsonPath('host', 'localhost')->assertJsonPath('port', 443);
    }

    public function test_api_responses_are_not_cacheable(): void
    {
        $response = $this->getJson('/api/health')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_client_telemetry_rejects_oversized_reports(): void
    {
        $this->actingAsDemoUser('ventas');
        $this->postJson('/api/debug/client', ['message' => str_repeat('x', 17000)])
            ->assertStatus(413);
    }

    public function test_client_telemetry_size_limit_also_applies_to_form_parameters(): void
    {
        $this->actingAsDemoUser('ventas');
        $this->post('/api/debug/client', ['message' => str_repeat('x', 17000)], ['Accept' => 'application/json'])
            ->assertStatus(413);
    }
}
