<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\DashboardAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardAnalyticsService $analytics): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json(
            $this->payloadForUser(
                $request,
                $analytics->dashboardPayload($validated['from'] ?? null, $validated['to'] ?? null),
            )
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payloadForUser(Request $request, array $payload): array
    {
        $user = $request->user();
        $user?->loadMissing('role');
        $role = $user?->role_slug;

        if ($role === 'ventas') {
            $payload = $this->stripExecutiveMetrics($payload);
        }

        // Alertas de integración de mayoristas: solo compras y admin.
        if (! in_array($role, ['administrador', 'gerente_compras'], true)) {
            if (isset($payload['alerts']) && is_array($payload['alerts'])) {
                $payload['alerts']['integrationIssues'] = [];
                $payload['alerts']['unsentRequests'] = [];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripExecutiveMetrics(array $payload): array
    {
        $payload['monthlyRealizedProfit'] = 0;
        $payload['monthlyPotentialProfit'] = 0;
        $payload['averageTicket'] = 0;
        $payload['wonQuotes'] = 0;
        $payload['lostQuotes'] = 0;
        $payload['winRate'] = 0;
        $payload['topRequestedProducts'] = [];
        $payload['topQuotedProducts'] = [];

        if (isset($payload['alerts']) && is_array($payload['alerts'])) {
            $payload['alerts']['lowStock'] = [];
        }

        return $payload;
    }
}
