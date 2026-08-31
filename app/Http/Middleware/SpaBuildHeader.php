<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SpaBuildHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $path = public_path('spa/build-id.txt');
        if (is_file($path)) {
            $buildId = trim((string) file_get_contents($path));
            if ($buildId !== '') {
                $response->headers->set('X-Spa-Build-Id', $buildId);
            }
        }

        return $response;
    }
}
