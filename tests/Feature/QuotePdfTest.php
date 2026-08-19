<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Client;
use App\Models\Quote;
use App\Models\User;
use App\Models\Wholesaler;
use App\Services\Quotes\QuotePdfService;
use App\Services\Wholesalers\WholesalerCatalogSync;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\AuthenticatedFeatureTestCase;

class QuotePdfTest extends AuthenticatedFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(WholesalerCatalogSync::class)->sync();
        Wholesaler::query()->update(['active' => true]);
    }

    #[Test]
    public function it_streams_pdf_for_quote_with_lines(): void
    {
        $quoteId = $this->createQuoteWithLines();

        $response = $this->get("/api/cotizaciones/{$quoteId}/pdf");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_renders_customer_observations_but_not_internal_notes(): void
    {
        $quoteId = $this->createQuoteWithLines();
        $quote = Quote::query()->with(['lines', 'client', 'creator'])->findOrFail($quoteId);
        $quote->update([
            'customer_observations' => 'Entrega especial visible para el cliente.',
            'notes' => 'Comentario interno que no debe salir.',
        ]);

        $service = app(QuotePdfService::class);
        $method = new ReflectionMethod(QuotePdfService::class, 'buildViewData');
        $method->setAccessible(true);
        $viewData = $method->invoke(
            $service,
            $quote->fresh(['lines', 'client', 'creator']),
            AppSetting::current(),
        );
        $html = view('pdf.quote', $viewData)->render();

        $this->assertStringContainsString('OBSERVACIONES:', $html);
        $this->assertStringContainsString('Entrega especial visible para el cliente.', $html);
        $this->assertStringNotContainsString('Comentario interno que no debe salir.', $html);
    }

    #[Test]
    public function it_returns_404_for_invalid_quote_id(): void
    {
        $this->get('/api/cotizaciones/not-a-uuid/pdf')->assertNotFound();
    }

    #[Test]
    public function it_returns_422_when_quote_has_no_lines(): void
    {
        $client = Client::query()->create([
            'company' => 'Sin lineas SA',
            'rfc' => 'SIN010101ABC',
        ]);

        $quote = Quote::query()->create([
            'folio' => 'COT-PDF-EMPTY',
            'client_id' => $client->id,
            'status' => 'en_elaboracion',
            'validity_days' => 15,
            'global_margin_percent' => 30,
            'tax_percent' => 16,
        ]);

        $this->getJson("/api/cotizaciones/{$quote->id}/pdf")
            ->assertStatus(422)
            ->assertJsonPath('message', 'La cotización no tiene partidas para generar PDF.');
    }

    #[Test]
    public function it_uses_logged_in_user_signature_not_another_users(): void
    {
        $admin = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'administrador'))->firstOrFail();
        $ventas = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'ventas'))->firstOrFail();
        $this->actingAs($admin);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $ventasRel = 'signatures/'.$ventas->id.'.png';
        $ventasAbs = storage_path('app/private/'.$ventasRel);
        if (! is_dir(dirname($ventasAbs))) {
            mkdir(dirname($ventasAbs), 0777, true);
        }
        file_put_contents($ventasAbs, $png);
        $ventas->signature_path = $ventasRel;
        $ventas->save();

        $admin->signature_path = null;
        $admin->save();

        // Sin firma global: admin no debe heredar la imagen de ventas.
        $globalPath = storage_path('app/public/'.config('quote_pdf.signature_image'));
        $hadGlobal = is_file($globalPath);
        $backup = $hadGlobal ? file_get_contents($globalPath) : null;
        if ($hadGlobal) {
            @unlink($globalPath);
        }

        $quoteId = $this->createQuoteWithLines();
        $quote = Quote::query()->with(['lines', 'client', 'creator'])->findOrFail($quoteId);
        $quote->created_by = $ventas->id;
        $quote->save();

        $service = app(QuotePdfService::class);
        $method = new ReflectionMethod(QuotePdfService::class, 'buildViewData');
        $method->setAccessible(true);
        $viewData = $method->invoke($service, $quote->fresh(['lines', 'client', 'creator']), AppSetting::current());

        $this->assertNull($viewData['signatureImageDataUri']);

        if ($hadGlobal && $backup !== null) {
            file_put_contents($globalPath, $backup);
        }
        @unlink($ventasAbs);
        $ventas->signature_path = null;
        $ventas->save();
    }

    #[Test]
    public function it_uses_logged_in_user_signature_when_available(): void
    {
        $admin = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'administrador'))->firstOrFail();
        $this->actingAs($admin);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $relative = 'signatures/'.$admin->id.'.png';
        $abs = storage_path('app/private/'.$relative);
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0777, true);
        }
        file_put_contents($abs, $png);
        $admin->signature_path = $relative;
        $admin->save();

        $quoteId = $this->createQuoteWithLines();
        $quote = Quote::query()->with(['lines', 'client', 'creator'])->findOrFail($quoteId);

        $service = app(QuotePdfService::class);
        $method = new ReflectionMethod(QuotePdfService::class, 'buildViewData');
        $method->setAccessible(true);
        $viewData = $method->invoke($service, $quote->fresh(['lines', 'client', 'creator']), AppSetting::current());

        $this->assertNotNull($viewData['signatureImageDataUri']);
        $this->assertStringStartsWith('data:image/', $viewData['signatureImageDataUri']);
        $this->assertSame($admin->name, $viewData['signatureName']);
        $this->assertSame($admin->email, $viewData['signatureEmail']);

        @unlink($abs);
        $admin->signature_path = null;
        $admin->save();
    }

    #[Test]
    public function it_falls_back_to_global_signature_when_logged_in_user_has_none(): void
    {
        $admin = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'administrador'))->firstOrFail();
        $admin->signature_path = null;
        $admin->save();
        $this->actingAs($admin);

        $globalPath = storage_path('app/public/'.config('quote_pdf.signature_image'));
        $dir = dirname($globalPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $hadGlobal = is_file($globalPath);
        $backup = $hadGlobal ? file_get_contents($globalPath) : null;
        file_put_contents($globalPath, $png);

        $quoteId = $this->createQuoteWithLines();
        $quote = Quote::query()->with(['lines', 'client', 'creator'])->findOrFail($quoteId);

        $service = app(QuotePdfService::class);
        $method = new ReflectionMethod(QuotePdfService::class, 'buildViewData');
        $method->setAccessible(true);
        $viewData = $method->invoke($service, $quote, AppSetting::current());

        $this->assertNotNull($viewData['signatureImageDataUri']);
        $this->assertStringStartsWith('data:image/', $viewData['signatureImageDataUri']);
        $this->assertSame($admin->name, $viewData['signatureName']);
        $this->assertSame($admin->email, $viewData['signatureEmail']);

        if ($hadGlobal && $backup !== null) {
            file_put_contents($globalPath, $backup);
        } else {
            @unlink($globalPath);
        }
    }

    #[Test]
    public function it_uses_logged_in_user_name_and_email_even_without_signature_image(): void
    {
        $admin = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'administrador'))->firstOrFail();
        $admin->signature_path = null;
        $admin->name = 'Administrador Exacto';
        $admin->email = 'cotizaciones@exacto.mx';
        $admin->save();
        $this->actingAs($admin);

        $globalPath = storage_path('app/public/'.config('quote_pdf.signature_image'));
        if (is_file($globalPath)) {
            @unlink($globalPath);
        }

        $quoteId = $this->createQuoteWithLines();
        $quote = Quote::query()->with(['lines', 'client', 'creator'])->findOrFail($quoteId);

        $service = app(QuotePdfService::class);
        $method = new ReflectionMethod(QuotePdfService::class, 'buildViewData');
        $method->setAccessible(true);
        $viewData = $method->invoke($service, $quote, AppSetting::current());

        $this->assertSame('Administrador Exacto', $viewData['signatureName']);
        $this->assertSame('cotizaciones@exacto.mx', $viewData['signatureEmail']);
        $this->assertNotSame(config('quote_pdf.default_signature_name'), $viewData['signatureName']);
        $this->assertNotSame(config('quote_pdf.default_signature_email'), $viewData['signatureEmail']);
    }

    private function createQuoteWithLines(): string
    {
        $client = Client::query()->create([
            'company' => 'PDF Test SA',
            'rfc' => 'PDF010101ABC',
        ]);

        $response = $this->postJson('/api/cotizaciones', [
            'folio' => 'COT-PDF-'.uniqid(),
            'clientId' => $client->id,
            'status' => 'enviada',
            'validityDays' => 15,
            'globalMarginPercent' => 30,
            'taxPercent' => 16,
            'lines' => [
                [
                    'quantity' => 1,
                    'product' => 'Switch Cisco',
                    'partNumber' => 'C9200L-24T-4G-E',
                    'cost' => 1000,
                    'marginPercent' => 30,
                    'salePrice' => 1300,
                    'amount' => 1300,
                ],
            ],
        ]);

        $response->assertCreated();

        return (string) $response->json('id');
    }
}
