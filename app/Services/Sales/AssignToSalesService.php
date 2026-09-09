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
    public function listActiveCompras(): array
    {
        return User::query()
            ->with('role')
            ->where('active', true)
            ->whereHas('role', fn ($role) => $role->where('slug', 'gerente_compras'))
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

    public function assertCanAssignToCompras(User $actor): void
    {
        $actor->loadMissing('role');
        if (! in_array($actor->role_slug, ['ventas', 'administrador'], true)) {
            throw ValidationException::withMessages([
                'recipientId' => ['No tienes permiso para asignar a compras.'],
            ]);
        }
    }

    public function resolveActiveComprasUser(int $recipientId): User
    {
        $recipient = User::query()->with('role')->find($recipientId);
        if ($recipient === null || $recipient->active === false || $recipient->role_slug !== 'gerente_compras') {
            throw ValidationException::withMessages([
                'recipientId' => ['Elige un usuario de compras activo.'],
            ]);
        }

        return $recipient;
    }

    public function resolveDefaultComprasUser(): User
    {
        $recipient = User::query()
            ->with('role')
            ->where('active', true)
            ->whereHas('role', fn ($role) => $role->where('slug', 'gerente_compras'))
            ->orderBy('name')
            ->first();

        if ($recipient === null) {
            throw ValidationException::withMessages([
                'recipientId' => ['No hay usuarios de compras activos.'],
            ]);
        }

        return $recipient;
    }

    public function resolveComprasUser(?int $recipientId): User
    {
        return $recipientId !== null
            ? $this->resolveActiveComprasUser($recipientId)
            : $this->resolveDefaultComprasUser();
    }

    public function isAssignedToCompras(?User $creator): bool
    {
        return $this->creatorHasRole($creator, 'gerente_compras');
    }

    public function isAssignedToSales(?User $creator): bool
    {
        return $this->creatorHasRole($creator, 'ventas');
    }

    private function creatorHasRole(?User $creator, string $roleSlug): bool
    {
        if ($creator === null) {
            return false;
        }
        $creator->loadMissing('role');

        return $creator->role_slug === $roleSlug;
    }

    /**
     * @return array{quote: Quote, previousFolio: string, folio: string}
     */
    public function assignQuoteToCompras(Quote $quote, User $actor, ?int $recipientId = null, ?string $message = null): array
    {
        $this->assertCanAssignToCompras($actor);
        $quote->loadMissing('creator.role');
        $recipient = $this->resolveComprasUser($recipientId);
        $previousFolio = (string) $quote->folio;

        $quote = DB::transaction(function () use ($quote, $actor, $recipient, $message, $previousFolio) {
            $newFolio = $this->quoteFolios->generate($recipient);
            $quote->forceFill([
                'created_by' => $recipient->id,
                'folio' => $newFolio,
            ])->save();

            $text = trim((string) $message);
            if ($text === '') {
                $text = "Cotización {$previousFolio} enviada a compras como {$newFolio}.";
            }

            $this->notifications->notifyAssignment(
                $quote->fresh(),
                $actor,
                $recipient,
                SalesNotificationService::AUDIENCE_COMPRAS,
                $text,
            );

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
    public function assignSolicitudToCompras(QuoteRequest $request, User $actor, ?int $recipientId = null): array
    {
        $this->assertCanAssignToCompras($actor);
        $request->loadMissing('creator.role');
        $recipient = $this->resolveComprasUser($recipientId);
        $previousFolio = $request->folio;

        $request = DB::transaction(function () use ($request, $recipient) {
            $newFolio = $this->solicitudFolios->generate($recipient);
            $request->forceFill([
                'created_by' => $recipient->id,
                'folio' => $newFolio,
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
