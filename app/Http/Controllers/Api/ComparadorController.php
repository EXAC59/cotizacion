<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComparisonJob;
use App\Services\Wholesalers\ComparatorJobService;
use App\Services\Wholesalers\WholesalerSalesAliasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComparadorController extends Controller
{
    public function disparar(Request $request, ComparatorJobService $jobs): JsonResponse
    {
        $validated = $request->validate([
            'partNumber' => ['required', 'string', 'max:80'],
            'quantity' => ['nullable', 'numeric', 'min:0.0001'],
            'preferredWarehouse' => ['nullable', 'string', 'max:8000'],
            'preferredWarehouses' => ['nullable', 'array', 'max:500'],
            'preferredWarehouses.*' => ['string', 'max:40'],
            'context' => ['nullable', 'array'],
            'context.screen' => ['nullable', 'string', 'max:40'],
            'context.lineId' => ['nullable', 'string', 'max:80'],
            'context.quoteId' => ['nullable', 'string', 'max:80'],
            'context.requestId' => ['nullable', 'string', 'max:80'],
        ]);

        $preferred = $validated['preferredWarehouse'] ?? null;
        if (! empty($validated['preferredWarehouses']) && is_array($validated['preferredWarehouses'])) {
            $preferred = implode(',', array_map(
                static fn ($w) => strtoupper(trim((string) $w)),
                $validated['preferredWarehouses'],
            ));
        }

        $job = $jobs->dispatch(
            $validated['partNumber'],
            (float) ($validated['quantity'] ?? 1),
            $preferred,
            $validated['context'] ?? [],
        );

        return response()->json([
            'jobId' => $job->id,
            'status' => $job->status,
            'message' => 'Comparación iniciada',
        ], 202);
    }

    public function show(string $id, Request $request, WholesalerSalesAliasService $aliases): JsonResponse
    {
        $job = ComparisonJob::query()->findOrFail($id);
        $payload = $job->toApiArray();

        if ($aliases->shouldMask($request->user())) {
            $payload = $aliases->maskComparatorPayload($payload);
        }

        return response()->json($payload);
    }
}
