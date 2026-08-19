<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class N8nInternalRoutesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function n8n_can_call_interpretar_without_user_session(): void
    {
        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | MARCA | UNIDAD |
| --- | --- | --- | --- | --- |
| 2 | USB 128GB Kingston | DTX/128 | Kingston | pza |
MD;

        $this->postJson('/api/solicitudes/interpretar', [
            'markdown' => $markdown,
            'source' => 'excel',
        ])
            ->assertOk()
            ->assertJsonPath('lineas_count', 1);
    }

    #[Test]
    public function n8n_can_call_marcas_resolver_without_user_session(): void
    {
        $this->postJson('/api/marcas/resolver', [
            'lineas' => [
                [
                    'product' => 'Switch Cisco 24 puertos',
                    'partNumber' => 'SG350-24',
                    'brand' => 'Genérico',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('lineas.0.brand', 'Cisco');
    }
}
