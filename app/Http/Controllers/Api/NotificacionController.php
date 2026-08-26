<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\SalesNotification;
use App\Services\Sales\QuoteFollowUpService;
use App\Services\Sales\SalesNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    public function __construct(
        private readonly SalesNotificationService $notifications,
        private readonly QuoteFollowUpService $followUps,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $payload = $this->notifications->listForUser($request->user());

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'quoteId' => ['required', 'uuid', 'exists:quotes,id'],
            'message' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $quote = Quote::query()->findOrFail($data['quoteId']);
        $notification = $this->notifications->notifySales(
            $quote,
            $request->user(),
            $data['message'] ?? null,
        );

        return response()->json($this->notifications->toApiArray(
            $notification->load(['quote.client', 'sender', 'recipient'])
        ), 201);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = SalesNotification::query()->findOrFail($id);
        $updated = $this->notifications->markRead($notification, $request->user());

        return response()->json($this->notifications->toApiArray($updated));
    }

    public function seguimiento(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:negociacion,ganada,perdida'],
            'remindDate' => ['nullable', 'string'],
            'invoice' => ['nullable', 'string', 'max:60'],
            'comments' => ['nullable', 'string', 'max:500'],
        ]);

        $quote = Quote::query()->findOrFail($id);
        $result = $this->followUps->update($quote, $request->user(), $data);
        $fresh = $result['quote'];

        return response()->json([
            'followUp' => $this->followUps->followUpPayload($fresh),
            'followUpHistory' => $this->followUps->historyForQuote($fresh),
            'eligibility' => $this->notifications->eligibility($fresh),
        ]);
    }

    public function eligibility(Request $request, string $id): JsonResponse
    {
        $quote = Quote::query()->findOrFail($id);

        return response()->json($this->notifications->eligibility($quote));
    }
}
