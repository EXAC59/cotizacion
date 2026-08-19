<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Rbac\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RbacController extends Controller
{
    public function __construct(private readonly RbacService $rbac) {}

    public function permissions(): JsonResponse
    {
        if (! $this->rbac->isAvailable()) {
            return response()->json([
                'available' => false,
                'message' => 'Tablas RBAC no disponibles.',
            ], 503);
        }

        return response()->json([
            'available' => true,
            'rolePermissions' => $this->rbac->getRolePermissionMap(),
        ]);
    }

    public function updatePermissions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rolePermissions' => ['required', 'array'],
        ]);

        $rolePermissions = $this->rbac->updateRolePermissionMap($validated['rolePermissions']);

        return response()->json([
            'message' => 'Permisos actualizados.',
            'rolePermissions' => $rolePermissions,
        ]);
    }

    public function resetPermissions(): JsonResponse
    {
        $rolePermissions = $this->rbac->resetToDefaults();

        return response()->json([
            'message' => 'Permisos restaurados a valores por defecto.',
            'rolePermissions' => $rolePermissions,
        ]);
    }
}
