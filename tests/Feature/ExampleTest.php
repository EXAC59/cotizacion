<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_shows_setup_when_spa_is_not_built(): void
    {
        $this->withTemporaryPublicPath(function (): void {
            $this->get('/')->assertOk()->assertViewIs('spa-setup');
        });
    }

    public function test_root_redirects_to_the_built_spa(): void
    {
        $this->withTemporaryPublicPath(function (string $path): void {
            mkdir($path.'/spa');
            file_put_contents($path.'/spa/index.html', '<html><head></head><body>SPA fixture</body></html>');
            $this->get('/')->assertRedirect('/spa/');
            $response = $this->get('/spa/')->assertOk()->assertSee('SPA fixture')->assertHeader('Cache-Control');
            foreach (['no-cache', 'no-store', 'must-revalidate'] as $directive) {
                $this->assertTrue($response->headers->hasCacheControlDirective($directive));
            }
        });
    }

    private function withTemporaryPublicPath(callable $callback): void
    {
        $original = public_path();
        $path = sys_get_temp_dir().'/cotizacion-public-'.bin2hex(random_bytes(8));
        mkdir($path);
        $this->app->usePublicPath($path);
        try {
            $callback($path);
        } finally {
            $this->app->usePublicPath($original);
            if (is_file($path.'/spa/index.html')) {
                unlink($path.'/spa/index.html');
                rmdir($path.'/spa');
            }
            rmdir($path);
        }
    }
}
