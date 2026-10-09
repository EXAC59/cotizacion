<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\QuoteRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthenticatedFeatureTestCase;

class SolicitudQuoteIntegrityTest extends AuthenticatedFeatureTestCase
{
    private function createLinkedRequest(): array
    {
        $this->actingAsDemoUser('ventas');
        $client = Client::query()->create(['company' => 'Cliente integridad', 'rfc' => 'CIN010101AAA']);
        $response = $this->postJson('/api/solicitudes', [
            'client_id' => $client->id, 'raw_text' => '1 Equipo original',
            'lineas' => [['quantity' => 1, 'product' => 'Equipo original', 'partNumber' => 'EQ-01', 'brand' => 'Genérico']],
        ])->assertCreated();

        return [QuoteRequest::query()->findOrFail($response->json('request_id')), Quote::query()->findOrFail($response->json('quote_id'))];
    }

    private function replacementLines(): array
    {
        return ['lineas' => [['quantity' => 9, 'product' => 'Equipo reemplazado', 'partNumber' => 'EQ-02', 'brand' => 'Genérico', 'referenceCost' => 999]]];
    }

    public static function protectedQuotes(): array
    {
        $cases = [];
        foreach (['ventas', 'gerente_compras', 'administrador'] as $role) {
            foreach (['assigned', 'edit_lock', 'completed', 'follow_up', 'sent_at', 'foreign_creator', 'en_elaboracion', 'pendiente_envio', 'enviada', 'aceptada', 'facturada', 'modificacion'] as $state) {
                $cases[$role.' '.$state] = [$role, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('protectedQuotes')]
    public function test_request_editor_cannot_overwrite_a_protected_quote(string $role, string $state): void
    {
        [$request, $quote] = $this->createLinkedRequest();
        $compras = $this->demoUser('gerente_compras');
        $values = match ($state) {
            'assigned' => ['purchase_assigned_to' => $compras->id, 'purchase_assigned_at' => now()],
            'edit_lock' => ['locked_by' => $compras->id, 'locked_at' => now()],
            'completed' => ['purchase_completed_at' => now()],
            'follow_up' => ['follow_up_assigned_to' => $this->demoUser('ventas')->id],
            'sent_at' => ['sent_at' => now()],
            'foreign_creator' => ['created_by' => $compras->id],
            default => ['status' => $state],
        };
        $quote->forceFill($values)->saveQuietly();
        $beforeQuote = $quote->fresh()->toArray();
        $beforeRequest = $request->fresh()->toArray();
        $beforeQuoteLines = $quote->lines()->get()->toArray();
        $beforeRequestLines = $request->lines()->get()->toArray();

        $this->actingAsDemoUser($role);
        $this->putJson("/api/solicitudes/{$request->id}/lineas", $this->replacementLines())->assertForbidden();

        $this->assertSame($beforeQuote, $quote->fresh()->toArray());
        $this->assertSame($beforeRequest, $request->fresh()->toArray());
        $this->assertSame($beforeQuoteLines, $quote->lines()->get()->toArray());
        $this->assertSame($beforeRequestLines, $request->lines()->get()->toArray());
    }

    public function test_owner_can_update_an_unclaimed_initial_quote_without_changing_its_identity(): void
    {
        [$request, $quote] = $this->createLinkedRequest();
        $folio = $quote->folio;
        $owner = $quote->created_by;
        $this->putJson("/api/solicitudes/{$request->id}/lineas", $this->replacementLines())
            ->assertOk()->assertJsonPath('quote_id', $quote->id)->assertJsonPath('quote_created', false);

        $this->assertDatabaseCount('quotes', 1);
        $this->assertDatabaseHas('quote_request_lines', ['request_id' => $request->id, 'product' => 'Equipo reemplazado', 'quantity' => 9]);
        $this->assertDatabaseHas('quote_lines', ['quote_id' => $quote->id, 'product' => 'Equipo reemplazado', 'quantity' => 9]);
        $this->assertSame($folio, $quote->fresh()->folio);
        $this->assertSame($owner, $quote->fresh()->created_by);
        $this->assertSame('solicitud_cotizaciones', $quote->fresh()->status);
        $this->assertGreaterThan(0, (float) $quote->fresh()->total);
    }

    public function test_editing_a_request_cannot_bypass_a_real_purchase_claim(): void
    {
        [$request, $quote] = $this->createLinkedRequest();
        $this->actingAsDemoUser('gerente_compras');
        $this->postJson("/api/cotizaciones/{$quote->id}/compras/tomar")->assertOk();
        $this->actingAsDemoUser('ventas');
        $this->putJson("/api/solicitudes/{$request->id}/lineas", $this->replacementLines())->assertForbidden();
        $this->assertSame('Equipo original', $quote->lines()->firstOrFail()->product);
        $this->assertSame('Equipo original', $request->lines()->firstOrFail()->product);
        $this->assertSame($this->demoUser('gerente_compras')->id, $quote->fresh()->purchase_assigned_to);
    }

    public function test_a_sync_failure_rolls_back_both_sets_of_lines_and_totals(): void
    {
        [$request, $quote] = $this->createLinkedRequest();
        $beforeQuote = $quote->fresh()->toArray();
        $beforeQuoteLines = $quote->lines()->get()->toArray();
        $beforeRequestLines = $request->lines()->get()->toArray();
        Quote::updating(static function (Quote $updating) use ($quote): void {
            if ($updating->id === $quote->id) {
                throw new \RuntimeException('Fixture: fallo al sincronizar importes.');
            }
        });

        $this->putJson("/api/solicitudes/{$request->id}/lineas", $this->replacementLines())->assertStatus(500);

        $this->assertSame($beforeQuote, $quote->fresh()->toArray());
        $this->assertSame($beforeQuoteLines, $quote->lines()->get()->toArray());
        $this->assertSame($beforeRequestLines, $request->lines()->get()->toArray());
    }

    public static function expiredLocks(): array
    {
        return ['expired heartbeat' => [true], 'missing timestamp' => [false]];
    }

    #[DataProvider('expiredLocks')]
    public function test_expired_edit_locks_do_not_block_initial_request_edits_forever(bool $hasTimestamp): void
    {
        [$request, $quote] = $this->createLinkedRequest();
        $quote->forceFill([
            'locked_by' => $this->demoUser('gerente_compras')->id,
            'locked_at' => $hasTimestamp ? now()->subSeconds(max(30, (int) config('quotes.lock_ttl_seconds', 120)) + 60) : null,
        ])->saveQuietly();

        $this->putJson("/api/solicitudes/{$request->id}/lineas", $this->replacementLines())->assertOk();
        $this->assertNull($quote->fresh()->locked_by);
        $this->assertSame('Equipo reemplazado', $quote->lines()->firstOrFail()->product);
        $this->assertSame('solicitud_cotizaciones', $quote->fresh()->status);
    }
}
