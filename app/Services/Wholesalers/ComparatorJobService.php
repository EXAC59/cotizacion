<?php

namespace App\Services\Wholesalers;

use App\Jobs\ComparatorLocalFallbackJob;
use App\Models\ComparisonJob;
use App\Services\N8nClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ComparatorJobService
{
    public function __construct(
        private readonly N8nClient $n8nClient,
        private readonly WholesalerComparatorService $comparator,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function dispatch(
        string $partNumber,
        float $quantity = 1,
        ?string $preferredWarehouse = null,
        array $context = [],
    ): ComparisonJob {
        $partNumber = SkuNormalizer::forLookup($partNumber);
        $preferredWarehouse = $preferredWarehouse !== null && $preferredWarehouse !== ''
            ? strtoupper(trim($preferredWarehouse))
            : null;

        $job = ComparisonJob::query()->create([
            'id' => (string) Str::uuid(),
            'part_number' => $partNumber,
            'quantity' => max(0.0001, $quantity),
            'preferred_warehouse' => $preferredWarehouse,
            'status' => ComparisonJob::STATUS_PROCESSING,
            'context' => $context,
        ]);

        // Resolver ya con Laravel/CT/CVA en Docker. n8n orquesta mayoristas y puede enriquecer.
        $this->completeWithLocalFallback($job);

        try {
            $this->n8nClient->dispararComparador(
                $job->id,
                $partNumber,
                (float) $job->quantity,
                null, // búsqueda nacional: todos los almacenes
                array_merge($context, ['searchAllWarehouses' => true]),
            );
        } catch (\Throwable $e) {
            Log::warning('n8n comparador no disponible (job ya resuelto en local)', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->scheduleFallbackIfStillProcessing($job->id);

        return $job->fresh() ?? $job;
    }

    /**
     * @param  list<array<string, mixed>>  $rawOffers
     */
    public function completeFromN8n(
        ComparisonJob $job,
        array $rawOffers,
        ?string $n8nExecutionId = null,
        bool $demoMode = false,
    ): ComparisonJob {
        $localOffers = is_array($job->offers) ? $job->offers : [];
        $mergedRaw = $this->mergeOfferPayloads($localOffers, $rawOffers);

        // No degradar un resultado local usable (p. ej. CT+CVA) con un callback n8n incompleto.
        if (
            $job->status === ComparisonJob::STATUS_DONE
            && $this->countUsableOffers($localOffers) > 0
            && $this->countUsableOffers($mergedRaw) < $this->countUsableOffers($localOffers)
        ) {
            Log::info('Callback n8n ignorado: no mejora el resultado local', [
                'job_id' => $job->id,
                'local_usable' => $this->countUsableOffers($localOffers),
                'n8n_offers' => count($rawOffers),
            ]);

            return $job;
        }

        $ranked = $this->comparator->rankRawOffers(
            $mergedRaw,
            (float) $job->quantity,
            null, // ranking sin filtrar por almacenes preferidos
        );

        $offers = array_map(fn (ComparedOffer $o) => $o->toArray(), $ranked);
        $best = $offers[0] ?? null;

        $job->update([
            'status' => ComparisonJob::STATUS_DONE,
            'offers' => $offers,
            'best' => $best,
            'demo_mode' => $demoMode,
            'n8n_execution_id' => $n8nExecutionId,
            'error_message' => null,
        ]);

        return $job->fresh() ?? $job;
    }

    /**
     * @param  list<array<string, mixed>>  $local
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeOfferPayloads(array $local, array $incoming): array
    {
        /** @var array<string, array<string, mixed>> $byCode */
        $byCode = [];

        foreach ($local as $offer) {
            if (! is_array($offer)) {
                continue;
            }
            $code = strtoupper(trim((string) ($offer['wholesalerCode'] ?? '')));
            if ($code === '') {
                continue;
            }
            $byCode[$code] = $offer;
        }

        foreach ($incoming as $offer) {
            if (! is_array($offer)) {
                continue;
            }
            $code = strtoupper(trim((string) ($offer['wholesalerCode'] ?? '')));
            if ($code === '') {
                continue;
            }

            $existing = $byCode[$code] ?? null;
            if ($existing === null) {
                $byCode[$code] = $offer;

                continue;
            }

            $existingUsable = $this->isUsableOffer($existing);
            $incomingUsable = $this->isUsableOffer($offer);
            if (! $existingUsable && $incomingUsable) {
                $byCode[$code] = $offer;
            }
        }

        return array_values($byCode);
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     */
    private function countUsableOffers(array $offers): int
    {
        $n = 0;
        foreach ($offers as $offer) {
            if (is_array($offer) && $this->isUsableOffer($offer)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function isUsableOffer(array $offer): bool
    {
        $err = trim((string) ($offer['error'] ?? ''));
        if ($err !== '') {
            return false;
        }

        return (float) ($offer['cost'] ?? 0) > 0 || (int) ($offer['stock'] ?? 0) > 0;
    }

    public function markError(ComparisonJob $job, string $message): ComparisonJob
    {
        $job->update([
            'status' => ComparisonJob::STATUS_ERROR,
            'error_message' => $message,
        ]);

        return $job->fresh();
    }

    public function runFallbackIfStillProcessing(string $jobId): void
    {
        $job = ComparisonJob::query()->find($jobId);
        if ($job === null || $job->status !== ComparisonJob::STATUS_PROCESSING) {
            return;
        }

        Log::info('Comparador: fallback local por timeout n8n', ['job_id' => $jobId]);
        $this->completeWithLocalFallback($job);
    }

    private function scheduleFallbackIfStillProcessing(string $jobId): void
    {
        $seconds = max(5, (int) config('n8n.comparator_fallback_seconds', 20));

        ComparatorLocalFallbackJob::dispatch($jobId)
            ->delay(now()->addSeconds($seconds));
    }

    private function completeWithLocalFallback(ComparisonJob $job): void
    {
        if ($job->status === ComparisonJob::STATUS_DONE) {
            return;
        }

        // Buscar el SKU en TODOS los almacenes de cada mayorista y dejar la mejor oferta
        // (ranking). n8n orquesta el fan-out; Laravel en Docker resuelve CT/CVA.
        // preferred_warehouse se guarda solo como referencia de UI: no limita existencias.
        $result = $this->comparator->compare(
            $job->part_number,
            (float) $job->quantity,
            null,
        );

        $job->update([
            'status' => ComparisonJob::STATUS_DONE,
            'offers' => $result['offers'],
            'best' => $result['best'],
            'demo_mode' => $result['demoMode'],
            // DONE aunque no haya ofertas: la UI muestra aviso de “producto no encontrado”, no fallo técnico.
            'error_message' => ($result['offers'] === [] ? ($result['lookupError'] ?? null) : null),
            'context' => array_merge(
                is_array($job->context) ? $job->context : [],
                [
                    'notFound' => $result['notFound'] ?? [],
                    'notFoundSummary' => $result['lookupError'] ?? null,
                ],
            ),
        ]);
    }
}
