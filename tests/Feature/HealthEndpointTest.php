<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    #[Test]
    public function public_health_returns_minimal_payload(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonStructure(['status']);

        $json = $response->json();
        $this->assertContains($json['status'], ['ok', 'degraded']);
        $this->assertArrayNotHasKey('stack', $json);
        $this->assertArrayNotHasKey('services', $json);
        $this->assertArrayNotHasKey('app', $json);
    }
}
