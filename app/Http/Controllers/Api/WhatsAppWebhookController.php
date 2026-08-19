<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Verificación del webhook (Meta GET hub.challenge).
     */
    public function verify(Request $request): Response
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        $expected = (string) config('whatsapp.verify_token', '');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verify rejected', [
            'mode' => $mode,
            'token_match' => $expected !== '' && hash_equals($expected, $token),
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Eventos entrantes (mensajes / estados). Por ahora solo ack 200.
     */
    public function receive(Request $request): Response
    {
        Log::info('WhatsApp webhook event', [
            'object' => $request->input('object'),
            'entry_count' => is_array($request->input('entry')) ? count($request->input('entry')) : 0,
        ]);

        return response('EVENT_RECEIVED', 200)->header('Content-Type', 'text/plain');
    }
}
