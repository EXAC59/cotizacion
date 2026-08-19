<?php

namespace Tests\Unit;

use App\Models\Quote;
use App\Services\Quotes\QuoteStatusGuard;
use Tests\TestCase;

class QuoteStatusGuardTest extends TestCase
{
    public function test_allows_status_regression(): void
    {
        $guard = new QuoteStatusGuard;
        $existing = new Quote(['status' => 'enviada']);

        $guard->assertForwardOnly($existing, 'en_elaboracion');

        $this->assertTrue(true);
    }

    public function test_rejects_unknown_status(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $guard = new QuoteStatusGuard;
        $guard->assertForwardOnly(null, 'estatus_inventado');
    }

    public function test_allows_solicitud_cotizaciones_status(): void
    {
        $guard = new QuoteStatusGuard;
        $guard->assertForwardOnly(null, 'solicitud_cotizaciones');

        $this->assertTrue(true);
    }

    public function test_allows_modificacion_status(): void
    {
        $guard = new QuoteStatusGuard;
        $guard->assertForwardOnly(null, 'modificacion');

        $this->assertTrue(true);
    }
}
