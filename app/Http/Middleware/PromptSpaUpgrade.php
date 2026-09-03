<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clientes SPA sin X-Spa-Build-Id (bundle viejo) reciben 401 para forzar recarga.
 */
class PromptSpaUpgrade
{
    public const UPGRADE_NOTIFICATION_ID = '00000000-0000-0000-0000-000000000001';

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing')) {
            return $next($request);
        }

        if ($request->is('api/notificaciones') && $request->method() === 'GET') {
            $clientBuild = trim((string) $request->header('X-Spa-Build-Id'));
            $serverBuild = $this->serverBuildId();

            if ($serverBuild !== null && ($clientBuild === '' || $clientBuild !== $serverBuild)) {
                return response()->json([
                    'message' => 'SPA upgrade required',
                    'spaUpgradeRequired' => true,
                    'spaBuildId' => $serverBuild,
                ], 401)->header('X-Spa-Upgrade-Required', '1');
            }
        }

        return $next($request);
    }

    private function serverBuildId(): ?string
    {
        $path = public_path('spa/build-id.txt');
        if (! is_file($path)) {
            return null;
        }

        $id = trim((string) file_get_contents($path));

        return $id !== '' ? $id : null;
    }
}
