<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sales\AssignToSalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VentasUserController extends Controller
{
    public function __construct(
        private readonly AssignToSalesService $assignToSales,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if ($actor === null) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $this->assignToSales->assertCanAssign($actor);

        return response()->json([
            'data' => $this->assignToSales->listActiveSalespeople(),
        ]);
    }
}
