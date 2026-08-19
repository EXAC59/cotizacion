<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MarcaResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarcaController extends Controller
{
    public function __construct(
        private readonly MarcaResolverService $marcaResolver,
    ) {}

    public function resolver(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.product' => ['required', 'string'],
            'lineas.*.partNumber' => ['nullable', 'string'],
            'lineas.*.brand' => ['nullable', 'string'],
            'lineas.*.quantity' => ['nullable', 'numeric'],
            'lineas.*.description' => ['nullable', 'string'],
            'lineas.*.unit' => ['nullable', 'string'],
        ]);

        $lineas = $this->marcaResolver->resolveLines($validated['lineas']);

        return response()->json([
            'lineas' => $lineas,
            'resolved_count' => count(array_filter($lineas, fn (array $l) => $l['brand_resolved'])),
        ]);
    }
}
