<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyN8nWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('n8n.webhook_secret');

        if (! $secret) {
            return $next($request);
        }

        $provided = $request->header('X-N8N-Webhook-Secret')
            ?? $request->header('X-Webhook-Secret');

        if (! is_string($provided) || ! hash_equals($secret, $provided)) {
            return response()->json(['message' => 'Webhook no autorizado'], 401);
        }

        return $next($request);
    }
}
