<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_rejects_post_without_signature(): void
    {
        config(['whatsapp.app_secret' => 'test-app-secret']);

        $this->postJson('/api/whatsapp/webhook', ['object' => 'whatsapp_business_account'])
            ->assertForbidden();
    }

    #[Test]
    public function it_rejects_post_when_app_secret_missing(): void
    {
        config(['whatsapp.app_secret' => '']);

        $this->call(
            'POST',
            '/api/whatsapp/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=abc'],
            '{"object":"whatsapp_business_account"}'
        )->assertStatus(503);
    }

    #[Test]
    public function it_accepts_post_with_valid_hmac(): void
    {
        $secret = 'test-app-secret';
        config(['whatsapp.app_secret' => $secret]);

        $body = '{"object":"whatsapp_business_account","entry":[]}';
        $sig = 'sha256='.hash_hmac('sha256', $body, $secret);

        $this->call(
            'POST',
            '/api/whatsapp/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $sig,
            ],
            $body
        )->assertOk();
    }

    #[Test]
    public function it_verifies_subscribe_challenge(): void
    {
        config(['whatsapp.verify_token' => 'verify-me']);

        $this->get('/api/whatsapp/webhook?'.http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'verify-me',
            'hub_challenge' => '12345',
        ]))->assertOk()->assertSee('12345');
    }
}
