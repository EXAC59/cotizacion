<?php

namespace Tests\Unit;

use App\Services\Wholesalers\Connectors\ApiWholesalerConnector;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiWholesalerConnectorTest extends TestCase
{
    #[Test]
    public function it_builds_bearer_auth_headers_by_default(): void
    {
        $this->setEnvVar('WHOLESALER_CT_API_KEY', 'test-key-123');

        $connector = new ApiWholesalerConnector;

        $this->assertSame(
            ['Authorization' => 'Bearer test-key-123'],
            $connector->authHeaders('WHOLESALER_CT'),
        );
    }

    #[Test]
    public function it_supports_custom_auth_header_name(): void
    {
        $this->setEnvVar('WHOLESALER_CT_API_KEY', 'secret');
        $this->setEnvVar('WHOLESALER_CT_AUTH_HEADER', 'X-Api-Key');

        $connector = new ApiWholesalerConnector;

        $this->assertSame(
            ['X-Api-Key' => 'secret'],
            $connector->authHeaders('WHOLESALER_CT'),
        );
    }

    #[Test]
    public function it_applies_source_ip_to_http_client_options(): void
    {
        $this->setEnvVar('WHOLESALER_CT_SOURCE_IP', '203.0.113.50');
        $this->setEnvVar('WHOLESALER_CT_TIMEOUT', '25');

        Http::fake(['*' => Http::response(['price' => 10, 'stock' => 5])]);

        $connector = new ApiWholesalerConnector;
        $connector->httpClient('WHOLESALER_CT')->get('https://api.ct.test/products/ABC');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.ct.test/products/ABC';
        });
    }

    private function setEnvVar(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        Env::disablePutenv();
        Env::enablePutenv();
    }
}
