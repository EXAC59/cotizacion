<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida X-Hub-Signature-256 (HMAC SHA-256 del App Secret de Meta).
 */
class VerifyWhatsAppSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('whatsapp.app_secret', '');
        if ($secret === '') {
            Log::error('WhatsApp webhook rejected: WHATSAPP_APP_SECRET no configurado');

            return response('Webhook misconfigured', 503);
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        if ($header === '' || ! str_starts_with($header, 'sha256=')) {
            Log::warning('WhatsApp webhook rejected: firma ausente o inválida');

            return response('Forbidden', 403);
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if (! hash_equals($expected, $header)) {
            Log::warning('WhatsApp webhook rejected: firma no coincide');

            return response('Forbidden', 403);
        }

        return $next($request);
    }
}
