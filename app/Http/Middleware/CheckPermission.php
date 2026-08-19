<?php

namespace App\Http\Middleware;

use App\Services\Rbac\RbacService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function __construct(private readonly RbacService $rbac) {}

    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $user->loadMissing('role');

        $actions = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode('|', $action),
        )));

        foreach ($actions as $allowedAction) {
            if ($this->rbac->userCan($user, $module, $allowedAction)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'No autorizado.'], 403);
    }
}
