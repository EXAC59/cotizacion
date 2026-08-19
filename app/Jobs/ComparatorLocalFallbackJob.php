<?php

namespace App\Jobs;

use App\Services\Wholesalers\ComparatorJobService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Completa un comparison job con lookup Laravel si n8n no respondió a tiempo.
 */
class ComparatorLocalFallbackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public string $comparisonJobId,
    ) {}

    public function handle(ComparatorJobService $jobs): void
    {
        $jobs->runFallbackIfStillProcessing($this->comparisonJobId);
    }
}
