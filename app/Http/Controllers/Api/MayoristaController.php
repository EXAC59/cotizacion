<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wholesaler;
use App\Services\Analytics\DashboardAnalyticsService;
use App\Services\Wholesalers\CtCatalogIndex;
use App\Services\Wholesalers\CvaCatalogIndex;
use App\Services\Wholesalers\WholesalerComparatorService;
use App\Services\Wholesalers\WholesalerLookupService;
use App\Services\Wholesalers\WholesalerSalesAliasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MayoristaController extends Controller
{
    public function index(Request $request, WholesalerSalesAliasService $aliases): JsonResponse
    {
        $query = Wholesaler::query()->orderBy('name', 'asc');

        if ($request->boolean('active')) {
            $query->where('active', '=', true);
        }

        $wholesalers = $query->get();
        $mask = $aliases->shouldMask($request->user());

        return response()->json([
            'data' => $wholesalers->map(function (Wholesaler $w) use ($mask, $aliases) {
                $row = $w->toApiArray();
                if ($mask) {
                    $alias = $aliases->aliasForCode($w->code);
                    $row['name'] = $alias;
                    $row['code'] = $alias;
                }

                return $row;
            })->values(),
            'meta' => [
                'active' => $wholesalers->where('active', true)->count(),
                'total' => $wholesalers->count(),
                'integrationTypes' => config('wholesalers.integration_labels', []),
            ],
        ]);
    }

    public function show(string $id, Request $request, WholesalerSalesAliasService $aliases): JsonResponse
    {
        $wholesaler = Wholesaler::query()->findOrFail($id);
        $row = $wholesaler->toApiArray();
        if ($aliases->shouldMask($request->user())) {
            $alias = $aliases->aliasForCode($wholesaler->code);
            $row['name'] = $alias;
            $row['code'] = $alias;
        }

        return response()->json($row);
    }

    public function stockBajo(
        Request $request,
        DashboardAnalyticsService $analytics,
        WholesalerSalesAliasService $aliases,
    ): JsonResponse {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $limit = (int) ($validated['limit'] ?? 100);
        $items = $analytics->lowStockItems($limit);

        if ($aliases->shouldMask($request->user())) {
            $items = array_map(
                fn (array $row): array => $aliases->maskOffer($row),
                $items,
            );
        }

        return response()->json([
            'threshold' => $analytics->lowStockThreshold(),
            'items' => $items,
            'count' => count($items),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $wholesaler = Wholesaler::query()->findOrFail($id);

        $validated = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        $wholesaler->update($validated);

        return response()->json($wholesaler->fresh()->toApiArray());
    }

    public function consultarForWholesaler(Request $request, string $id, WholesalerLookupService $lookup, WholesalerSalesAliasService $aliases): JsonResponse
    {
        $wholesaler = Wholesaler::query()->findOrFail($id);

        $validated = $request->validate([
            'partNumber' => ['required', 'string', 'max:80'],
            'preferredWarehouse' => ['nullable', 'string', 'max:80'],
        ]);

        $offers = $lookup->lookupForWholesaler(
            $wholesaler,
            $validated['partNumber'],
            $validated['preferredWarehouse'] ?? null,
        );

        if ($aliases->shouldMask($request->user())) {
            $offers = $aliases->maskOffers($offers);
        }

        return response()->json([
            'wholesalerId' => $wholesaler->id,
            'partNumber' => $validated['partNumber'],
            'offers' => $offers,
            'count' => count($offers),
        ]);
    }

    public function consultar(Request $request, WholesalerLookupService $lookup, WholesalerSalesAliasService $aliases): JsonResponse
    {
        $validated = $request->validate([
            'partNumber' => ['required', 'string', 'max:80'],
            'wholesalerIds' => ['nullable', 'array'],
            'wholesalerIds.*' => ['uuid'],
            'activeOnly' => ['nullable', 'boolean'],
            'preferredWarehouse' => ['nullable', 'string', 'max:80'],
        ]);

        $offers = $lookup->lookupByPartNumber(
            $validated['partNumber'],
            $validated['wholesalerIds'] ?? null,
            $validated['activeOnly'] ?? true,
            $validated['preferredWarehouse'] ?? null,
        );

        if ($aliases->shouldMask($request->user())) {
            $offers = $aliases->maskOffers($offers);
        }

        return response()->json([
            'partNumber' => $validated['partNumber'],
            'offers' => $offers,
            'count' => count($offers),
        ]);
    }

    public function comparar(Request $request, WholesalerComparatorService $comparator, WholesalerSalesAliasService $aliases): JsonResponse
    {
        $validated = $request->validate([
            'partNumber' => ['required', 'string', 'max:80'],
            'quantity' => ['nullable', 'numeric', 'min:0.0001'],
            'preferredWarehouse' => ['nullable', 'string', 'max:80'],
            'wholesalerIds' => ['nullable', 'array'],
            'wholesalerIds.*' => ['uuid'],
        ]);

        $result = $comparator->compare(
            $validated['partNumber'],
            (float) ($validated['quantity'] ?? 1),
            $validated['preferredWarehouse'] ?? null,
            $validated['wholesalerIds'] ?? null,
        );

        if ($aliases->shouldMask($request->user())) {
            $result = $aliases->maskComparatorPayload($result);
        }

        return response()->json($result);
    }

    public function compararLote(Request $request, WholesalerComparatorService $comparator, WholesalerSalesAliasService $aliases): JsonResponse
    {
        $validated = $request->validate([
            'preferredWarehouse' => ['nullable', 'string', 'max:8000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.partNumber' => ['required', 'string', 'max:80'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0.0001'],
            'lines.*.preferredWarehouse' => ['nullable', 'string', 'max:8000'],
        ]);

        $results = $comparator->compareBatch(
            $validated['lines'],
            $validated['preferredWarehouse'] ?? null,
        );

        if ($aliases->shouldMask($request->user())) {
            $results = $aliases->maskBatchResults($results);
        }

        return response()->json([
            'results' => $results,
            'count' => count($results),
        ]);
    }

    public function autocompleteCt(Request $request, CtCatalogIndex $catalog): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:120'],
            'sku' => ['nullable', 'string', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = (int) ($validated['limit'] ?? 15);
        $sku = trim((string) ($validated['sku'] ?? ''));
        $descripcion = trim((string) ($validated['descripcion'] ?? ''));
        $q = trim((string) ($validated['q'] ?? ''));

        if ($sku !== '' || $descripcion !== '') {
            $results = $catalog->searchMatching($sku, $descripcion, $limit);
        } elseif ($q !== '') {
            $results = $catalog->search($q, $limit);
        } else {
            return response()->json([
                'message' => 'Indica q, sku o descripcion (mín. 2 caracteres).',
                'errors' => ['q' => ['Requerido si no envías sku/descripcion.']],
            ], 422);
        }

        return response()->json([
            'data' => $results,
            'count' => count($results),
        ]);
    }

    public function autocompleteCva(Request $request, CvaCatalogIndex $catalog): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:120'],
            'sku' => ['nullable', 'string', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $limit = (int) ($validated['limit'] ?? 15);
        $sku = trim((string) ($validated['sku'] ?? ''));
        $descripcion = trim((string) ($validated['descripcion'] ?? ''));
        $q = trim((string) ($validated['q'] ?? ''));

        if ($sku !== '' || $descripcion !== '') {
            $results = $catalog->searchMatching($sku, $descripcion, $limit);
        } elseif ($q !== '') {
            $results = $catalog->search($q, $limit);
        } else {
            return response()->json([
                'message' => 'Indica q, sku o descripcion (mín. 2 caracteres).',
                'errors' => ['q' => ['Requerido si no envías sku/descripcion.']],
            ], 422);
        }

        return response()->json([
            'data' => $results,
            'count' => count($results),
            'syncedAt' => $catalog->syncedAt(),
            'catalogCount' => $catalog->count(),
        ]);
    }
}
