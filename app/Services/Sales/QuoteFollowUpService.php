<?php

namespace App\Services\Sales;

use App\Models\Quote;
use App\Models\QuoteFollowUpEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuoteFollowUpService
{
    /** Recordatorios: cotizaciones listas para que ventas negocie con el cliente. */
    public const UNSENT_FOR_CLIENT_STATUSES = [
        'pendiente_envio',
    ];

    public static function isUnsentForClient(Quote $quote): bool
    {
        if ($quote->sent_at !== null) {
            return false;
        }

        return in_array($quote->status, self::UNSENT_FOR_CLIENT_STATUSES, true);
    }

    public function __construct(
        private readonly SalesNotificationService $notifications,
    ) {}

    /**
     * @param  array{status: string, remindDate?: string|null, invoice?: string|null, comments?: string|null}  $payload
     * @return array{quote: Quote, event: QuoteFollowUpEvent}
     *
     * @throws ValidationException
     */
    public function update(Quote $quote, User $actor, array $payload): array
    {
        if (! in_array($actor->role_slug, ['ventas', 'administrador'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Solo ventas puede marcar el estatus de seguimiento.',
            ]);
        }

        if (! self::isUnsentForClient($quote)) {
            throw ValidationException::withMessages([
                'status' => 'El recordatorio solo aplica a cotizaciones en Lista / Terminada que aún no se han enviado al cliente.',
            ]);
        }

        if ($actor->role_slug === 'ventas' && ! $quote->isVisibleToSalesperson($actor)) {
            throw ValidationException::withMessages([
                'status' => 'Solo puedes dar seguimiento a cotizaciones que creaste o que compras te envió.',
            ]);
        }

        $status = $payload['status'] ?? '';
        if (! in_array($status, ['negociacion', 'ganada', 'perdida'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Selecciona un estatus de recordatorio.',
            ]);
        }

        $invoice = trim((string) ($payload['invoice'] ?? ''));
        $comments = trim((string) ($payload['comments'] ?? ''));
        $remindAt = null;

        if ($status === 'ganada') {
            $len = mb_strlen($invoice);
            if ($len < 2 || $len > 60) {
                throw ValidationException::withMessages([
                    'invoice' => 'Factura/ticket: entre 2 y 60 caracteres.',
                ]);
            }
            $comments = '';
        }

        if ($status === 'perdida') {
            $len = mb_strlen($comments);
            if ($len < 3 || $len > 500) {
                throw ValidationException::withMessages([
                    'comments' => 'Comentarios: entre 3 y 500 caracteres.',
                ]);
            }
            $invoice = '';
        }

        if ($status === 'negociacion') {
            $dateStr = trim((string) ($payload['remindDate'] ?? ''));
            $remindAt = $this->parseRemindDate($dateStr);
            $invoice = '';
            $comments = '';
        }

        $fromStatus = $quote->follow_up_status;
        $labels = [
            'negociacion' => 'Negociación',
            'ganada' => 'Ganada',
            'perdida' => 'Perdida',
        ];

        return DB::transaction(function () use ($quote, $actor, $status, $fromStatus, $invoice, $comments, $remindAt, $labels) {
            $now = now();
            $quote->update([
                'follow_up_status' => $status,
                'follow_up_invoice' => $status === 'ganada' ? $invoice : null,
                'follow_up_comments' => $status === 'perdida' ? $comments : null,
                'follow_up_remind_at' => $remindAt,
                'follow_up_at' => $now,
                'follow_up_by' => $actor->id,
            ]);

            $event = QuoteFollowUpEvent::query()->create([
                'quote_id' => $quote->id,
                'user_id' => $actor->id,
                'from_status' => $fromStatus,
                'to_status' => $status,
                'remind_at' => $remindAt,
                'invoice' => $status === 'ganada' ? $invoice : null,
                'comments' => $status === 'perdida' ? $comments : null,
                'created_at' => $now,
            ]);

            $this->notifications->notifyComprasFollowUp($quote, $actor, $labels[$status]);

            return [
                'quote' => $quote->fresh(['followUpByUser', 'followUpEvents.user']),
                'event' => $event,
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyForQuote(Quote $quote): array
    {
        $quote->loadMissing(['followUpEvents.user']);

        return $quote->followUpEvents
            ->sortByDesc(fn (QuoteFollowUpEvent $e) => $e->created_at?->timestamp ?? 0)
            ->values()
            ->map(fn (QuoteFollowUpEvent $ev) => [
                'id' => $ev->id,
                'userName' => $ev->user?->name ?? 'Usuario',
                'fromStatus' => $ev->from_status,
                'toStatus' => $ev->to_status,
                'remindAt' => $ev->remind_at?->toIso8601String(),
                'invoice' => $ev->invoice,
                'comments' => $ev->comments,
                'createdAt' => $ev->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function followUpPayload(Quote $quote): ?array
    {
        if (! $quote->follow_up_status) {
            return null;
        }

        $quote->loadMissing('followUpByUser');

        return [
            'status' => $quote->follow_up_status,
            'invoice' => $quote->follow_up_invoice,
            'comments' => $quote->follow_up_comments,
            'remindAt' => $quote->follow_up_remind_at?->toIso8601String(),
            'at' => $quote->follow_up_at?->toIso8601String(),
            'byUser' => $quote->followUpByUser?->name,
            'byUserId' => $quote->follow_up_by,
        ];
    }

    private function parseRemindDate(string $dateStr): Carbon
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateStr, $m)) {
            throw ValidationException::withMessages([
                'remindDate' => 'Elige la fecha de seguimiento.',
            ]);
        }

        $chosen = Carbon::createFromDate((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
        $today = Carbon::today();

        if ($chosen->lt($today)) {
            throw ValidationException::withMessages([
                'remindDate' => 'La fecha de seguimiento no puede ser anterior a hoy.',
            ]);
        }

        return $chosen->endOfDay();
    }
}
