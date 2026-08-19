<?php

namespace App\Services\Quotes;

use App\Exceptions\QuoteLockedException;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class QuoteLockService
{
    public function acquire(Quote $quote, ?User $user = null): Quote
    {
        $user = $user ?? Auth::user();
        if ($user === null) {
            throw new QuoteLockedException(
                lockedBy: new User(['name' => 'Usuario desconocido', 'email' => '']),
                message: 'Debes iniciar sesión para editar la cotización.',
            );
        }

        $this->clearExpiredLock($quote);
        $quote->refresh();

        if ($quote->locked_by === null) {
            $quote->update([
                'locked_by' => $user->id,
                'locked_at' => now(),
                'last_opened_at' => now(),
            ]);

            return $quote->fresh(['lockedByUser']);
        }

        if ((int) $quote->locked_by === (int) $user->id) {
            $quote->update([
                'locked_at' => now(),
                'last_opened_at' => now(),
            ]);

            return $quote->fresh(['lockedByUser']);
        }

        $holder = $quote->lockedByUser ?? User::query()->find($quote->locked_by);

        throw new QuoteLockedException(
            lockedBy: $holder ?? new User(['name' => 'Otro usuario', 'email' => '']),
            lockedAt: $quote->locked_at?->toIso8601String(),
        );
    }

    public function release(Quote $quote, ?User $user = null): void
    {
        $user = $user ?? Auth::user();
        if ($user === null || $quote->locked_by === null) {
            return;
        }

        if ((int) $quote->locked_by !== (int) $user->id) {
            return;
        }

        $quote->update([
            'locked_by' => null,
            'locked_at' => null,
        ]);
    }

    public function assertHeldByCurrentUser(Quote $quote, ?User $user = null): void
    {
        $user = $user ?? Auth::user();
        if ($user === null) {
            throw new QuoteLockedException(
                lockedBy: new User(['name' => 'Usuario desconocido', 'email' => '']),
                message: 'Debes iniciar sesión para guardar la cotización.',
            );
        }

        $this->clearExpiredLock($quote);
        $quote->refresh();

        if ($quote->locked_by === null) {
            throw new QuoteLockedException(
                lockedBy: new User(['name' => 'Reserva expirada', 'email' => '']),
                message: 'Tu reserva de edición expiró. Vuelve a abrir la cotización.',
            );
        }

        if ((int) $quote->locked_by !== (int) $user->id) {
            $holder = $quote->lockedByUser ?? User::query()->find($quote->locked_by);

            throw new QuoteLockedException(
                lockedBy: $holder ?? new User(['name' => 'Otro usuario', 'email' => '']),
                lockedAt: $quote->locked_at?->toIso8601String(),
            );
        }

        $quote->update(['locked_at' => now()]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockPayload(Quote $quote, ?User $viewer = null): ?array
    {
        $this->clearExpiredLock($quote);
        $quote->loadMissing('lockedByUser');

        if ($quote->locked_by === null || $quote->lockedByUser === null) {
            return null;
        }

        $viewerId = $viewer?->id ?? Auth::id();

        return [
            'userId' => (string) $quote->locked_by,
            'userName' => $quote->lockedByUser->name,
            'userEmail' => $quote->lockedByUser->email,
            'lockedAt' => $quote->locked_at?->toIso8601String(),
            'isOwn' => $viewerId !== null && (int) $viewerId === (int) $quote->locked_by,
        ];
    }

    private function clearExpiredLock(Quote $quote): void
    {
        if ($quote->locked_by === null) {
            return;
        }

        // Sin marca de tiempo no hay evidencia de actividad reciente → liberar.
        if ($quote->locked_at === null) {
            $quote->update([
                'locked_by' => null,
                'locked_at' => null,
            ]);

            return;
        }

        $ttl = max(30, (int) config('quotes.lock_ttl_seconds', 120));

        if ($quote->locked_at->lte(now()->subSeconds($ttl))) {
            $quote->update([
                'locked_by' => null,
                'locked_at' => null,
            ]);
        }
    }
}
