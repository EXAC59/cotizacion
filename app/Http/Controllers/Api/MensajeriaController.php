<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\SalesNotification;
use App\Services\Quotes\PurchaseRequestWorkflowService;
use App\Services\Quotes\QuoteMessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MensajeriaController extends Controller
{
    public function __construct(private readonly QuoteMessagingService $chat) {}

    public function pending(Request $request): JsonResponse
    {
        $inbox = app(\App\Services\Sales\NotificationInboxService::class)->forUser($request->user());
        return response()->json(['unreadCount' => $inbox['pendingCount'], 'pendingCount' => $inbox['pendingCount'],
            'unreadMessageCount' => $inbox['unreadMessageCount']]);
    }

    public function index(Request $request, \App\Services\Sales\NotificationInboxService $inbox): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1'],
            'mode' => ['nullable', 'in:conversations'],
            'section' => ['nullable', 'in:all,new_requests,own_requests,own_quotes,ready_to_send,quote_notices,pending,history'],
            'filter' => ['nullable', 'in:all,unread,reminders,mine']]);
        $user = $request->user();
        abort_unless($user->active && in_array($user->role_slug, ['ventas', 'gerente_compras', 'administrador'], true), 403);
        if (($data['mode'] ?? null) === 'conversations') return $this->conversations($request, $data);
        $groups = $inbox->messagingGroupsForUser($user, $this->chat);
        $sections = array_map(fn ($items) => $user->role_slug === 'ventas'
            ? collect($items)->pluck('quoteId')->unique()->count() : count($items), $groups);
        $entries = [];
        foreach ($groups as $group => $notices) {
            foreach ($notices as $notice) {
                $id = $notice['quoteId'] ?? $notice['requestId'];
                if (! $id) continue;
                $entries[$id] ??= ['id' => $id, 'requestId' => $notice['requestId'] ?? null,
                    'folio' => $notice['folio'], 'client' => $notice['clientName'], 'sections' => [], 'notices' => []];
                if (! in_array($group, $entries[$id]['sections'], true)) $entries[$id]['sections'][] = $group;
                $entries[$id]['notices'][] = [...$notice, 'group' => $group];
            }
        }
        $quoteIds = collect($entries)->whereNull('requestId')->pluck('id')->all();
        $unreadIds = DB::table('quote_chat_messages as messages')
            ->leftJoin('quote_chat_reads as reads', function ($join) use ($user) {
                $join->on('reads.quote_id', '=', 'messages.quote_id')->where('reads.user_id', '=', $user->id);
            })->whereIn('messages.quote_id', $this->chat->participantQuotes($user)->select('quotes.id'))
            ->whereRaw('messages.id > COALESCE(reads.last_message_id, 0)')
            ->where(fn ($sender) => $sender->where('messages.sender_id', '<>', $user->id)->orWhereNull('messages.sender_id'))
            ->distinct()->pluck('messages.quote_id')->all();
        $historyQuery = $this->chat->participantQuotes($user)->whereNotIn('quotes.id', $quoteIds)
            ->whereIn('quotes.id', DB::table('quote_conversations')->select('quote_id'));
        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $historyQuery->where(fn ($q) => $q->where('folio', 'like', $term)->orWhereHas('client', fn ($client) => $client->where('company', 'like', $term)));
        }
        $sections['history'] = (clone $historyQuery)->count();
        $section = $data['section'] ?? 'all';
        if ($section === 'history') {
            $entries = (clone $historyQuery)->orderByRaw('COALESCE(last_activity_at, updated_at) ASC')->orderBy('id')->pluck('quotes.id')
                ->map(fn ($id) => ['id' => $id, 'requestId' => null, 'sections' => ['history'], 'notices' => []]);
        } else {
            $entries = collect($entries)->filter(fn ($entry) => ($section === 'all' || in_array($section, $entry['sections'], true))
                && ($search === '' || mb_stripos($entry['folio'].' '.$entry['client'], $search) !== false))
                ->map(function ($entry) use ($section) {
                    if ($section !== 'all') $entry['notices'] = array_values(array_filter($entry['notices'], fn ($notice) => $notice['group'] === $section));
                    return $entry;
                });
        }
        $isUnread = fn ($entry) => in_array($entry['id'], $unreadIds, true) || collect($entry['notices'])->contains(fn ($notice) => ! $notice['read']);
        // A scheduled commercial follow-up stays visible even without an unread bell notice.
        $scheduledReminders = Quote::query()->whereIn('id', $entries->whereNull('requestId')->pluck('id'))
            ->where('follow_up_status', 'negociacion')->whereNotNull('follow_up_remind_at')
            ->where('status', 'pendiente_envio')->whereNull('sent_at')->pluck('id')->flip()->all();
        $hasReminder = fn ($entry) => isset($scheduledReminders[$entry['id']])
            || collect($entry['notices'])->contains(fn ($notice) => $notice['reminder']);
        $unreadQuoteCount = $entries->filter($isUnread)->count();
        $reminderCount = $entries->filter($hasReminder)->count();
        if (($data['filter'] ?? 'all') === 'unread') $entries = $entries->filter($isUnread);
        if (($data['filter'] ?? 'all') === 'reminders') $entries = $entries->filter($hasReminder);
        $page = new \Illuminate\Pagination\LengthAwarePaginator($entries->values()->forPage($data['page'] ?? 1, 30)->values(),
            $entries->count(), 30, $data['page'] ?? 1);
        $quotes = Quote::query()->with(['client', 'creator.role', 'followUpAssignee.role', 'purchaseAssignee', 'lines'])
            ->whereIn('id', $page->getCollection()->whereNull('requestId')->pluck('id'))->get()->keyBy('id');
        $rows = $page->getCollection()->map(function ($entry) use ($user, $quotes, $section) {
            $entry['notices'] = array_values(array_filter($entry['notices'], fn ($notice) => ! ($notice['isQuote'] ?? false)));
            $notice = $entry['notices'][0] ?? null;
            if ($entry['requestId']) {
                // A request without a quotation opens its existing request screen, never a fabricated chat.
                $summary = ['id' => $entry['id'], 'folio' => $entry['folio'], 'client' => $entry['client'],
                    'sales' => $user->name, 'purchasing' => 'Compras', 'status' => 'solicitud_cotizaciones', 'total' => 0,
                    'createdAt' => $notice['createdAt'], 'daysIdle' => 0, 'stage' => 'por_tomar', 'hasChat' => false,
                    'canChat' => false, 'canTake' => false, 'checks' => ['stock' => false, 'price' => false, 'delivery' => false],
                    'reminder' => null, 'unreadCount' => 0, 'lastMessage' => $notice['detail'], 'lastMessageAt' => null, 'products' => []];
            } else {
                if (! isset($quotes[$entry['id']])) return null;
                $summary = $this->chat->summary($quotes[$entry['id']], $user);
                if (in_array('new_requests', $entry['sections'], true)) $summary['folio'] = $entry['folio'];
            }
            return [...$summary, 'requestId' => $entry['requestId'], 'sections' => $entry['sections'], 'notices' => $entry['notices'],
                'section' => $section === 'all' ? $entry['sections'][0] : $section,
                'alertType' => $notice ? ($notice['reminder'] ? 'reminder' : 'notification') : null,
                'alertDetail' => $notice ? $notice['label'].' · '.$notice['detail'] : null,
                'hasNotifications' => $notice !== null,
                'notificationCount' => collect($entry['notices'])->where('read', false)->reject(fn ($notice) => $notice['messageNotice'] ?? false)->count(),
                'lastNotification' => $notice['detail'] ?? null, 'lastNotificationAt' => $notice['createdAt'] ?? null];
        })->filter()->values();
        return response()->json(['data' => $rows, 'page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total(),
            'sections' => $sections, 'unreadQuoteCount' => $unreadQuoteCount, 'reminderCount' => $reminderCount,
            'unreadCount' => $this->chat->unreadCount($user)]);
    }

    private function conversations(Request $request, array $data): JsonResponse
    {
        $user = $request->user();
        $query = $this->chat->conversationQuotes($user)->with(['client', 'creator.role', 'followUpAssignee.role', 'purchaseAssignee', 'lines']);
        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($quotes) => $quotes->where('folio', 'like', $term)
                ->orWhereHas('client', fn ($client) => $client->where('company', 'like', $term)));
        }
        $unreadIds = DB::table('quote_chat_messages as messages')->leftJoin('quote_chat_reads as reads', function ($join) use ($user) {
            $join->on('reads.quote_id', '=', 'messages.quote_id')->where('reads.user_id', '=', $user->id);
        })->whereIn('messages.quote_id', $this->chat->conversationQuotes($user)->select('quotes.id'))
            ->whereRaw('messages.id > COALESCE(reads.last_message_id, 0)')
            ->where(fn ($sender) => $sender->where('messages.sender_id', '<>', $user->id)->orWhereNull('messages.sender_id'))
            ->distinct()->pluck('messages.quote_id');
        $notifications = app(\App\Services\Sales\SalesNotificationService::class)->visibleForUser($user);
        $unreadIds = $unreadIds->merge((clone $notifications)->whereNull('read_at')->pluck('quote_id'))->unique();
        $total = (clone $query)->count();
        $unreadCount = (clone $query)->whereIn('quotes.id', $unreadIds)->count();
        $reminderIds = (clone $notifications)->whereNull('read_at')->whereIn('reason_code',
            ['sin_avance', 'en_elaboracion', 'seguimiento_actualizado', 'recordatorio_vencido', 'solicitud_compras_urgente'])->pluck('quote_id');
        $reminders = fn ($quotes) => $quotes->where(fn ($due) => $due->where(fn ($scheduled) => $scheduled
            ->where('follow_up_status', 'negociacion')->whereNotNull('follow_up_remind_at')
            ->where('status', 'pendiente_envio')->whereNull('sent_at'))->orWhereIn('quotes.id', $reminderIds));
        $reminderCount = $reminders(clone $query)->count();
        if (($data['filter'] ?? 'all') === 'unread') $query->whereIn('quotes.id', $unreadIds);
        if (($data['filter'] ?? 'all') === 'reminders') $reminders($query);
        if (($data['filter'] ?? 'all') === 'mine') {
            $query->where(fn ($open) => $open->whereNull('follow_up_status')->orWhereNotIn('follow_up_status', ['ganada', 'perdida']))
                ->whereNotIn('quotes.id', DB::table('quote_conversations')->select('quote_id')->where('stage', 'cerrada'));
            if ($user->role_slug === 'ventas') {
                $query->where(fn ($owner) => $owner->where('follow_up_assigned_to', $user->id)
                    ->orWhere(fn ($creator) => $creator->where('created_by', $user->id)
                        ->whereDoesntHave('followUpAssignee', fn ($assignee) => $assignee->where('active', true)
                            ->whereHas('role', fn ($role) => $role->where('slug', 'ventas')))));
            } elseif ($user->role_slug === 'gerente_compras') {
                $query->where(fn ($owner) => $owner->where('purchase_assigned_to', $user->id)
                    ->orWhere(fn ($unclaimed) => $unclaimed->where('status', 'solicitud_cotizaciones')->whereNull('purchase_assigned_to')));
            }
        }
        $page = $query->orderByDesc('updated_at')->orderBy('id')->paginate(30, ['*'], 'page', $data['page'] ?? 1);
        $rows = $page->getCollection()->map(fn ($quote) => [...$this->chat->summary($quote, $user),
            'sections' => ['history'], 'section' => 'history', 'notices' => []]);
        return response()->json(['data' => $rows, 'page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total(),
            'sections' => ['new_requests' => 0, 'own_requests' => 0, 'own_quotes' => 0, 'ready_to_send' => 0, 'quote_notices' => 0, 'pending' => 0, 'history' => $total],
            'unreadQuoteCount' => $unreadCount, 'reminderCount' => $reminderCount, 'unreadCount' => $this->chat->unreadCount($user)]);
    }

    public function claim(Request $request, string $id, PurchaseRequestWorkflowService $purchases): JsonResponse
    {
        DB::transaction(function () use ($request, $id, $purchases) {
            $quote = Quote::query()->lockForUpdate()->findOrFail($id);
            abort_unless($this->chat->sales($quote), 422, 'Asigna un vendedor a la cotización antes de iniciar el chat.');
            $claimed = $purchases->claim($quote, $request->user());
            $this->chat->open($claimed, $request->user());
        });

        return $this->show($request, $id);
    }

    public function open(Request $request, string $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id) {
            $quote = Quote::query()->lockForUpdate()->findOrFail($id);
            $this->chat->assertConversationAccess($quote, $request->user());
            $this->chat->initialize($quote, $request->user());
        });

        return $this->show($request, $id);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1']]);
        $quote = Quote::query()->with(['client', 'creator.role', 'followUpAssignee.role', 'purchaseAssignee', 'lines'])->findOrFail($id);
        $actor = $request->user();
        $this->chat->assertConversationAccess($quote, $actor);
        $query = DB::table('quote_chat_messages')->where('quote_id', $id);
        if (isset($data['before'])) $query->where('id', '<', $data['before']);
        $messages = $query->orderByDesc('id')->limit(101)->get();
        $more = $messages->count() > 100;
        $messages = $messages->take(100)->reverse()->values();
        $names = DB::table('users')->whereIn('id', $messages->pluck('sender_id')->filter()->unique())->pluck('name', 'id');
        $other = $actor->role_slug === 'ventas' ? $this->chat->purchasing($quote)?->id : $this->chat->sales($quote)?->id;
        $read = (int) DB::table('quote_chat_reads')->where('quote_id', $id)->where('user_id', $other)->value('last_message_id');

        $notifications = app(\App\Services\Sales\SalesNotificationService::class);
        $notices = $notifications->visibleForUser($actor, false)->where('quote_id', $id)->where('reason_code', '<>', 'mensaje_chat')
            ->reorder('created_at')->orderBy('id')->get()->map(fn ($notice) => [
                'id' => $notice->id, 'label' => $notifications->reasonLabel($notice->reason_code), 'detail' => $notice->message,
                'read' => $notice->read_at !== null, 'persistent' => true, 'createdAt' => $notice->created_at?->toIso8601String(),
            ]);
        $pending = app(\App\Services\Sales\NotificationInboxService::class)->forUser($actor)['groups']['pending'];
        foreach ($pending as $notice) if ($notice['quoteId'] === $id) $notices->push([
            'id' => $notice['id'], 'label' => 'Recordatorio vigente', 'detail' => $notice['detail'], 'read' => true,
            'persistent' => false, 'createdAt' => null,
        ]);
        return response()->json(['quote' => $this->chat->summary($quote, $actor), 'notices' => $notices, 'messages' => $messages->map(fn ($message) => [
            'id' => (string) $message->id, 'text' => $message->body, 'system' => $message->kind === 'system',
            'mine' => (int) $message->sender_id === (int) $actor->id, 'authorName' => $names[$message->sender_id] ?? 'Usuario',
            'createdAt' => $message->created_at, 'read' => $other && $read >= $message->id,
        ]), 'hasMore' => $more]);
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000'], 'clientId' => ['required', 'uuid']]);
        $messageId = DB::transaction(function () use ($request, $id, $data) {
            $quote = Quote::query()->lockForUpdate()->findOrFail($id);

            return $this->chat->send($quote, $request->user(), $data);
        });

        return response()->json(['id' => (string) $messageId], 201);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lastId' => ['required_without:noticeIds', 'nullable', 'integer', 'min:1'],
            'noticeIds' => ['required_without:lastId', 'array', 'min:1', 'max:100'], 'noticeIds.*' => ['required', 'uuid', 'distinct']]);
        DB::transaction(function () use ($request, $id, $data) {
            $quote = Quote::query()->lockForUpdate()->findOrFail($id);
            $this->chat->assertConversationAccess($quote, $request->user());
            if (! empty($data['noticeIds'])) {
                $notices = app(\App\Services\Sales\SalesNotificationService::class)->visibleForUser($request->user(), false)
                    ->where('quote_id', $id)->where('reason_code', '<>', 'mensaje_chat')->whereIn('id', $data['noticeIds']);
                abort_unless((clone $notices)->count() === count($data['noticeIds']), 422, 'El aviso no pertenece a esta cotización o a tu usuario.');
                $notices->whereNull('read_at')->update(['read_at' => now()]);
            }
            if (empty($data['lastId'])) return;
            $message = DB::table('quote_chat_messages')->where('quote_id', $id)->where('id', $data['lastId'])->first();
            abort_unless($message, 422, 'El mensaje no pertenece a esta conversación.');
            $current = (int) DB::table('quote_chat_reads')->where('quote_id', $id)->where('user_id', $request->user()->id)->value('last_message_id');
            DB::table('quote_chat_reads')->updateOrInsert(['quote_id' => $id, 'user_id' => $request->user()->id],
                ['last_message_id' => max($current, (int) $message->id), 'updated_at' => now()]);
            $unread = DB::table('quote_chat_messages')->where('quote_id', $id)->where('id', '>', max($current, (int) $message->id))
                ->where(fn ($query) => $query->where('sender_id', '<>', $request->user()->id)->orWhereNull('sender_id'))->exists();
            if (! $unread) SalesNotification::query()->where('quote_id', $id)->where('recipient_id', $request->user()->id)
                ->where('reason_code', 'mensaje_chat')->whereNull('read_at')->update(['read_at' => now()]);
        });

        return response()->json(['ok' => true, 'unreadCount' => $this->chat->unreadCount($request->user())]);
    }

    public function workflow(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:check,question,resume,ready,sent,close,reopen'],
            'text' => ['nullable', 'string', 'max:2000'], 'key' => ['required_if:action,check', 'in:stock,price,delivery'],
            'checked' => ['required_if:action,check', 'boolean']]);
        DB::transaction(function () use ($request, $id, $data) {
            $quote = Quote::query()->lockForUpdate()->findOrFail($id);
            $actor = $request->user();
            $this->chat->assertAccess($quote, $actor);
            $conversation = DB::table('quote_conversations')->where('quote_id', $id)->first();
            abort_unless($conversation, 409, 'Abre primero el chat.');
            $stage = $this->chat->stage($quote, $conversation);
            $action = $data['action'];
            $buying = in_array($actor->role_slug, ['gerente_compras', 'administrador'], true);
            $allowed = $buying
                ? $stage === 'en_proceso' && in_array($action, ['check', 'question', 'ready'], true)
                : ($stage === 'espera_ventas' && $action === 'resume') || ($stage === 'lista' && in_array($action, ['sent', 'reopen'], true)) || ($stage === 'enviada' && in_array($action, ['sent', 'close'], true));
            abort_unless($allowed, 409, 'Esta acción no corresponde a tu rol o a la etapa actual.');
            $text = trim($data['text'] ?? '');
            if (in_array($action, ['question', 'resume', 'reopen', 'close'], true) && $text === '') {
                throw ValidationException::withMessages(['text' => 'Describe el detalle para dejarlo en el chat.']);
            }
            $checks = json_decode($conversation->checks ?? '{}', true);
            if ($action === 'check') {
                $checks[$data['key']] = $data['checked'];
                $event = $actor->name.' '.($data['checked'] ? 'confirmó' : 'reabrió').' la revisión de '.['stock' => 'disponibilidad', 'price' => 'precios', 'delivery' => 'entrega'][$data['key']].'.';
                $next = $stage;
            } else {
                if ($action === 'ready') {
                    abort_unless(($checks['stock'] ?? false) && ($checks['price'] ?? false) && ($checks['delivery'] ?? false), 422, 'Completa los tres puntos de revisión.');
                    // Commercial validation remains in the existing quotation editor.
                    abort_unless($quote->status === 'pendiente_envio', 422, 'Termina y guarda la cotización desde Ver cotización antes de marcarla lista.');
                }
                if ($action === 'sent') {
                    abort_unless($quote->sent_at, 422, 'Envía la cotización desde su pantalla antes de confirmar el envío.');
                }
                if ($action === 'reopen') {
                    $checks['price'] = false; $checks['delivery'] = false;
                }
                $next = ['question' => 'espera_ventas', 'resume' => 'en_proceso', 'ready' => 'lista', 'sent' => 'enviada', 'close' => 'cerrada', 'reopen' => 'en_proceso'][$action];
                $event = ['question' => 'Compras pidió información. El siguiente paso es de Ventas.', 'resume' => 'Ventas respondió. Compras puede continuar.',
                    'ready' => 'Compras confirmó que la cotización está lista.', 'sent' => 'Ventas confirmó el envío de la cotización.',
                    'close' => 'Ventas cerró el seguimiento. El historial se conserva.', 'reopen' => 'Ventas solicitó un ajuste a Compras.'][$action];
                if ($text !== '') DB::table('quote_chat_messages')->insert(['quote_id' => $id, 'sender_id' => $actor->id, 'kind' => 'text', 'body' => $text, 'created_at' => now()]);
            }
            DB::table('quote_conversations')->where('quote_id', $id)->update(['stage' => $next, 'checks' => json_encode($checks), 'updated_at' => now()]);
            $this->chat->event($quote, $actor, $event);
            $this->chat->notify($quote, $actor, $event);
        });

        return $this->show($request, $id);
    }
}
