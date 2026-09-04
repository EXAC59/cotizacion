<?php

namespace App\Services\Sales;

use App\Models\AppSetting;
use App\Models\Quote;
use App\Models\SalesNotification;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SalesNotificationService
{
    public const AUDIENCE_VENTAS = 'ventas';

    public const AUDIENCE_COMPRAS = 'compras';

    public const REASON_LISTA = 'lista_terminada';

    public const REASON_EN_ELABORACION = 'en_elaboracion';

    public const REASON_SIN_AVANCE = 'sin_avance';

    public const REASON_SEGUIMIENTO = 'seguimiento_actualizado';

    /** Comentario libre de compras hacia ventas sobre el recordatorio */
    public const REASON_COMENTARIO = 'comentario_compras';

    /**
     * @return array{
     *   eligible: bool,
     *   reasonCode: string|null,
     *   blockReason: string|null,
     *   daysIdle: int,
     *   pendingUnread: bool,
     *   notifyRecipientId: int|null,
     *   notifyRecipientName: string|null
     * }
     */
    public function eligibility(Quote $quote): array
    {
        $daysIdle = $this->daysIdle($quote);
        $recipient = $this->resolveVentasRecipient($quote);
        $recipientMeta = [
            'notifyRecipientId' => $recipient?->id,
            'notifyRecipientName' => $recipient?->name,
        ];

        if (in_array($quote->follow_up_status, ['ganada', 'perdida'], true)) {
            return [
                'eligible' => false,
                'reasonCode' => null,
                'blockReason' => 'Esta cotización ya está cerrada (ganada o perdida).',
                'daysIdle' => $daysIdle,
                'pendingUnread' => false,
                ...$recipientMeta,
            ];
        }

        if ($quote->status === 'pendiente_envio') {
            return [
                'eligible' => true,
                'reasonCode' => self::REASON_LISTA,
                'blockReason' => null,
                'daysIdle' => $daysIdle,
                'pendingUnread' => $this->hasUnreadDedupe($quote->id, self::REASON_LISTA, self::AUDIENCE_VENTAS),
                ...$recipientMeta,
            ];
        }

        $threshold = AppSetting::current()->resolvedUnansweredQuoteDays();
        if ($quote->status === 'en_elaboracion' && $daysIdle >= $threshold) {
            return [
                'eligible' => true,
                'reasonCode' => self::REASON_SIN_AVANCE,
                'blockReason' => null,
                'daysIdle' => $daysIdle,
                'pendingUnread' => $this->hasUnreadDedupe($quote->id, self::REASON_SIN_AVANCE, self::AUDIENCE_VENTAS),
                ...$recipientMeta,
            ];
        }

        if ($quote->status === 'en_elaboracion') {
            return [
                'eligible' => false,
                'reasonCode' => null,
                'blockReason' => $this->elaboracionBelowThresholdReason($daysIdle, $threshold),
                'daysIdle' => $daysIdle,
                'pendingUnread' => false,
                ...$recipientMeta,
            ];
        }

        return [
            'eligible' => false,
            'reasonCode' => null,
            'blockReason' => 'Por ahora no aplica un aviso automático a ventas.',
            'daysIdle' => $daysIdle,
            'pendingUnread' => false,
            ...$recipientMeta,
        ];
    }

    public function daysIdle(Quote $quote): int
    {
        $lastActivity = $quote->last_opened_at && $quote->last_opened_at->gt($quote->updated_at)
            ? $quote->last_opened_at
            : $quote->updated_at;

        if (! $lastActivity) {
            return 0;
        }

        return (int) $lastActivity->diffInDays(now());
    }

    public function defaultMessage(Quote $quote, string $reasonCode): string
    {
        if ($reasonCode === self::REASON_LISTA) {
            return 'Lista / Terminada: lista para envío o seguimiento.';
        }

        if ($reasonCode === self::REASON_EN_ELABORACION) {
            return 'En elaboración: cotización pendiente de terminar.';
        }

        if ($reasonCode === self::REASON_SIN_AVANCE) {
            $days = $this->daysIdle($quote);

            return "Sin avance: lleva {$days} días en elaboración.";
        }

        return "Cotización {$quote->folio}: revisar seguimiento.";
    }

    public function reasonLabel(string $reasonCode): string
    {
        return match ($reasonCode) {
            self::REASON_LISTA => 'Lista / Terminada',
            self::REASON_EN_ELABORACION => 'En elaboración',
            self::REASON_SIN_AVANCE => 'Sin avance en elaboración',
            self::REASON_SEGUIMIENTO => 'Recordatorio actualizado',
            self::REASON_COMENTARIO => 'Comentario de compras',
            default => 'Aviso',
        };
    }

    /**
     * Avisos automáticos a ventas: cotizaciones en elaboración idle ≥ N días
     * (N = AppSetting unanswered_quote_days, default 3).
     * Respeta dedupe: no crea otro si ya hay uno no leído con el mismo motivo.
     *
     * @return array{created: int, skipped: int, notifiedQuoteIds: list<string>}
     */
    public function autoNotifyIdleQuotes(int $limit = 100): array
    {
        $threshold = AppSetting::current()->resolvedUnansweredQuoteDays();
        $cutoff = now()->subDays($threshold);

        $quotes = Quote::query()
            ->with(['creator.role', 'statusEvents.user.role', 'lockedByUser.role', 'client'])
            ->where('status', 'en_elaboracion')
            ->where(function ($q) {
                $q->whereNull('follow_up_status')
                    ->orWhereNotIn('follow_up_status', ['ganada', 'perdida']);
            })
            ->where('updated_at', '<', $cutoff)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_opened_at')
                    ->orWhere('last_opened_at', '<', $cutoff);
            })
            ->orderBy('updated_at')
            ->limit(max(1, min($limit, 500)))
            ->get();

        $created = 0;
        $skipped = 0;
        $notifiedQuoteIds = [];

        foreach ($quotes as $quote) {
            $eligibility = $this->eligibility($quote);
            if (! ($eligibility['eligible'] ?? false) || ($eligibility['reasonCode'] ?? null) !== self::REASON_SIN_AVANCE) {
                $skipped++;

                continue;
            }

            if ($eligibility['pendingUnread'] ?? false) {
                $skipped++;

                continue;
            }

            $recipient = $this->resolveVentasRecipient($quote);
            if ($recipient === null) {
                $skipped++;

                continue;
            }

            SalesNotification::query()->create([
                'quote_id' => $quote->id,
                'sender_id' => null,
                'recipient_id' => $recipient->id,
                'audience' => self::AUDIENCE_VENTAS,
                'reason_code' => self::REASON_SIN_AVANCE,
                'message' => $this->defaultMessage($quote, self::REASON_SIN_AVANCE),
                'read_at' => null,
            ]);

            $created++;
            $notifiedQuoteIds[] = $quote->id;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'notifiedQuoteIds' => $notifiedQuoteIds,
        ];
    }

    /**
     * Aviso directo de asignación (compras↔ventas) sin reglas de elegibilidad.
     */
    public function notifyAssignment(
        Quote $quote,
        User $sender,
        User $recipient,
        string $audience,
        string $message,
    ): SalesNotification {
        $text = trim($message);
        if ($text === '') {
            $text = "Cotización {$quote->folio} asignada para tu seguimiento.";
        }

        return SalesNotification::query()->create([
            'quote_id' => $quote->id,
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'audience' => $audience,
            'reason_code' => self::REASON_COMENTARIO,
            'message' => $text,
            'read_at' => null,
        ]);
    }

    /**
     * Compras envía un comentario sobre el recordatorio al vendedor de la cotización.
     *
     * @throws ValidationException
     */
    public function notifySales(Quote $quote, User $sender, ?string $message = null, ?int $recipientId = null): SalesNotification
    {
        if (! in_array($sender->role_slug, ['administrador', 'gerente_compras'], true)) {
            throw ValidationException::withMessages([
                'quoteId' => 'Solo compras o administrador pueden avisar a ventas.',
            ]);
        }

        $text = trim((string) $message);
        $len = mb_strlen($text);
        if ($len < 3 || $len > 1000) {
            throw ValidationException::withMessages([
                'message' => 'Escribe un comentario sobre el recordatorio (3 a 1000 caracteres).',
            ]);
        }

        $eligibility = $this->eligibility($quote);
        $recipient = $recipientId !== null
            ? User::query()->with('role')->find($recipientId)
            : $this->resolveVentasRecipient($quote);

        if (! $this->isActiveVentas($recipient)) {
            throw ValidationException::withMessages([
                'quoteId' => 'No hay un usuario de ventas al que enviar el comentario.',
            ]);
        }

        // Motivo contextual si es candidata; si no, comentario libre de recordatorio
        $reasonCode = ($eligibility['eligible'] && $eligibility['reasonCode'])
            ? $eligibility['reasonCode']
            : self::REASON_COMENTARIO;

        $existing = SalesNotification::query()
            ->where('quote_id', $quote->id)
            ->where('audience', self::AUDIENCE_VENTAS)
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->first();

        if ($existing) {
            $existing->update([
                'sender_id' => $sender->id,
                'recipient_id' => $recipient->id,
                'reason_code' => $reasonCode,
                'message' => $text,
            ]);

            return $existing->fresh(['quote.client', 'sender', 'recipient']);
        }

        return SalesNotification::query()->create([
            'quote_id' => $quote->id,
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'audience' => self::AUDIENCE_VENTAS,
            'reason_code' => $reasonCode,
            'message' => $text,
            'read_at' => null,
        ]);
    }

    public function notifyComprasFollowUp(Quote $quote, User $actor, string $statusLabel): SalesNotification
    {
        // Marcar leídos avisos a ventas de esta cotización
        SalesNotification::query()
            ->where('quote_id', $quote->id)
            ->where('audience', self::AUDIENCE_VENTAS)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        // Evitar spam: si ya hay unread de seguimiento para esta quote, actualizar mensaje
        $existing = SalesNotification::query()
            ->where('quote_id', $quote->id)
            ->where('audience', self::AUDIENCE_COMPRAS)
            ->where('reason_code', self::REASON_SEGUIMIENTO)
            ->whereNull('read_at')
            ->first();

        $message = "{$actor->name} marcó {$statusLabel} en {$quote->folio}.";

        if ($existing) {
            $existing->update([
                'sender_id' => $actor->id,
                'message' => $message,
            ]);

            return $existing->fresh();
        }

        return SalesNotification::query()->create([
            'quote_id' => $quote->id,
            'sender_id' => $actor->id,
            'recipient_id' => null,
            'audience' => self::AUDIENCE_COMPRAS,
            'reason_code' => self::REASON_SEGUIMIENTO,
            'message' => $message,
            'read_at' => null,
        ]);
    }

    /**
     * @return array{data: list<array<string, mixed>>, unreadCount: int}
     */
    public function listForUser(User $user, int $limit = 40): array
    {
        $query = SalesNotification::query()
            ->with(['quote.client', 'sender', 'recipient'])
            ->orderByDesc('created_at')
            ->limit($limit);

        $role = $user->role_slug;
        if ($role === 'ventas') {
            $query->where('audience', self::AUDIENCE_VENTAS)
                ->where('recipient_id', $user->id)
                ->whereHas('quote', function ($quoteQuery) use ($user) {
                    $quoteQuery->whereHas('creator.role', fn ($role) => $role->where('slug', 'ventas'))
                        ->where(function ($maker) use ($user) {
                            $maker->where('created_by', $user->id)
                                ->orWhere(fn ($byName) => $byName->madeByDisplayName($user));
                        });
                });
        } elseif (in_array($role, ['gerente_compras', 'administrador'], true)) {
            $query->where('audience', self::AUDIENCE_COMPRAS);
        } else {
            return ['data' => [], 'unreadCount' => 0];
        }

        $items = $query->get();
        $data = $items->map(fn (SalesNotification $n) => $this->toApiArray($n))->values()->all();
        $unreadCount = $items->whereNull('read_at')->count();

        if ($role === 'ventas') {
            $alreadyNotified = $items->pluck('quote_id')->filter()->all();
            $pipeline = $this->pipelineAlertsForVentas($user, $alreadyNotified);
            $data = array_values(array_merge($pipeline, $data));
            $unreadCount += count($pipeline);
        }

        return [
            'data' => $data,
            'unreadCount' => $unreadCount,
        ];
    }

    /**
     * Alertas de dashboard convertidas en avisos: cotizaciones del vendedor
     * en elaboración o Lista / Terminada.
     *
     * @param  list<string>  $excludeQuoteIds
     * @return list<array<string, mixed>>
     */
    public function pipelineAlertsForVentas(User $user, array $excludeQuoteIds = []): array
    {
        if ($user->role_slug !== 'ventas') {
            return [];
        }

        $quotes = Quote::query()
            ->with('client')
            ->whereHas('creator.role', fn ($role) => $role->where('slug', 'ventas'))
            ->where(function ($query) use ($user) {
                $query->where('created_by', $user->id)
                    ->orWhere(fn ($byName) => $byName->madeByDisplayName($user));
            })
            ->whereIn('status', ['en_elaboracion', 'pendiente_envio'])
            ->where(function ($q) {
                $q->whereNull('follow_up_status')
                    ->orWhereNotIn('follow_up_status', ['ganada', 'perdida']);
            })
            ->when($excludeQuoteIds !== [], fn ($q) => $q->whereNotIn('id', $excludeQuoteIds))
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get();

        return $quotes->map(function (Quote $quote) use ($user) {
            $reason = $quote->status === 'pendiente_envio'
                ? self::REASON_LISTA
                : self::REASON_EN_ELABORACION;

            return [
                'id' => 'pipeline-'.$quote->id,
                'quoteId' => $quote->id,
                'folio' => $quote->folio,
                'clientName' => $quote->client?->company ?? '',
                'audience' => self::AUDIENCE_VENTAS,
                'reasonCode' => $reason,
                'reasonLabel' => $this->reasonLabel($reason),
                'message' => $this->defaultMessage($quote, $reason),
                'senderName' => 'Sistema',
                'recipientId' => $user->id,
                'recipientName' => $user->name,
                'createdAt' => ($quote->updated_at ?? $quote->created_at)?->toIso8601String(),
                'readAt' => null,
                'read' => false,
                'kind' => 'pipeline',
            ];
        })->values()->all();
    }

    public function markRead(SalesNotification $notification, User $user): SalesNotification
    {
        $notification->loadMissing('quote');
        $role = $user->role_slug;
        $allowed = false;

        if ($notification->audience === self::AUDIENCE_VENTAS) {
            $quote = $notification->quote;
            $quote?->loadMissing('creator');
            $samePerson = Quote::normalizeDisplayName($quote?->creator?->name)
                === Quote::normalizeDisplayName($user->name);
            $sameCreator = (int) ($quote?->created_by ?? 0) === (int) $user->id;
            $allowed = $role === 'administrador'
                || ($role === 'ventas'
                    && (int) $notification->recipient_id === (int) $user->id
                    && ($sameCreator || $samePerson));
        }

        if ($notification->audience === self::AUDIENCE_COMPRAS) {
            $allowed = in_array($role, ['gerente_compras', 'administrador'], true);
        }

        if (! $allowed) {
            throw ValidationException::withMessages([
                'id' => 'No puedes marcar esta notificación.',
            ]);
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return $notification->fresh()->load(['quote.client', 'sender', 'recipient']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(SalesNotification $n): array
    {
        return [
            'id' => $n->id,
            'quoteId' => $n->quote_id,
            'folio' => $n->quote?->folio,
            'clientName' => $n->quote?->client?->company ?? '',
            'audience' => $n->audience,
            'reasonCode' => $n->reason_code,
            'reasonLabel' => $this->reasonLabel($n->reason_code),
            'message' => $n->message,
            'senderName' => $n->sender?->name ?? (
                $n->reason_code === self::REASON_SIN_AVANCE && $n->sender_id === null
                    ? 'Automático'
                    : null
            ),
            'recipientId' => $n->recipient_id,
            'recipientName' => $n->recipient?->name,
            'createdAt' => $n->created_at?->toIso8601String(),
            'readAt' => $n->read_at?->toIso8601String(),
            'read' => $n->read_at !== null,
            'kind' => 'inbox',
        ];
    }

    private function hasUnreadDedupe(string $quoteId, string $reasonCode, string $audience): bool
    {
        return SalesNotification::query()
            ->where('quote_id', $quoteId)
            ->where('reason_code', $reasonCode)
            ->where('audience', $audience)
            ->whereNull('read_at')
            ->exists();
    }

    /**
     * Destinatario ventas: solo el usuario con rol ventas que creó la cotización.
     */
    public function resolveVentasRecipient(Quote $quote): ?User
    {
        $quote->loadMissing('creator.role');

        if ($this->isActiveVentas($quote->creator)) {
            return $quote->creator;
        }

        return null;
    }

    private function isActiveVentas(?User $user): bool
    {
        return $user !== null
            && $user->active
            && $user->role_slug === 'ventas';
    }

    private function elaboracionBelowThresholdReason(int $daysIdle, int $threshold): string
    {
        $thresholdLabel = $threshold === 1 ? '1 día' : "{$threshold} días";

        if ($daysIdle === 0) {
            return "Recién en elaboración. Ventas recibirá un aviso si pasan {$thresholdLabel} sin avance.";
        }

        $idleLabel = $daysIdle === 1 ? '1 día' : "{$daysIdle} días";

        return "Lleva {$idleLabel} sin avance. El aviso a ventas se envía al cumplir {$thresholdLabel}.";
    }
}
