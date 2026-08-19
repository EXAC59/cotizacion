<?php

namespace Tests\Feature;

use App\Models\QuoteRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudLecturaValidationTest extends AuthenticatedFeatureTestCase
{

    #[Test]
    public function it_rejects_excel_template_missing_marca_column(): void
    {
        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | UNIDAD |
| --- | --- | --- | --- |
| 2 | Cable HDMI 2m | HDMI-200 | pza |
MD;

        $response = $this->postJson('/api/solicitudes/interpretar', [
            'markdown' => $markdown,
            'source' => 'excel',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'El archivo no cumple el formato requerido.')
            ->assertJsonPath('errors.columnas_faltantes', ['MARCA']);
    }

    #[Test]
    public function it_accepts_excel_with_required_columns(): void
    {
        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | MARCA | UNIDAD |
| --- | --- | --- | --- | --- |
| 2 | Cable HDMI 2m | HDMI-200 | Belkin | pza |
MD;

        $response = $this->postJson('/api/solicitudes/interpretar', [
            'markdown' => $markdown,
            'source' => 'excel',
        ]);

        $response->assertOk()
            ->assertJsonPath('lineas_count', 1)
            ->assertJsonPath('lineas.0.product', 'Cable HDMI 2m')
            ->assertJsonPath('lineas.0.partNumber', 'HDMI-200');
    }

    #[Test]
    public function it_accepts_excel_without_unidad_column_defaulting_to_pza(): void
    {
        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | MARCA |
| --- | --- | --- | --- |
| 4 | TINTA HP 662 NEGRO | CZ103AL | HP |
| 100 | TONER KYOCERA | 1T0C0W0US0 | KYOCERA |
MD;

        $response = $this->postJson('/api/solicitudes/interpretar', [
            'markdown' => $markdown,
            'source' => 'excel',
        ]);

        $response->assertOk()
            ->assertJsonPath('lineas_count', 2)
            ->assertJsonPath('lineas.0.unit', 'pza')
            ->assertJsonPath('lineas.1.brand', 'KYOCERA');
    }

    #[Test]
    public function it_marks_solicitud_error_when_validation_fails_with_request_id(): void
    {
        $request = QuoteRequest::query()->create([
            'source' => 'excel',
            'status' => 'procesando',
            'file_name' => 'plantilla.xlsx',
        ]);

        $markdown = <<<'MD'
| CANTIDAD | PRODUCTO | NO.PARTE | UNIDAD |
| --- | --- | --- | --- |
| 1 | Monitor 24 pulgadas | MON-24 | pza |
MD;

        $response = $this->postJson('/api/solicitudes/interpretar', [
            'markdown' => $markdown,
            'source' => 'excel',
            'request_id' => $request->id,
        ]);

        $response->assertUnprocessable();

        $request->refresh();
        $this->assertSame('error', $request->status);
        $this->assertNotNull($request->error_message);
    }

    #[Test]
    public function it_uses_raw_text_for_text_source_when_request_id_is_provided(): void
    {
        $request = QuoteRequest::query()->create([
            'source' => 'text',
            'status' => 'procesando',
            'file_name' => 'texto-libre.txt',
            'raw_text' => "2 Monitor Dell P2422H 24 pulgadas\n1 Teclado Logitech K120 USB",
        ]);

        $response = $this->postJson('/api/solicitudes/interpretar', [
            'markdown' => "2 Monitor Dell 24 pulgadas\n24 pulgadas\n1 Teclado Logitech K120 USB",
            'source' => 'text',
            'request_id' => $request->id,
            'file_name' => 'texto-libre.txt',
            'extension' => 'txt',
        ]);

        $response->assertOk()
            ->assertJsonPath('lineas_count', 2)
            ->assertJsonPath('source', 'text')
            ->assertJsonPath('lineas.0.partNumber', 'P2422H')
            ->assertJsonPath('lineas.1.partNumber', 'K120');
    }

    #[Test]
    public function it_resolves_generic_brands_via_api(): void
    {
        $response = $this->postJson('/api/marcas/resolver', [
            'lineas' => [
                [
                    'product' => 'Switch Cisco 24 puertos administrable',
                    'partNumber' => 'SG350-24',
                    'brand' => 'Genérico',
                ],
                [
                    'product' => 'Cable HDMI',
                    'partNumber' => 'HDMI-01',
                    'brand' => 'Belkin',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('resolved_count', 1)
            ->assertJsonPath('lineas.0.brand', 'Cisco')
            ->assertJsonPath('lineas.0.brand_resolved', true)
            ->assertJsonPath('lineas.1.brand', 'Belkin')
            ->assertJsonPath('lineas.1.brand_resolved', false);
    }
}
