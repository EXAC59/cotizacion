<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use App\Models\SalesNotification;
use App\Models\User;
use App\Services\Sales\SalesNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuoteMessagingService
{
    public function __construct(private readonly SalesNotificationService $notifications) {}

    public function sales(Quote $quote): ?User
    {
        return $this->notifications->resolveVentasRecipient($quote);
    }

    public function purchasing(Quote $quote): ?User
    {
        if ($quote->purchase_assigned_to) return $quote->purchaseAssignee;
        // Completed quotes release the purchase assignment; retain their chat participant.
        if ($quote->status === 'solicitud_cotizaciones') return null;
        $id = DB::table('quote_conversations')->where('quote_id', $quote->id)->value('purchasing_user_id');
        if ($id) return User::find($id);

        // Quotations predating messaging retain their buyer in the activity history.
        $activePurchaser = fn ($user) => $user->where('active', true)
            ->whereHas('role', fn ($role) => $role->whereIn('slug', ['gerente_compras', 'administrador']));
        $event = $quote->statusEvents()->whereHas('user', $activePurchaser)->reorder('created_at', 'desc')->orderByDesc('id')->first();
        if ($event) return $event->user;
        $notice = $quote->salesNotifications()->where('audience', 'ventas')->whereHas('sender', $activePurchaser)->orderByDesc('id')->first();
        if ($notice) return $notice->sender;
        return $quote->internalNotes()->whereHas('user', $activePurchaser)->orderByDesc('id')->first()?->user;
    }

    public function assertAccess(Quote $quote, User $actor, bool $allowPending = false): void
    {
        abort_unless($actor->active, 403);
        if ($actor->isAdministrator()) {
            return;
        }
        if ($actor->role_slug === 'ventas') {
            abort_unless((int) $this->sales($quote)?->id === (int) $actor->id, 403, 'El chat pertenece al vendedor responsable de esta cotización.');

            return;
        }
        abort_unless($actor->role_slug === 'gerente_compras', 403);
        if ($allowPending && $quote->purchase_assigned_to === null && $quote->status === 'solicitud_cotizaciones') {
            return;
        }
        abort_unless((int) $this->purchasing($quote)?->id === (int) $actor->id, 403, 'El chat pertenece al comprador responsable.');
    }

    /** Conversations are shared; commercial actions still require the responsible user. */
    public function assertConversationAccess(Quote $quote, User $actor): void
    {
        abort_unless($actor->active, 403);
        if (in_array($actor->role_slug, ['ventas', 'gerente_compras', 'administrador'], true)) return;
        $this->assertAccess($quote, $actor);
    }

    public function isMessageRecipient(Quote $quote, User $actor): bool
    {
        return $quote->salesNotifications()->whereIn('reason_code', ['mensaje_chat', 'cierre_cotizacion'])
            ->where('recipient_id', $actor->id)->exists();
    }

    public function notifyClosing(Quote $quote, User $actor, string $body): void
    {
        $this->notify($quote, $actor, $body, 'cierre_cotizacion');
    }

    public function notify(Quote $quote, User $actor, string $body, string $reason = 'mensaje_chat'): void
    {
        $participants = DB::table('quote_chat_messages')->where('quote_id', $quote->id)->whereNotNull('sender_id')->pluck('sender_id')
            ->merge(DB::table('quote_chat_reads')->where('quote_id', $quote->id)->pluck('user_id'))->unique();
        // Presence is irrelevant: these notices persist for recipients who are offline.
        $recipients = User::query()->where('active', true)->where('id', '<>', $actor->id)
            ->where(fn ($users) => $users->whereHas('role', fn ($role) => $role->whereIn('slug', ['ventas', 'gerente_compras']))
                ->orWhereIn('id', $participants))->get();
        foreach ($recipients as $recipient) {
            $notice = SalesNotification::query()->updateOrCreate([
                'quote_id' => $quote->id, 'recipient_id' => $recipient->id, 'reason_code' => $reason, 'read_at' => null,
            ], ['sender_id' => $actor->id, 'audience' => $recipient->role_slug === 'ventas' ? 'ventas' : 'compras',
                'message' => mb_substr($body, 0, 1000)]);
            if ($reason === 'cierre_cotizacion') $notice->forceFill(['created_at' => now()])->save();
        }
    }

    public function initialize(Quote $quote, ?User $actor = null): void
    {
        $purchaser = $this->purchasing($quote);
        $inserted = DB::table('quote_conversations')->insertOrIgnore([
            'quote_id' => $quote->id, 'purchasing_user_id' => $purchaser?->id,
            'stage' => $this->stage($quote, null),
            'checks' => json_encode(['stock' => false, 'price' => false, 'delivery' => false]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted && $actor && $purchaser && $this->sales($quote)) {
            $this->event($quote, $actor, 'Se abrió el chat de seguimiento. Compras responsable: '.$purchaser->name.'.');
        }
        if ($inserted && $actor && trim((string) $quote->customer_observations) !== '') {
            $this->event($quote, $actor, 'Requerimiento: '.$quote->customer_observations);
        }
    }

    public function conversationQuotes(User $actor): Builder
    {
        abort_unless($actor->active && in_array($actor->role_slug, ['ventas', 'gerente_compras', 'administrador'], true), 403);
        return Quote::query();
    }

    /** Called within the existing purchase claim transaction. */
    public function open(Quote $quote, User $actor): void
    {
        $this->assertAccess($quote, $actor);
        $purchaser = $this->purchasing($quote);
        if ($purchaser === null) {
            return;
        }
        $conversation = DB::table('quote_conversations')->where('quote_id', $quote->id)->first();
        if ($conversation) {
            DB::table('quote_conversations')->where('quote_id', $quote->id)->update(['purchasing_user_id' => $purchaser->id]);
            if ((int) $conversation->purchasing_user_id !== (int) $purchaser->id) {
                $this->event($quote, $actor, 'Compras responsable: '.$purchaser->name.'.');
                $this->notify($quote, $actor, 'Compras tomó la solicitud. El chat de seguimiento está disponible.');
            }
            return;
        }
        DB::table('quote_conversations')->insert([
            'quote_id' => $quote->id, 'purchasing_user_id' => $purchaser->id, 'stage' => $this->stage($quote, null),
            'checks' => json_encode(['stock' => false, 'price' => false, 'delivery' => false]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->event($quote, $actor, 'Se abrió el chat de seguimiento. Compras responsable: '.$purchaser->name.'.');
        if (trim((string) $quote->customer_observations) !== '') {
            $this->event($quote, $actor, 'Requerimiento: '.$quote->customer_observations);
        }
        $this->notify($quote, $actor, 'Compras tomó la solicitud. El chat de seguimiento está disponible.');
    }

    public function event(Quote $quote, User $actor, string $body): int
    {
        return (int) DB::table('quote_chat_messages')->insertGetId([
            'quote_id' => $quote->id, 'sender_id' => $actor->id, 'kind' => 'system',
            'body' => $body, 'created_at' => now(),
        ]);
    }

    public function stage(Quote $quote, ?object $conversation): string
    {
        if ($conversation?->stage === 'cerrada') return 'cerrada';
        if ($quote->sent_at || in_array($quote->status, ['enviada', 'aceptada', 'facturada'], true)) return 'enviada';
        if ($conversation?->stage === 'espera_ventas') return 'espera_ventas';
        if ($conversation?->stage === 'en_proceso') return 'en_proceso';
        if ($quote->status === 'pendiente_envio') return 'lista';

        return $this->purchasing($quote) ? 'en_proceso' : 'por_tomar';
    }

    public function participantQuotes(User $actor): Builder
    {
        abort_unless($actor->active && in_array($actor->role_slug, ['ventas', 'gerente_compras', 'administrador'], true), 403);
        $quotes = Quote::query();
        if ($actor->role_slug === 'ventas') {
            // Use the same active salesperson fallback as resolveVentasRecipient.
            $quotes->where(function ($query) use ($actor) {
                $query->where('follow_up_assigned_to', $actor->id)->orWhere(function ($fallback) use ($actor) {
                    $fallback->where('created_by', $actor->id)->whereDoesntHave('followUpAssignee', function ($assignee) {
                        $assignee->where('active', true)->whereHas('role', fn ($role) => $role->where('slug', 'ventas'));
                    });
                })->orWhere(function ($legacy) use ($actor) {
                    $activeSales = fn ($user) => $user->where('active', true)
                        ->whereHas('role', fn ($role) => $role->where('slug', 'ventas'));
                    $legacy->whereDoesntHave('creator', $activeSales)->whereDoesntHave('followUpAssignee', $activeSales)
                        ->where(function ($recipient) {
                            $recipient->select('sales_notifications.recipient_id')->from('sales_notifications')
                                ->join('users', 'users.id', '=', 'sales_notifications.recipient_id')
                                ->join('roles', 'roles.id', '=', 'users.role_id')
                                ->whereColumn('sales_notifications.quote_id', 'quotes.id')->where('sales_notifications.audience', 'ventas')
                                ->whereNotIn('sales_notifications.reason_code', ['mensaje_chat', 'cierre_cotizacion'])
                                ->whereNotNull('sales_notifications.sender_id')->where('users.active', true)->where('roles.slug', 'ventas')
                                ->orderByDesc('sales_notifications.created_at')->orderByDesc('sales_notifications.id')->limit(1);
                        }, $actor->id);
                })->orWhereHas('salesNotifications', fn ($notice) => $notice->whereIn('reason_code', ['mensaje_chat', 'cierre_cotizacion'])->where('recipient_id', $actor->id));
            });
        } elseif ($actor->role_slug === 'gerente_compras') {
            $quotes->where(function ($query) use ($actor) {
                $query->where('purchase_assigned_to', $actor->id)->orWhere('created_by', $actor->id)
                    ->orWhereHas('salesNotifications', fn ($notice) => $notice->where('reason_code', 'cierre_cotizacion')->where('recipient_id', $actor->id))
                    ->orWhereIn('quotes.id', DB::table('quote_chat_reads')->select('quote_id')->where('user_id', $actor->id))
                    ->orWhere(function ($completed) use ($actor) {
                    $completed->whereNull('purchase_assigned_to')->where('status', '<>', 'solicitud_cotizaciones')
                        ->whereIn('quotes.id', DB::table('quote_conversations')->select('quote_id')->where('purchasing_user_id', $actor->id));
                });
            });
        }

        return $quotes;
    }

    public function unreadCount(User $actor): int
    {
        return app(\App\Services\Sales\NotificationInboxService::class)->forUser($actor)['pendingCount'];
    }

    /** Unread chat messages grouped by quotation, without bell notices or operational tasks. */
    public function unreadMessageCounts(User $actor): array
    {
        return DB::table('quote_chat_messages as messages')
            ->join('quote_conversations as conversations', 'conversations.quote_id', '=', 'messages.quote_id')
            ->leftJoin('quote_chat_reads as reads', function ($join) use ($actor) {
                $join->on('reads.quote_id', '=', 'messages.quote_id')->where('reads.user_id', '=', $actor->id);
            })
            ->whereIn('messages.quote_id', $this->conversationQuotes($actor)->select('quotes.id'))
            ->whereRaw('messages.id > COALESCE(reads.last_message_id, 0)')
            ->where(fn ($query) => $query->where('messages.sender_id', '<>', $actor->id)->orWhereNull('messages.sender_id'))
            ->select('messages.quote_id')->selectRaw('COUNT(*) as unread_total')->groupBy('messages.quote_id')
            ->pluck('unread_total', 'messages.quote_id')->map(fn ($count) => (int) $count)->all();
    }

    public function summary(Quote $quote, User $actor): array
    {
        $conversation = DB::table('quote_conversations')->where('quote_id', $quote->id)->first();
        $lastRead = (int) DB::table('quote_chat_reads')->where('quote_id', $quote->id)->where('user_id', $actor->id)->value('last_message_id');
        $sales = $this->sales($quote);
        $purchasing = $this->purchasing($quote);
        $canChat = $actor->active && in_array($actor->role_slug, ['ventas', 'gerente_compras', 'administrador'], true);
        $canManageWorkflow = $actor->isAdministrator() || ($actor->role_slug === 'ventas'
            ? (int) $sales?->id === (int) $actor->id : (int) $purchasing?->id === (int) $actor->id);
        $last = $canChat ? DB::table('quote_chat_messages')->where('quote_id', $quote->id)->orderByDesc('id')->first() : null;
        $unread = $canChat ? DB::table('quote_chat_messages')->where('quote_id', $quote->id)->where('id', '>', $lastRead)
            ->where(fn ($query) => $query->where('sender_id', '<>', $actor->id)->orWhereNull('sender_id'))->count() : 0;
        $notifications = $this->notifications->visibleForUser($actor)->where('quote_id', $quote->id)->where('reason_code', '<>', 'mensaje_chat');
        $notificationCount = (clone $notifications)->whereNull('read_at')->count();
        $lastNotification = (clone $notifications)->orderByDesc('created_at')->first();
        return [
            'id' => $quote->id, 'folio' => $quote->folio, 'client' => $quote->client?->company ?? 'Sin cliente',
            'sales' => $sales?->name ?? 'Ventas sin asignar', 'purchasing' => $purchasing?->name ?? 'Compras por asignar',
            'status' => $quote->status, 'total' => (float) $quote->total, 'createdAt' => $quote->created_at?->toIso8601String(),
            'section' => 'history', 'alertType' => null, 'alertDetail' => null,
            'daysIdle' => $this->notifications->daysIdle($quote),
            'stage' => $this->stage($quote, $conversation), 'hasChat' => $conversation !== null,
            'nextAction' => in_array($quote->follow_up_status, ['ganada', 'perdida'], true) ? 'Seguimiento finalizado.' : match ($this->stage($quote, $conversation)) {
                'por_tomar' => 'Compras debe tomar la solicitud.',
                'en_proceso' => 'Compras debe revisar productos, precios y entrega.',
                'espera_ventas' => 'Ventas debe responder la consulta de Compras.',
                'lista' => 'Ventas debe revisar y enviar la cotización al cliente.',
                'enviada' => 'Ventas debe dar seguimiento a la respuesta del cliente.',
                'cerrada' => 'Seguimiento cerrado. El historial está disponible.',
                default => 'Revisa el estado de la cotización con el equipo.',
            },
            'canTake' => in_array($actor->role_slug, ['gerente_compras', 'administrador'], true) && $quote->status === 'solicitud_cotizaciones' && $quote->purchase_assigned_to === null && $sales !== null,
            'canChat' => $canChat,
            'canView' => $canChat, 'canManageWorkflow' => $canManageWorkflow,
            'checks' => $conversation?->checks ? json_decode($conversation->checks, true) : ['stock' => false, 'price' => false, 'delivery' => false],
            'reminder' => $quote->follow_up_status === 'negociacion' && $quote->status === 'pendiente_envio' && $quote->sent_at === null
                ? $quote->follow_up_remind_at?->toIso8601String() : null, 'unreadCount' => $unread,
            'hasNotifications' => (clone $notifications)->exists(), 'notificationCount' => $notificationCount,
            'lastNotification' => $lastNotification?->message, 'lastNotificationAt' => $lastNotification?->created_at?->toIso8601String(),
            'lastMessage' => $last?->body ?? 'Chat de seguimiento de la cotización.',
            'lastMessageAt' => $last?->created_at,
            'products' => $quote->lines->map(fn ($line) => ['description' => $line->product, 'quantity' => (float) $line->quantity])->all(),
        ];
    }

    public function send(Quote $quote, User $actor, array $data): int
    {
        $this->assertConversationAccess($quote, $actor);
        $this->initialize($quote, $actor);
        $conversation = DB::table('quote_conversations')->where('quote_id', $quote->id)->first();
        abort_unless($conversation, 409, 'Compras debe tomar la solicitud antes de abrir el chat.');
        $existing = DB::table('quote_chat_messages')->where('quote_id', $quote->id)->where('sender_id', $actor->id)->where('client_id', $data['clientId'])->first();
        if ($existing) {
            abort_unless($existing->body === trim($data['text']), 409, 'Este identificador ya corresponde a otro mensaje.');
            return (int) $existing->id;
        }
        abort_if($conversation->stage === 'cerrada', 409, 'Este seguimiento está cerrado.');
        $body = trim($data['text']);
        if ($body === '') throw ValidationException::withMessages(['text' => 'Escribe un mensaje.']);
        $id = (int) DB::table('quote_chat_messages')->insertGetId([
            'quote_id' => $quote->id, 'sender_id' => $actor->id, 'client_id' => $data['clientId'],
            'kind' => 'text', 'body' => $body, 'created_at' => now(),
        ]);
        $this->notify($quote, $actor, "Mensaje en {$quote->folio}: ".$body);

        return $id;
    }
}
