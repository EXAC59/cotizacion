<?php

namespace App\Services\Sales;

use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\User;
use App\Services\Quotes\QuoteFolioGenerator;
use App\Services\SolicitudFolioGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignToSalesService
{
    public function __construct(
        private readonly QuoteFolioGenerator $quoteFolios,
        private readonly SolicitudFolioGenerator $solicitudFolios,
        private readonly SalesNotificationService $notifications,
    ) {}

    /**
     * @return list<array{id: int, name: string, folioCode: string|null}>
     */
    public function listActiveSalespeople(): array
    {
        return User::query()
            ->with('role')
            ->where('active', true)
            ->whereHas('role', fn ($role) => $role->where('slug', 'ventas'))
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'folioCode' => $user->resolveQuoteFolioCode(),
            ])
            ->values()
            ->all();
    }

    public function assertCanAssign(User $actor): void
    {
        $actor->loadMissing('role');
        if (! in_array($actor->role_slug, ['gerente_compras', 'administrador'], true)) {
            throw ValidationException::withMessages([
                'recipientId' => ['Solo compras o administrador pueden asignar a ventas.'],
            ]);
        }
    }

    public function resolveActiveSalesperson(int $recipientId): User
    {
        $recipient = User::query()->with('role')->find($recipientId);
        if ($recipient === null || $recipient->active === false || $recipient->role_slug !== 'ventas') {
            throw ValidationException::withMessages([
                'recipientId' => ['Elige un usuario de ventas activo.'],
            ]);
        }

        return $recipient;
    }

    public function isAssignedToSales(?User $creator): bool
    {
        if ($creator === null) {
            return false;
        }
        $creator->loadMissing('role');

        return $creator->role_slug === 'ventas';
    }

    public function assertNotAlreadyAssignedToSales(?User $creator, string $entityLabel): void
    {
        if (! $this->isAssignedToSales($creator)) {
            return;
        }

        throw ValidationException::withMessages([
            'recipientId' => ["Esta {$entityLabel} ya fue enviada a ventas y no se puede reasignar."],
        ]);
    }

    /**
     * @return array{quote: Quote, previousFolio: string, folio: string}
     */
    public function assignQuote(Quote $quote, User $actor, int $recipientId, ?string $message = null): array
    {
        $this->assertCanAssign($actor);
        $quote->loadMissing('creator.role');
        $this->assertNotAlreadyAssignedToSales($quote->creator, 'cotización');
        $recipient = $this->resolveActiveSalesperson($recipientId);
        $previousFolio = (string) $quote->folio;

        $quote = DB::transaction(function () use ($quote, $actor, $recipient, $message, $previousFolio) {
            $newFolio = $this->quoteFolios->generate($recipient);
            $quote->forceFill([
                'created_by' => $recipient->id,
                'folio' => $newFolio,
            ])->save();

            $text = trim((string) $message);
            if ($text === '') {
                $text = "Cotización {$previousFolio} asignada como {$newFolio} para tu seguimiento.";
            }

            $this->notifications->notifySales($quote->fresh(), $actor, $text, (int) $recipient->id);

            return $quote->fresh(['client', 'creator', 'lines.offers.wholesaler', 'internalNotes.user']);
        });

        return [
            'quote' => $quote,
            'previousFolio' => $previousFolio,
            'folio' => (string) $quote->folio,
        ];
    }

    /**
     * @return array{request: QuoteRequest, previousFolio: string|null, folio: string|null}
     */
    public function assignSolicitud(QuoteRequest $request, User $actor, int $recipientId): array
    {
        $this->assertCanAssign($actor);
        $request->loadMissing('creator.role');
        $this->assertNotAlreadyAssignedToSales($request->creator, 'solicitud');
        $recipient = $this->resolveActiveSalesperson($recipientId);
        $previousFolio = $request->folio;

        $request = DB::transaction(function () use ($request, $recipient, $actor) {
            $newFolio = $this->solicitudFolios->generate($recipient);
            $request->forceFill([
                'created_by' => $recipient->id,
                'folio' => $newFolio,
                // Quien asigna (compras/admin) queda como revisor externo.
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ])->save();

            return $request->fresh(['lines', 'client', 'creator.role', 'reviewer']);
        });

        return [
            'request' => $request,
            'previousFolio' => $previousFolio,
            'folio' => $request->folio,
        ];
    }
}
