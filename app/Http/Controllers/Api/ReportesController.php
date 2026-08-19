<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportesController extends Controller
{
    public function index(Request $request, DashboardAnalyticsService $analytics): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json(
            $analytics->reportesPayload($validated['from'] ?? null, $validated['to'] ?? null)
        );
    }
}
