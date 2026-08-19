<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Health público mínimo: sin versiones, URLs ni mensajes de error internos.
 */
class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $databaseOk = false;

        try {
            DB::connection()->getPdo();
            $databaseOk = true;
        } catch (\Throwable) {
            $databaseOk = false;
        }

        return response()->json([
            'status' => $databaseOk ? 'ok' : 'degraded',
        ]);
    }
}
