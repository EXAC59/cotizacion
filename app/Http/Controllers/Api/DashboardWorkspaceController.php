<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Services\Analytics\DashboardWorkspaceService;
use App\Support\ViewerListScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardWorkspaceController extends Controller
{
    public function index(Request $request, DashboardWorkspaceService $workspace): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'scope' => ['nullable', Rule::in(ViewerListScope::values())],
            'status' => ['nullable', Rule::in(config('quotes.statuses'))],
            'focus' => ['nullable', Rule::in(['all', 'pending', 'unread', 'progress', 'ready', 'sent', 'unsent'])],
            'delivery' => ['nullable', Rule::in(['all', 'sent', 'unsent'])],
            'sort' => ['nullable', Rule::in(['date', 'status', 'oldest'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', Rule::in([5, 10, 15])],
            'recent' => ['nullable', 'boolean'],
        ]);

        return response()->json($workspace->payload($request->user(), $filters));
    }

    public function viewed(Request $request, string $id): JsonResponse
    {
        $quote = Quote::query()->forViewerList($request->user(), ViewerListScope::ALL)->findOrFail($id);
        $now = now();
        // Concurrent tabs preserve the first reading; never touch commercial activity or locks.
        DB::table('quote_user_views')->upsert([
            ['quote_id' => $quote->id, 'user_id' => $request->user()->id,
                'first_opened_at' => $now, 'last_opened_at' => $now],
        ], ['quote_id', 'user_id'], ['last_opened_at']);

        return response()->json(['viewedAt' => $now->toIso8601String()]);
    }
}
