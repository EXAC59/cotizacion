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
        return $this->listActiveUsersByRole('ventas');
    }

    /**
     * @return list<array{id: int, name: string, folioCode: string|null}>
     */
    public function listActiveCompras(): array
    {
        return $this->listActiveUsersByRole('gerente_compras');
    }

    /**
     * @return list<array{id: int, name: string, folioCode: string|null}>
     */
    private function listActiveUsersByRole(string $roleSlug): array
    {
        return User::query()
            ->with('role')
            ->where('active', true)
            ->whereHas('role', fn ($role) => $role->where('slug', $roleSlug))
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

    public function assertCanAssignToSales(User $actor): void
    {
        $actor->loadMissing('role');
        if (! in_array($actor->role_slug, ['gerente_compras', 'administrador'], true)) {
            throw ValidationException::withMessages([
                'recipientId' => ['No tienes permiso para asignar a ventas.'],
            ]);
        }
    }

    /** @deprecated Use assertCanAssignToSales */
    public function assertCanAssign(User $actor): void
    {
        $this->assertCanAssignToSales($actor);
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

    public function resolveActiveSalesperson(int $recipientId): User
    {
        return $this->resolveActiveUserByRole($recipientId, 'ventas', 'Elige un usuario de ventas activo.');
    }

    public function resolveActiveComprasUser(int $recipientId): User
    {
        return $this->resolveActiveUserByRole(
            $recipientId,
            'gerente_compras',
            'Elige un usuario de compras activo.',
        );
    }

    /** Primer usuario activo del área (cuando no se elige persona en UI). */
    public function resolveDefaultSalesperson(): User
    {
        return $this->resolveDefaultUserByRole('ventas', 'No hay usuarios de ventas activos.');
    }

    /** Primer usuario activo de compras (cuando no se elige persona en UI). */
    public function resolveDefaultComprasUser(): User
    {
        return $this->resolveDefaultUserByRole(
            'gerente_compras',
            'No hay usuarios de compras activos.',
        );
    }

    private function resolveActiveUserByRole(int $recipientId, string $roleSlug, string $error): User
    {
        $recipient = User::query()->with('role')->find($recipientId);
        if ($recipient === null || $recipient->active === false || $recipient->role_slug !== $roleSlug) {
            throw ValidationException::withMessages([
                'recipientId' => [$error],
            ]);
        }

        return $recipient;
    }

    private function resolveDefaultUserByRole(string $roleSlug, string $emptyError): User
    {
        $recipient = User::query()
            ->with('role')
            ->where('active', true)
            ->whereHas('role', fn ($role) => $role->where('slug', $roleSlug))
            ->orderBy('name')
            ->first();

        if ($recipient === null) {
            throw ValidationException::withMessages([
                'recipientId' => [$emptyError],
            ]);
        }

        return $recipient;
    }

    private function resolveSalesperson(?int $recipientId): User
    {
        return $recipientId !== null
            ? $this->resolveActiveSalesperson($recipientId)
            : $this->resolveDefaultSalesperson();
    }

    private function resolveComprasUser(?int $recipientId): User
    {
        return $recipientId !== null
            ? $this->resolveActiveComprasUser($recipientId)
            : $this->resolveDefaultComprasUser();
    }

    public function isAssignedToSales(?User $creator): bool
    {
        return $this->creatorHasRole($creator, 'ventas');
    }

    public function isAssignedToCompras(?User $creator): bool
    {
        return $this->creatorHasRole($creator, 'gerente_compras');
    }

    private function creatorHasRole(?User $creator, string $roleSlug): bool
    {
        if ($creator === null) {
            return false;
        }
        $creator->loadMissing('role');

        return $creator->role_slug === $roleSlug;
    }

    public function assertNotAlreadyAssignedToSales(?User $creator, string $entityLabel): void
    {
        // Reasignación libre entre compras/ventas: no bloquear.
    }

    public function assertNotAlreadyAssignedToCompras(?User $creator, string $entityLabel): void
    {
        // Reasignación libre entre compras/ventas: no bloquear.
    }

    /**
     * @return array{quote: Quote, previousFolio: string, folio: string}
     */
    public function assignQuote(Quote $quote, User $actor, ?int $recipientId = null, ?string $message = null): array
    {
        $this->assertCanAssignToSales($actor);
        $quote->loadMissing('creator.role');
        $this->assertNotAlreadyAssignedToSales($quote->creator, 'cotización');
        $recipient = $this->resolveSalesperson($recipientId);
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

            $this->notifications->notifyAssignment(
                $quote->fresh(),
                $actor,
                $recipient,
                SalesNotificationService::AUDIENCE_VENTAS,
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
     * @return array{quote: Quote, previousFolio: string, folio: string}
     */
    public function assignQuoteToCompras(Quote $quote, User $actor, ?int $recipientId = null, ?string $message = null): array
    {
        $this->assertCanAssignToCompras($actor);
        $quote->loadMissing('creator.role');
        $this->assertNotAlreadyAssignedToCompras($quote->creator, 'cotización');
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
    public function assignSolicitud(QuoteRequest $request, User $actor, ?int $recipientId = null): array
    {
        $this->assertCanAssignToSales($actor);
        $request->loadMissing('creator.role');
        $this->assertNotAlreadyAssignedToSales($request->creator, 'solicitud');
        $recipient = $this->resolveSalesperson($recipientId);
        $previousFolio = $request->folio;

        $request = DB::transaction(function () use ($request, $recipient, $actor) {
            $newFolio = $this->solicitudFolios->generate($recipient);
            $request->forceFill([
                'created_by' => $recipient->id,
                'folio' => $newFolio,
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

    /**
     * @return array{request: QuoteRequest, previousFolio: string|null, folio: string|null}
     */
    public function assignSolicitudToCompras(QuoteRequest $request, User $actor, ?int $recipientId = null): array
    {
        $this->assertCanAssignToCompras($actor);
        $request->loadMissing('creator.role');
        $this->assertNotAlreadyAssignedToCompras($request->creator, 'solicitud');
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
