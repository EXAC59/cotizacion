<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Quote;
use App\Support\MexicanRfc;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClienteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $query = Client::query()
            ->withCount('quotes')
            ->orderBy('company', 'asc');

        $search = trim($validated['q'] ?? '');
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($builder) use ($like) {
                $builder
                    ->where('company', 'like', $like)
                    ->orWhere('rfc', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('contact_name', 'like', $like);
            });
        }

        return response()->json([
            'data' => $query->get()->map(function (Client $c) {
                $payload = $c->toApiArray();
                $payload['quotesCount'] = (int) $c->quotes_count;

                return $payload;
            })->values(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $client = Client::query()
            ->withCount('quotes')
            ->withSum('quotes as quotes_total', 'total')
            ->findOrFail($id);

        $lastQuoteAt = $client->quotes()->max('created_at');

        $payload = $client->toApiArray(true);
        if ($lastQuoteAt !== null) {
            $payload['stats']['lastQuoteAt'] = $lastQuoteAt instanceof \DateTimeInterface
                ? $lastQuoteAt->format(\DateTimeInterface::ATOM)
                : (string) $lastQuoteAt;
        }

        return response()->json($payload);
    }

    public function cotizaciones(string $id, Request $request): JsonResponse
    {
        Client::query()->findOrFail($id);

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);

        $query = Quote::query()
            ->with('creator')
            ->withCount('lines')
            ->where('client_id', $id)
            ->orderByDesc('created_at')
            ->limit($limit);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json([
            'data' => $query->get()->map(fn (Quote $quote) => [
                'id' => $quote->id,
                'folio' => $quote->folio,
                'clientId' => $quote->client_id,
                'status' => $quote->status,
                'taxPercent' => (float) $quote->tax_percent,
                'total' => (float) $quote->total,
                'linesCount' => (int) $quote->lines_count,
                'createdByName' => $quote->creator?->name,
                'createdAt' => $quote->created_at?->toIso8601String(),
                'sentAt' => $quote->sent_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $this->assertUniqueRfc($validated['rfc'] ?? null);

        $client = Client::query()->create($this->mapToModel($validated));

        return response()->json($client->toApiArray(), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $client = Client::query()->findOrFail($id);
        $validated = $this->validatePayload($request);
        $this->assertUniqueRfc($validated['rfc'] ?? null, $client->id);

        $client->update($this->mapToModel($validated));

        return response()->json($client->fresh()->toApiArray());
    }

    public function destroy(string $id): JsonResponse
    {
        $client = Client::query()->withCount('quotes')->findOrFail($id);
        $quotesCount = (int) $client->quotes_count;

        if ($quotesCount > 0) {
            return response()->json([
                'message' => 'No se puede eliminar el cliente porque tiene cotizaciones asociadas.',
                'quotesCount' => $quotesCount,
                'code' => 'client_has_quotes',
            ], 409);
        }

        try {
            $client->delete();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'No se puede eliminar el cliente porque tiene cotizaciones asociadas.',
                'quotesCount' => $quotesCount,
                'code' => 'client_has_quotes',
            ], 409);
        }

        return response()->json(['message' => 'Cliente eliminado']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string'],
            'contact' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'paymentTerms' => ['nullable', 'string', 'max:120'],
        ]);

        $company = trim((string) ($validated['company'] ?? ''));
        if ($company === '') {
            throw ValidationException::withMessages([
                'company' => ['La empresa / razón social es obligatoria.'],
            ]);
        }
        $validated['company'] = $company;

        $rfc = trim((string) ($validated['rfc'] ?? ''));
        if ($rfc !== '' && ! MexicanRfc::isValid($rfc)) {
            throw ValidationException::withMessages([
                'rfc' => ['El RFC no tiene un formato válido.'],
            ]);
        }
        $validated['rfc'] = $rfc;

        return $validated;
    }

    private function assertUniqueRfc(?string $rfc, ?string $ignoreId = null): void
    {
        $normalized = Client::normalizeRfc($rfc);
        if ($normalized === null) {
            return;
        }

        $exists = Client::query()
            ->whereNormalizedRfc($normalized, $ignoreId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'rfc' => ['Ya existe un cliente con ese RFC.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mapToModel(array $validated): array
    {
        return [
            'company' => $validated['company'],
            'rfc' => $validated['rfc'] ?? '',
            'address' => $validated['address'] ?? '',
            'contact_name' => $validated['contact'] ?? '',
            'email' => $validated['email'] ?? '',
            'whatsapp' => $validated['whatsapp'] ?? '',
            'payment_terms' => $validated['paymentTerms'] ?? '',
        ];
    }
}
