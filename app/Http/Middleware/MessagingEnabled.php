<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MessagingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('messaging.enabled'), 404);

        return $next($request);
    }
}
