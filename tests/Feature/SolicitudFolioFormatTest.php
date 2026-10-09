<?php

namespace Tests\Feature;

use App\Models\QuoteRequest;
use App\Services\SolicitudFolioGenerator;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudFolioFormatTest extends AuthenticatedFeatureTestCase
{
    private function requestWithFolio(string $folio): QuoteRequest
    {
        return QuoteRequest::query()->create(['folio' => $folio, 'source' => 'text', 'status' => 'procesada', 'raw_text' => 'Partida de prueba']);
    }

    private function formatMigration(): object
    {
        return require database_path('migrations/2026_10_05_150000_format_sc_request_folios_with_four_digits.php');
    }

    public function test_new_sequence_starts_with_four_digits(): void
    {
        $this->assertSame('SC-0001', app(SolicitudFolioGenerator::class)->generate());
    }

    public function test_generator_keeps_sequence_with_old_and_new_padding(): void
    {
        $this->requestWithFolio('SC-000031');
        $this->requestWithFolio('SC-0007');
        $this->assertSame('SC-0032', app(SolicitudFolioGenerator::class)->generate());
    }

    public function test_migration_changes_only_padding_of_existing_sc_folios(): void
    {
        $request = $this->requestWithFolio('SC-000031');
        $original = $request->fresh()->getAttributes();
        $alreadyFormatted = $this->requestWithFolio('SC-0007');
        $legacy = $this->requestWithFolio('SOL-ERIKA-0001');
        $this->formatMigration()->up();
        $this->assertSame(array_replace($original, ['folio' => 'SC-0031']), $request->fresh()->getAttributes());
        $this->assertSame('SC-0007', $alreadyFormatted->fresh()->folio);
        $this->assertSame('SOL-ERIKA-0001', $legacy->fresh()->folio);
    }

    public function test_duplicate_numeric_sequences_abort_without_partial_changes(): void
    {
        $first = $this->requestWithFolio('SC-000001');
        $old = $this->requestWithFolio('SC-000031');
        $new = $this->requestWithFolio('SC-0031');
        try {
            $this->formatMigration()->up();
            $this->fail('Duplicate numeric folios must block normalization.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('duplicados', $error->getMessage());
        }
        $this->assertSame('SC-000001', $first->fresh()->folio);
        $this->assertSame('SC-000031', $old->fresh()->folio);
        $this->assertSame('SC-0031', $new->fresh()->folio);
    }

    public function test_large_sequences_are_never_truncated(): void
    {
        $request = $this->requestWithFolio('SC-010000');
        $this->formatMigration()->up();
        $this->assertSame('SC-10000', $request->fresh()->folio);
        $this->assertSame('SC-10001', app(SolicitudFolioGenerator::class)->generate());
    }

    public function test_rollback_restores_six_digit_padding_without_renumbering(): void
    {
        $request = $this->requestWithFolio('SC-0031');
        $this->formatMigration()->down();
        $this->assertSame('SC-000031', $request->fresh()->folio);
    }

    public function test_request_can_be_found_by_its_formatted_folio(): void
    {
        $request = $this->requestWithFolio('SC-000031');
        $this->formatMigration()->up();
        $this->getJson('/api/solicitudes?q=SC-0031')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $request->id)->assertJsonPath('data.0.folio', 'SC-0031');
    }
}
