<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class AppBrandingApiTest extends AuthenticatedFeatureTestCase
{
    #[Test]
    public function it_returns_default_branding(): void
    {
        $response = $this->getJson('/api/configuracion/branding');

        $response->assertOk()
            ->assertJsonPath('appName', 'Cotización B2B')
            ->assertJsonPath('appTagline', 'Uso interno de Exacto');
    }

    #[Test]
    public function guests_can_read_branding_without_session(): void
    {
        auth()->logout();

        $this->getJson('/api/configuracion/branding')
            ->assertOk()
            ->assertJsonPath('appName', 'Cotización B2B')
            ->assertJsonPath('appTagline', 'Uso interno de Exacto');
    }

    #[Test]
    public function it_updates_app_branding_name_and_tagline(): void
    {
        $response = $this->patchJson('/api/configuracion/comercial', [
            'appName' => 'Exacto Cotizaciones',
            'appTagline' => 'Portal interno',
        ]);

        $response->assertOk()
            ->assertJsonPath('appName', 'Exacto Cotizaciones')
            ->assertJsonPath('appTagline', 'Portal interno');

        $this->assertDatabaseHas('app_settings', [
            'id' => 1,
            'app_name' => 'Exacto Cotizaciones',
            'app_tagline' => 'Portal interno',
        ]);

        $this->getJson('/api/configuracion/branding')
            ->assertOk()
            ->assertJsonPath('appName', 'Exacto Cotizaciones')
            ->assertJsonPath('appTagline', 'Portal interno');
    }

    #[Test]
    public function empty_app_name_falls_back_to_default_in_branding_endpoint(): void
    {
        AppSetting::current()->update([
            'app_name' => null,
            'app_tagline' => null,
        ]);

        $this->patchJson('/api/configuracion/comercial', [
            'appName' => '',
            'appTagline' => '',
        ])->assertOk();

        $this->getJson('/api/configuracion/branding')
            ->assertOk()
            ->assertJsonPath('appName', 'Cotización B2B')
            ->assertJsonPath('appTagline', 'Uso interno de Exacto');
    }
}
