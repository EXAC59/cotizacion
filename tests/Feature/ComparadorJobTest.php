<?php

namespace Tests\Feature;

use App\Models\ComparisonJob;
use App\Models\Wholesaler;
use App\Services\Wholesalers\WholesalerCatalogSync;
use PHPUnit\Framework\Attributes\Test;
use Tests\AuthenticatedFeatureTestCase;

class ComparadorJobTest extends AuthenticatedFeatureTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        app(WholesalerCatalogSync::class)->sync();
        Wholesaler::query()->update(['active' => true]);
    }

    #[Test]
    public function it_dispatches_comparison_job_and_completes_via_callback(): void
    {
        config(['n8n.comparator_webhook_url' => null]);

        $dispatch = $this->postJson('/api/comparador/disparar', [
            'partNumber' => 'C9200L-24T-4G-E',
            'quantity' => 2,
            'preferredWarehouse' => 'CDMX',
        ]);

        $dispatch->assertStatus(202)->assertJsonStructure(['jobId', 'status']);
        $jobId = $dispatch->json('jobId');

        $poll = $this->getJson("/api/comparador/{$jobId}");
        $poll->assertOk()->assertJsonPath('status', 'done');
        $this->assertNotEmpty($poll->json('offers'));
        $this->assertNotNull($poll->json('best'));
    }

    #[Test]
    public function n8n_callback_completes_job_with_ranked_offers(): void
    {
        $job = ComparisonJob::query()->create([
            'part_number' => 'WD19',
            'quantity' => 1,
            'preferred_warehouse' => 'CDMX',
            'status' => ComparisonJob::STATUS_PROCESSING,
        ]);

        $wh = Wholesaler::query()->where('active', true)->firstOrFail();

        $response = $this->postJson('/api/n8n/comparador', [
            'job_id' => $job->id,
            'demo_mode' => true,
            'offers' => [
                [
                    'wholesalerId' => $wh->id,
                    'wholesalerCode' => $wh->code,
                    'wholesalerName' => $wh->name,
                    'partNumber' => 'WD19',
                    'cost' => 3200,
                    'stock' => 10,
                    'warehouse' => 'CDMX',
                    'leadDays' => 0,
                ],
                [
                    'wholesalerId' => $wh->id,
                    'wholesalerCode' => 'MOCK2',
                    'wholesalerName' => 'Mock 2',
                    'partNumber' => 'WD19',
                    'cost' => 3500,
                    'stock' => 5,
                    'warehouse' => 'MTY',
                    'leadDays' => 2,
                ],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('job.status', 'done');
        $this->assertTrue($response->json('job.best.isBest'));
    }

    #[Test]
    public function n8n_callback_ignores_unknown_job_without_failing(): void
    {
        $response = $this->postJson('/api/n8n/comparador', [
            'job_id' => '019f4dae-0000-7000-8000-000000000099',
            'offers' => [],
            'demo_mode' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('ignored', true)
            ->assertJsonPath('message', 'Job no encontrado; callback ignorado');
    }
}
