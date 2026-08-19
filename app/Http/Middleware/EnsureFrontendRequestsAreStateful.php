<?php

namespace App\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful as SanctumEnsureFrontendRequestsAreStateful;

/**
 * Sanctum solo carga sesión si detecta Referer/Origin del SPA.
 * Algunos navegadores no envían esos headers en GET; si ya hay cookie de sesión, tratamos la petición como stateful.
 */
class EnsureFrontendRequestsAreStateful extends SanctumEnsureFrontendRequestsAreStateful
{
    public static function fromFrontend($request): bool
    {
        if ($request->is('sanctum/csrf-cookie', 'api/login', 'api/logout', 'api/user')) {
            return true;
        }

        $sessionCookie = config('session.cookie');

        if ($sessionCookie && $request->cookies->has($sessionCookie)) {
            return true;
        }

        return parent::fromFrontend($request);
    }
}
