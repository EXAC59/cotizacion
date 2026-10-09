<?php

namespace App\Services\Sales;

use App\Models\User;
use App\Services\Analytics\DashboardAnalyticsService;
use App\Services\Rbac\RbacService;

class NotificationInboxService
{
    public function __construct(
        private readonly SalesNotificationService $notifications,
        private readonly DashboardAnalyticsService $analytics,
        private readonly RbacService $rbac,
    ) {}

    public function messagingGroupsForUser(User $user, \App\Services\Quotes\QuoteMessagingService $chat): array
    {
        $groups = $this->forUser($user)['groups'];
        $groups['ready_to_send'] = [];
        if ($user->role_slug !== 'ventas') return $groups;

        // Sales groups contain actual owned quotations, including those without a current bell notice.
        $groups = array_fill_keys(array_keys($groups), []);
        $quotes = $chat->participantQuotes($user)->with('client')->orderByDesc('updated_at')->get();
        $notices = $this->notifications->visibleForUser($user)->whereIn('quote_id', $quotes->pluck('id'))
            ->where('reason_code', '<>', 'mensaje_chat')->get()->groupBy('quote_id');
        foreach ($quotes as $quote) {
            $section = $quote->status === 'pendiente_envio' && $quote->sent_at === null ? 'ready_to_send' : 'own_quotes';
            $groups[$section][] = ['id' => 'sales-quote-'.$quote->id, 'quoteId' => $quote->id, 'requestId' => null,
                'folio' => $quote->folio, 'clientName' => $quote->client?->company ?? 'Sin cliente',
                'label' => $section === 'ready_to_send' ? 'Lista para enviar' : 'Tu cotización',
                'detail' => $section === 'ready_to_send' ? 'Terminada y pendiente de envío al cliente.' : 'Seguimiento entre Ventas y Compras.',
                'read' => true, 'reminder' => false, 'createdAt' => $quote->created_at?->toIso8601String(), 'isQuote' => true];
            foreach ($notices->get($quote->id, collect()) as $notice) {
                $groups[$section][] = ['id' => $notice->id, 'quoteId' => $quote->id, 'requestId' => null,
                    'folio' => $quote->folio, 'clientName' => $quote->client?->company ?? 'Sin cliente',
                    'label' => $this->notifications->reasonLabel($notice->reason_code), 'detail' => $notice->message,
                    'read' => $notice->read_at !== null, 'reminder' => in_array($notice->reason_code,
                        ['sin_avance', 'en_elaboracion', 'seguimiento_actualizado'], true), 'createdAt' => $notice->created_at?->toIso8601String()];
            }
        }
        return $groups;
    }

    /** The bell and messaging read this same inbox, including its existing limits and permissions. */
    public function forUser(User $user, int $page = 1): array
    {
        $payload = $this->notifications->listForUser($user, 40, $page);
        $items = collect($payload['data']);
        $notices = $items->filter(fn ($item) => ! in_array($item['kind'] ?? 'inbox', ['pipeline', 'pipeline_request'], true));
        $notifiedIds = $notices->pluck('quoteId')->filter()->all();
        $alerts = $user->role_slug !== 'ventas' && $this->rbac->userCan($user, 'dashboard', 'view')
            ? $this->analytics->quoteNotificationAlerts($user) : ['purchaseRequestQuotes' => [], 'unansweredQuotes' => []];
        $purchaseRequests = collect($alerts['purchaseRequestQuotes'])->reject(fn ($quote) => in_array($quote['id'], $notifiedIds, true))
            ->map(fn ($quote) => ['id' => 'purchase-request-'.$quote['id'], 'quoteId' => $quote['id'], 'folio' => $quote['folio'],
                'clientName' => $quote['clientName'], 'label' => 'Solicitud de cotización',
                'detail' => $quote['purchaseAttentionStatus'] === 'en_atencion'
                    ? 'En atención por '.($quote['purchaseAssigneeName'] ?? 'Compras') : 'Pendiente de revisión por Compras',
            ])->values()->all();
        $operational = collect($alerts['unansweredQuotes'])->map(fn ($quote) => [
            'id' => 'unanswered-'.$quote['id'], 'quoteId' => $quote['id'], 'folio' => $quote['folio'], 'clientName' => $quote['clientName'],
            'label' => 'Sin avance', 'detail' => ($quote['clientName'] ?: 'Sin cliente').' · '.$quote['daysWaiting'].' días sin actividad',
        ])->values()->all();
        $notificationItem = fn ($item) => [
            'id' => $item['id'], 'quoteId' => $item['quoteId'] ?? null, 'requestId' => $item['requestId'] ?? null,
            'folio' => $item['folio'] ?? 'Cotización', 'clientName' => $item['clientName'],
            'label' => $item['reasonLabel'], 'detail' => $item['message'], 'read' => $item['read'], 'createdAt' => $item['createdAt'] ?? null,
            'messageNotice' => $item['reasonCode'] === 'mensaje_chat',
            'reminder' => in_array($item['reasonCode'], ['sin_avance', 'en_elaboracion', 'seguimiento_actualizado'], true),
        ];
        $messageCounts = config('messaging.enabled') && $user->active && in_array($user->role_slug, ['ventas', 'gerente_compras', 'administrador'], true)
            ? app(\App\Services\Quotes\QuoteMessagingService::class)->unreadMessageCounts($user) : [];
        $chatNoticeCounts = $messageCounts ? $this->notifications->visibleForUser($user)->where('reason_code', 'mensaje_chat')
            ->whereNull('read_at')->reorder()->select('quote_id')->selectRaw('COUNT(*) as notice_total')
            ->groupBy('quote_id')->pluck('notice_total', 'quote_id')->all() : [];
        // One bell notice may represent several messages: add only the messages it does not already count.
        $extraMessages = collect($messageCounts)->map(fn ($count, $id) => max(0, $count - (int) ($chatNoticeCounts[$id] ?? 0)))->sum();
        $pendingCount = $payload['unreadCount'] + count($purchaseRequests) + count($operational) + $extraMessages;
        return [...$payload, 'pendingCount' => $pendingCount, 'unreadMessageCount' => array_sum($messageCounts),
            'purchaseRequests' => $purchaseRequests, 'operational' => $operational,
            'groups' => [
                'new_requests' => array_map(fn ($item) => [...$item, 'requestId' => null, 'read' => false, 'reminder' => false], $purchaseRequests),
                'own_requests' => $items->where('kind', 'pipeline_request')->map($notificationItem)->values()->all(),
                'own_quotes' => $items->where('kind', 'pipeline')->map($notificationItem)->values()->all(),
                'quote_notices' => $notices->map($notificationItem)->values()->all(),
                'pending' => array_map(fn ($item) => [...$item, 'requestId' => null, 'read' => false, 'reminder' => true], $operational),
            ],
        ];
    }
}
