<?php

namespace App\Services\Wholesalers;

use App\Models\Wholesaler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Concurrency;

class WholesalerLookupService
{
    public function __construct(
        private readonly WholesalerConnectorFactory $factory,
    ) {}

    /**
     * @param  list<string>|null  $wholesalerIds
     * @return list<array<string, mixed>>
     */
    public function lookupByPartNumber(
        string $partNumber,
        ?array $wholesalerIds = null,
        bool $activeOnly = true,
        ?string $preferredWarehouse = null,
    ): array {
        return $this->lookupByPartNumberParallel($partNumber, $wholesalerIds, $activeOnly, $preferredWarehouse);
    }

    /**
     * @param  list<string>|null  $wholesalerIds
     * @return list<array<string, mixed>>
     */
    public function lookupByPartNumberParallel(
        string $partNumber,
        ?array $wholesalerIds = null,
        bool $activeOnly = true,
        ?string $preferredWarehouse = null,
    ): array {
        $partNumber = SkuNormalizer::forLookup($partNumber);
        if ($partNumber === '') {
            return [];
        }

        $wholesalers = $this->resolveWholesalers($wholesalerIds, $activeOnly);
        if ($wholesalers->isEmpty()) {
            return [];
        }

        if ($wholesalers->count() === 1) {
            return $this->lookupForWholesaler($wholesalers->first(), $partNumber, $preferredWarehouse);
        }

        // Cada lookup fija sus preferidos (seguro con Concurrency por proceso o en serie).
        try {
            $tasks = $wholesalers->map(function (Wholesaler $wholesaler) use ($partNumber, $preferredWarehouse) {
                return function () use ($wholesaler, $partNumber, $preferredWarehouse) {
                    return $this->lookupForWholesaler($wholesaler, $partNumber, $preferredWarehouse);
                };
            })->all();

            /** @var list<list<array<string, mixed>>> $chunks */
            $chunks = Concurrency::run($tasks);

            return array_merge(...$chunks);
        } catch (\Throwable) {
            $offers = [];
            foreach ($wholesalers as $wholesaler) {
                $offers = array_merge(
                    $offers,
                    $this->lookupForWholesaler($wholesaler, $partNumber, $preferredWarehouse),
                );
            }

            return $offers;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lookupForWholesaler(
        Wholesaler $wholesaler,
        string $partNumber,
        ?string $preferredWarehouse = null,
    ): array {
        CtWarehouseDirectory::setPreferredForLookup($preferredWarehouse);
        try {
            $connector = $this->factory->for($wholesaler);
            $offers = [];

            foreach ($connector->lookup($wholesaler, $partNumber) as $offer) {
                $offers[] = OfferNormalizer::enrich($offer->toArray());
            }

            return $offers;
        } finally {
            CtWarehouseDirectory::setPreferredForLookup(null);
        }
    }

    /**
     * @param  list<string>|null  $wholesalerIds
     * @return \Illuminate\Database\Eloquent\Collection<int, Wholesaler>
     */
    private function resolveWholesalers(?array $wholesalerIds, bool $activeOnly): Collection
    {
        $query = Wholesaler::query()->orderBy('name', 'asc');
        if ($activeOnly) {
            $query->where('active', '=', true);
        }
        if ($wholesalerIds !== null && $wholesalerIds !== []) {
            $query->whereIn('id', $wholesalerIds);
        }

        $onlyCodes = config('quote_comparator.compare_wholesaler_codes', []);
        if ($onlyCodes !== []) {
            $query->whereIn('code', $onlyCodes);
        }

        return $query->get();
    }
}
