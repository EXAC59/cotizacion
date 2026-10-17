<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use App\Models\QuoteClosingResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuoteClosingResultService
{
    public const LABELS = ['perdida' => 'Perdida', 'negociacion' => 'Negociación',
        'ganada_parcialmente' => 'Ganada parcialmente', 'ganada' => 'Ganada'];

    public function __construct(private readonly QuoteMessagingService $chat) {}

    public function canEdit(Quote $quote, User $actor): bool
    {
        return $actor->active && $quote->sent_at !== null && ($actor->isAdministrator() || $actor->role_slug === 'gerente_compras'
            || ($actor->role_slug === 'ventas' && (int) $this->chat->sales($quote)?->id === (int) $actor->id));
    }

    public function save(string $id, User $actor, array $data): QuoteClosingResult
    {
        return DB::transaction(function () use ($id, $actor, $data) {
            $quote = Quote::query()->lockForUpdate()->findOrFail($id);
            abort_unless($this->canEdit($quote, $actor), 403, 'Solo Compras, Administración o el vendedor responsable puede registrar el cierre de una cotización enviada.');
            $current = $quote->closingResult;
            if ($current?->request_id === $data['requestId']) return $current;
            abort_if(($current?->version ?? 0) !== (int) $data['version']
                || $quote->updated_at->format('Y-m-d H:i:s.u') !== $data['quoteVersion'],
                409, 'La cotización cambió. Cierra el panel y vuelve a abrirlo antes de guardar.');

            $status = $data['status'];
            $previousStatus = $current?->status;
            $previousLabel = $previousStatus ? self::LABELS[$previousStatus] : 'Enviada al cliente';
            $invoice = trim((string) ($data['invoice'] ?? ''));
            $comments = trim((string) ($data['comments'] ?? ''));
            $requiresInvoice = in_array($status, ['ganada', 'ganada_parcialmente'], true);
            if ($requiresInvoice && $invoice === '') {
                throw ValidationException::withMessages(['invoice' => 'Captura la factura para guardar como '.self::LABELS[$status].'.']);
            }
            if (in_array($status, ['perdida', 'negociacion'], true) && $comments === '') {
                throw ValidationException::withMessages(['comments' => $status === 'perdida'
                    ? 'Escribe el motivo de la pérdida.' : 'Escribe la aclaración de la negociación.']);
            }
            $selected = [];
            if ($status === 'ganada_parcialmente') {
                $lines = $quote->lines()->orderBy('line_order')->get();
                $ids = $data['selectedLineIds'] ?? [];
                if (count($ids) === 0 || count(array_diff($ids, $lines->modelKeys())) > 0) {
                    throw ValidationException::withMessages(['selectedLineIds' => 'Selecciona al menos una partida de esta cotización.']);
                }
                if (count($ids) >= $lines->count()) {
                    throw ValidationException::withMessages(['selectedLineIds' => 'Si el cliente compró todas las partidas, elige Ganada.']);
                }
                // Retain the purchased lines even if the quotation is edited later.
                $selected = $lines->whereIn('id', $ids)->map(fn ($line) => ['id' => $line->id,
                    'product' => $line->product, 'partNumber' => $line->part_number,
                    'quantity' => (float) $line->quantity, 'salePrice' => (float) $line->sale_price,
                    'amount' => (float) $line->amount])->values()->all();
            }
            $result = QuoteClosingResult::query()->updateOrCreate(['quote_id' => $quote->id], [
                'status' => $status, 'invoice' => $requiresInvoice ? $invoice : null,
                'comments' => in_array($status, ['negociacion', 'perdida'], true) ? $comments : null,
                'selected_lines' => $selected, 'user_id' => $actor->id,
                'version' => ($current?->version ?? 0) + 1, 'request_id' => $data['requestId'],
            ]);
            $detail = match ($status) {
                'ganada' => 'Factura: '.$invoice,
                'perdida' => 'Motivo de la pérdida: '.$comments,
                'negociacion' => 'Aclaración de la negociación: '.$comments,
                'ganada_parcialmente' => 'Factura: '.$invoice.'. Partidas compradas: '.collect($selected)->map(fn ($line) => $line['quantity'].' × '.$line['product'])->implode('; '),
            };
            $body = $previousStatus === $status
                ? $actor->name.' actualizó los datos del cierre de '.$quote->folio.' ('.self::LABELS[$status].'). '.$detail
                : $actor->name.' cambió el estatus de cierre de '.$quote->folio.' de '.$previousLabel.' a '.self::LABELS[$status].'. '.$detail;
            $this->chat->initialize($quote);
            $this->chat->event($quote, $actor, $body);
            $this->chat->notifyClosing($quote, $actor, $body);
            app(QuoteActivityService::class)->record($quote, $actor);

            return $result;
        });
    }
}
