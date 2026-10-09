<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use App\Models\QuoteInternalNote;

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
     * Estados de los envíos aceptados por Meta. La firma se valida en middleware.
     */
    public function receive(Request $request): Response
    {
        $labels = ['sent' => 'enviado', 'delivered' => 'entregado', 'read' => 'leído', 'failed' => 'fallido'];
        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                if ((string) ($value['metadata']['phone_number_id'] ?? '') !== (string) config('whatsapp.phone_number_id')
                    || ! filled(config('whatsapp.phone_number_id'))) {
                    continue;
                }
                foreach ($value['statuses'] ?? [] as $status) {
                    $id = $status['id'] ?? '';
                    $label = $labels[$status['status'] ?? ''] ?? null;
                    if (! is_string($id) || ! preg_match('/^wamid\.[A-Za-z0-9=+\/_-]{1,240}$/', $id) || $label === null) {
                        continue;
                    }
                    $receipt = QuoteInternalNote::query()->where('body', 'like', '% Mensaje: '.$id.'.')->get()
                        ->first(fn ($note) => str_ends_with($note->body, ' Mensaje: '.$id.'.'));
                    if ($receipt === null) {
                        continue;
                    }
                    $body = 'WhatsApp: mensaje '.$id.' — '.$label.'.';
                    if (! QuoteInternalNote::query()->where('quote_id', $receipt->quote_id)->where('body', $body)->exists()) {
                        QuoteInternalNote::query()->create([
                            'quote_id' => $receipt->quote_id, 'user_id' => null,
                            'body' => $body, 'created_at' => now(),
                        ]);
                    }
                }
            }
        }
        Log::info('WhatsApp webhook event', [
            'object' => $request->input('object'),
            'entry_count' => is_array($request->input('entry')) ? count($request->input('entry')) : 0,
        ]);

        return response('EVENT_RECEIVED', 200)->header('Content-Type', 'text/plain');
    }
}
