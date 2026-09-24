<?php

namespace App\Services\Sales;

use App\Models\AppSetting;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\SalesNotification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class SalesNotificationService
{
    public const AUDIENCE_VENTAS = 'ventas';

    public const AUDIENCE_COMPRAS = 'compras';

    public const REASON_LISTA = 'lista_terminada';

    public const REASON_EN_ELABORACION = 'en_elaboracion';

    public const REASON_SIN_AVANCE = 'sin_avance';

    public const REASON_SEGUIMIENTO = 'seguimiento_actualizado';

    public const REASON_SOLICITUD_COMPRAS = 'solicitud_recibida';

    public const REASON_SOLICITUD_COMPRAS_URGENTE = 'solicitud_compras_urgente';

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
        $lastActivity = $quote->last_activity_at ?? $quote->updated_at;

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
            self::REASON_SOLICITUD_COMPRAS => 'Nueva solicitud de cotización',
            self::REASON_SOLICITUD_COMPRAS_URGENTE => 'Solicitud urgente sin atender',
            self::REASON_COMENTARIO => 'Comentario de compras',
            default => 'Aviso',
        };
    }

    /**
     * Genera un aviso independiente para cada usuario activo de Compras.
     * La búsqueda previa evita duplicados si el flujo se reintenta.
     */
    public function notifyComprasNewRequest(Quote $quote, ?User $sender = null): int
    {
        $recipients = User::query()
            ->where('active', true)
            ->whereHas('role', fn ($role) => $role->where('slug', 'gerente_compras'))
            ->get();

        $created = 0;
        foreach ($recipients as $recipient) {
            $notification = SalesNotification::query()->firstOrCreate(
                [
                    'quote_id' => $quote->id,
                    'recipient_id' => $recipient->id,
                    'audience' => self::AUDIENCE_COMPRAS,
                    'reason_code' => self::REASON_SOLICITUD_COMPRAS,
                ],
                [
                    'sender_id' => $sender?->id,
                    'message' => "Nueva solicitud de cotización {$quote->folio} disponible para Compras.",
                    'read_at' => null,
                ],
            );

            if ($notification->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Recupera avisos faltantes para cotizaciones que siguen esperando a Compras.
     * Es idempotente: notifyComprasNewRequest evita duplicar un aviso por destinatario.
     *
     * @return array{created: int, reviewed: int}
     */
    public function syncComprasRequestNotifications(int $limit = 500): array
    {
        $recipients = User::query()
            ->where('active', true)
            ->whereHas('role', fn ($role) => $role->where('slug', 'gerente_compras'))
            ->get();

        $created = 0;
        $reviewed = 0;
        $perRecipientLimit = max(1, min($limit, 2000));

        foreach ($recipients as $recipient) {
            $quotes = Quote::query()
                ->with('creator')
                ->where('status', 'solicitud_cotizaciones')
                ->whereDoesntHave('salesNotifications', function ($notification) use ($recipient) {
                    $notification->where('recipient_id', $recipient->id)
                        ->where('audience', self::AUDIENCE_COMPRAS)
                        ->where('reason_code', self::REASON_SOLICITUD_COMPRAS);
                })
                ->orderBy('created_at')
                ->limit($perRecipientLimit)
                ->get();

            foreach ($quotes as $quote) {
                $notification = SalesNotification::query()->firstOrCreate(
                    [
                        'quote_id' => $quote->id,
                        'recipient_id' => $recipient->id,
                        'audience' => self::AUDIENCE_COMPRAS,
                        'reason_code' => self::REASON_SOLICITUD_COMPRAS,
                    ],
                    [
                        'sender_id' => $quote->creator?->id,
                        'message' => "Nueva solicitud de cotización {$quote->folio} disponible para Compras.",
                        'read_at' => null,
                    ],
                );
                $reviewed++;
                if ($notification->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        return [
            'created' => $created,
            'reviewed' => $reviewed,
        ];
    }

    /**
     * Escala a Administración solicitudes que nadie de Compras ha tomado.
     *
     * @return array{created: int, escalated: int}
     */
    public function escalateUnclaimedPurchaseRequests(int $limit = 200): array
    {
        $threshold = AppSetting::current()->resolvedUnansweredQuoteDays();
        $cutoff = now()->subDays($threshold);
        $admins = User::query()
            ->where('active', true)
            ->whereHas('role', fn ($role) => $role->where('slug', 'administrador'))
            ->get();

        if ($admins->isEmpty()) {
            return ['created' => 0, 'escalated' => 0];
        }

        $quotes = Quote::query()
            ->where('status', 'solicitud_cotizaciones')
            ->whereNull('purchase_assigned_to')
            ->whereNull('purchase_escalated_at')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit(max(1, min($limit, 1000)))
            ->get();

        $created = 0;
        foreach ($quotes as $quote) {
            foreach ($admins as $admin) {
                $notification = SalesNotification::query()->firstOrCreate(
                    [
                        'quote_id' => $quote->id,
                        'recipient_id' => $admin->id,
                        'audience' => self::AUDIENCE_COMPRAS,
                        'reason_code' => self::REASON_SOLICITUD_COMPRAS_URGENTE,
                    ],
                    [
                        'sender_id' => null,
                        'message' => "Urgente: {$quote->folio} lleva {$threshold} días sin que Compras la tome.",
                        'read_at' => null,
                    ],
                );
                if ($notification->wasRecentlyCreated) {
                    $created++;
                }
            }

            $quote->forceFill(['purchase_escalated_at' => now()])->saveQuietly();
        }

        return ['created' => $created, 'escalated' => $quotes->count()];
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
            ->where(function ($q) use ($cutoff) {
                $q->where('last_activity_at', '<', $cutoff)
                    ->orWhere(function ($legacy) use ($cutoff) {
                        $legacy->whereNull('last_activity_at')->where('updated_at', '<', $cutoff);
                    });
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

            $reminder = SalesNotification::query()
                ->where('quote_id', $quote->id)
                ->where('audience', self::AUDIENCE_VENTAS)
                ->whereNotNull('sender_id')
                ->where('updated_at', '<=', $cutoff)
                ->orderByDesc('updated_at')
                ->first();

            $lastActivity = $quote->last_activity_at ?? $quote->updated_at;
            if ($reminder === null || ($lastActivity && $lastActivity->gt($reminder->updated_at))) {
                $skipped++;

                continue;
            }

            $recipients = $quote->follow_up_assigned_to !== null
                ? User::query()->whereKey($quote->follow_up_assigned_to)->where('active', true)->get()
                : User::query()
                    ->where('active', true)
                    ->whereHas('role', fn ($role) => $role->where('slug', 'ventas'))
                    ->get();

            if ($recipients->isEmpty()) {
                $skipped++;

                continue;
            }

            foreach ($recipients as $recipient) {
                $exists = SalesNotification::query()
                    ->where('quote_id', $quote->id)
                    ->where('recipient_id', $recipient->id)
                    ->where('reason_code', self::REASON_SIN_AVANCE)
                    ->whereNull('read_at')
                    ->exists();

                if ($exists) {
                    continue;
                }

                try {
                    SalesNotification::query()->create([
                        'quote_id' => $quote->id,
                        'sender_id' => null,
                        'recipient_id' => $recipient->id,
                        'audience' => self::AUDIENCE_VENTAS,
                        'reason_code' => self::REASON_SIN_AVANCE,
                        'message' => "Disponible para tomar: {$quote->folio} lleva {$threshold} días sin avance después del recordatorio de Compras.",
                        'read_at' => null,
                    ]);
                } catch (QueryException $e) {
                    // Otra ejecución pudo ganar la carrera entre exists() y create().
                    if ($e->getCode() !== '23505') {
                        throw $e;
                    }

                    continue;
                }

                $created++;
                $notifiedQuoteIds[] = $quote->id;
            }
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
            ->where('recipient_id', $recipient->id)
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
        // Recupera avisos que pudieron faltar por solicitudes creadas antes de
        // habilitar las notificaciones, sin alterar los ya leídos.
        if (in_array($user->role_slug, ['gerente_compras', 'administrador'], true)) {
            $this->syncComprasRequestNotifications();
        }

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
                        ->where(function ($responsible) use ($user) {
                            $responsible->whereNull('follow_up_assigned_to')
                                ->orWhere('follow_up_assigned_to', $user->id);
                        });
                });
        } elseif (in_array($role, ['gerente_compras', 'administrador'], true)) {
            $query->where('audience', self::AUDIENCE_COMPRAS)
                ->where(function ($recipient) use ($user) {
                    $recipient->whereNull('recipient_id')
                        ->orWhere('recipient_id', $user->id);
                });
        } else {
            return ['data' => [], 'unreadCount' => 0];
        }

        $items = $query->get();
        $data = $items->map(fn (SalesNotification $n) => $this->toApiArray($n))->values()->all();
        $unreadCount = $items->whereNull('read_at')->count();

        if ($role === 'ventas') {
            $alreadyNotified = $items->pluck('quote_id')->filter()->all();
            $pipelineQuotes = $this->pipelineAlertsForVentas($user, $alreadyNotified);
            $pipelineRequests = $this->pipelineRequestAlertsForVentas($user);
            $pipeline = array_values(array_merge($pipelineRequests, $pipelineQuotes));
            $data = array_values(array_merge($pipeline, $data));
            $unreadCount += count($pipeline);
        }

        return [
            'data' => $data,
            'unreadCount' => $unreadCount,
        ];
    }

    /**
     * Bandeja compartida: cotizaciones de ventas sin responsable, o asignadas
     * al vendedor actual, que requieren seguimiento.
     *
     * @param  list<string>  $excludeQuoteIds
     * @return list<array<string, mixed>>
     */
    public function pipelineAlertsForVentas(User $user, array $excludeQuoteIds = []): array
    {
        if ($user->role_slug !== 'ventas') {
            return [];
        }

        $threshold = AppSetting::current()->resolvedUnansweredQuoteDays();
        $cutoff = now()->subDays($threshold);

        $quotes = Quote::query()
            ->with(['client', 'followUpAssignee'])
            ->whereHas('creator.role', fn ($role) => $role->where('slug', 'ventas'))
            ->where(function ($assignment) use ($user) {
                $assignment->whereNull('follow_up_assigned_to')
                    ->orWhere('follow_up_assigned_to', $user->id);
            })
            ->where(function ($status) use ($cutoff) {
                $status->where(function ($ready) {
                    $ready->where('status', 'pendiente_envio')->whereNull('sent_at');
                })->orWhere(function ($idle) use ($cutoff) {
                    $idle->where('status', 'en_elaboracion')
                        ->whereHas('salesNotifications', function ($reminder) use ($cutoff) {
                            $reminder->where('audience', self::AUDIENCE_VENTAS)
                                ->whereNotNull('sender_id')
                                ->where('updated_at', '<=', $cutoff);
                        })
                        ->where(function ($activity) use ($cutoff) {
                            $activity->where('last_activity_at', '<=', $cutoff)
                                ->orWhere(function ($legacy) use ($cutoff) {
                                    $legacy->whereNull('last_activity_at')->where('updated_at', '<=', $cutoff);
                                });
                        });
                });
            })
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
                'requestId' => null,
                'folio' => $quote->folio,
                'clientName' => $quote->client?->company ?? '',
                'audience' => self::AUDIENCE_VENTAS,
                'reasonCode' => $reason,
                'reasonLabel' => $this->reasonLabel($reason),
                'message' => $this->defaultMessage($quote, $reason),
                'senderName' => 'Sistema',
                'recipientId' => $user->id,
                'recipientName' => $user->name,
                'assigneeName' => $quote->followUpAssignee?->name,
                'createdAt' => ($quote->updated_at ?? $quote->created_at)?->toIso8601String(),
                'readAt' => null,
                'read' => false,
                'kind' => 'pipeline',
            ];
        })->values()->all();
    }

    /**
     * Solicitudes propias del vendedor que aún no tienen cotización vinculada.
     *
     * @return list<array<string, mixed>>
     */
    public function pipelineRequestAlertsForVentas(User $user): array
    {
        if ($user->role_slug !== 'ventas') {
            return [];
        }

        $requests = QuoteRequest::query()
            ->with('client')
            ->ownedByUser($user)
            ->whereDoesntHave('quotes')
            ->whereNotIn('status', ['procesando', 'error'])
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get();

        return $requests->map(function (QuoteRequest $request) use ($user) {
            $label = $request->file_name
                ?: ($request->folio ?: 'Solicitud');

            return [
                'id' => 'pipeline-request-'.$request->id,
                'quoteId' => null,
                'requestId' => $request->id,
                'folio' => $label,
                'clientName' => $request->client?->company ?? '',
                'audience' => self::AUDIENCE_VENTAS,
                'reasonCode' => self::REASON_EN_ELABORACION,
                'reasonLabel' => $this->reasonLabel(self::REASON_EN_ELABORACION),
                'message' => 'Solicitud pendiente de cotización en Compras.',
                'senderName' => 'Sistema',
                'recipientId' => $user->id,
                'recipientName' => $user->name,
                'createdAt' => ($request->updated_at ?? $request->created_at)?->toIso8601String(),
                'readAt' => null,
                'read' => false,
                'kind' => 'pipeline_request',
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
            $allowed = in_array($role, ['gerente_compras', 'administrador'], true)
                && ($notification->recipient_id === null
                    || (int) $notification->recipient_id === (int) $user->id);
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
     * Destinatario ventas: responsable actual; si aún no existe, quien creó la cotización.
     */
    public function resolveVentasRecipient(Quote $quote): ?User
    {
        $quote->loadMissing(['creator.role', 'followUpAssignee.role']);

        if ($this->isActiveVentas($quote->followUpAssignee)) {
            return $quote->followUpAssignee;
        }

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
