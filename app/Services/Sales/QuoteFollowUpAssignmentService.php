<?php

namespace App\Services\Sales;

use App\Models\Quote;
use App\Models\QuoteInternalNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuoteFollowUpAssignmentService
{
    public function __construct(
        private readonly SalesNotificationService $notifications,
    ) {}

    public function claim(Quote $quote, User $actor, string $declaration): Quote
    {
        if ($actor->role_slug !== 'ventas' || ! $actor->active) {
            throw ValidationException::withMessages([
                'quoteId' => 'Solo un usuario activo de Ventas puede tomar el seguimiento.',
            ]);
        }

        $declaration = trim($declaration);
        if (mb_strlen($declaration) < 10 || mb_strlen($declaration) > 1000) {
            throw ValidationException::withMessages([
                'declaration' => 'Escribe una declaración de entre 10 y 1000 caracteres antes de tomar el seguimiento.',
            ]);
        }

        return DB::transaction(function () use ($quote, $actor, $declaration) {
            $locked = Quote::query()
                ->with(['creator.role', 'followUpAssignee'])
                ->lockForUpdate()
                ->findOrFail($quote->id);

            if ($locked->creator?->role_slug !== 'ventas') {
                throw ValidationException::withMessages([
                    'quoteId' => 'Solo se pueden tomar cotizaciones creadas por Ventas.',
                ]);
            }

            if ($locked->follow_up_assigned_to !== null) {
                if ((int) $locked->follow_up_assigned_to === (int) $actor->id) {
                    return $locked;
                }

                $name = $locked->followUpAssignee?->name ?? 'otro vendedor';
                throw ValidationException::withMessages([
                    'quoteId' => "Esta cotización ya fue tomada por {$name}.",
                ]);
            }

            $eligibility = $this->notifications->eligibility($locked);
            if (! ($eligibility['eligible'] ?? false)) {
                throw ValidationException::withMessages([
                    'quoteId' => $eligibility['blockReason'] ?? 'La cotización ya no requiere seguimiento.',
                ]);
            }

            $now = now();
            $locked->forceFill([
                'follow_up_assigned_to' => $actor->id,
                'follow_up_assigned_by' => $actor->id,
                'follow_up_assigned_at' => $now,
            ])->save();

            QuoteInternalNote::query()->create([
                'quote_id' => $locked->id,
                'user_id' => $actor->id,
                'body' => "Seguimiento tomado por {$actor->name}. Declaración: {$declaration}",
                'created_at' => $now,
            ]);

            return $locked->fresh(['creator.role', 'followUpAssignee', 'followUpAssignedByUser']);
        });
    }

    public function release(Quote $quote, User $actor): Quote
    {
        if (! in_array($actor->role_slug, ['administrador', 'gerente_compras'], true)) {
            throw ValidationException::withMessages([
                'quoteId' => 'Solo Compras o Administración puede liberar el seguimiento.',
            ]);
        }

        return DB::transaction(function () use ($quote, $actor) {
            $locked = Quote::query()->with('followUpAssignee')->lockForUpdate()->findOrFail($quote->id);
            $previous = $locked->followUpAssignee?->name;

            if ($locked->follow_up_assigned_to === null) {
                return $locked;
            }

            $locked->forceFill([
                'follow_up_assigned_to' => null,
                'follow_up_assigned_by' => null,
                'follow_up_assigned_at' => null,
            ])->save();

            QuoteInternalNote::query()->create([
                'quote_id' => $locked->id,
                'user_id' => $actor->id,
                'body' => 'Seguimiento liberado por '.$actor->name.($previous ? "; responsable anterior: {$previous}." : '.'),
                'created_at' => now(),
            ]);

            return $locked->fresh(['creator.role', 'followUpAssignee', 'followUpAssignedByUser']);
        });
    }
}
