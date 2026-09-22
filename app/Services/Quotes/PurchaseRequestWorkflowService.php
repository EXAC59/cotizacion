<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use App\Models\QuoteInternalNote;
use App\Models\SalesNotification;
use App\Models\User;
use App\Services\Sales\SalesNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseRequestWorkflowService
{
    public function claim(Quote $quote, User $actor): Quote
    {
        $this->assertPurchasingUser($actor);

        return DB::transaction(function () use ($quote, $actor) {
            $locked = Quote::query()
                ->with('purchaseAssignee')
                ->lockForUpdate()
                ->findOrFail($quote->id);

            if ($locked->status !== 'solicitud_cotizaciones') {
                throw ValidationException::withMessages([
                    'quoteId' => 'Esta cotización ya avanzó y no está disponible para tomar.',
                ]);
            }

            if ($locked->purchase_assigned_to !== null) {
                if ((int) $locked->purchase_assigned_to === (int) $actor->id) {
                    return $locked;
                }

                throw ValidationException::withMessages([
                    'quoteId' => 'Esta solicitud ya está siendo atendida por '.
                        ($locked->purchaseAssignee?->name ?? 'otro comprador').'.',
                ]);
            }

            $now = now();
            $locked->forceFill([
                'purchase_assigned_to' => $actor->id,
                'purchase_assigned_by' => $actor->id,
                'purchase_assigned_at' => $now,
            ])->save();

            QuoteInternalNote::query()->create([
                'quote_id' => $locked->id,
                'user_id' => $actor->id,
                'body' => "Solicitud tomada por Compras: {$actor->name} inició la atención.",
                'created_at' => $now,
            ]);

            SalesNotification::query()
                ->where('quote_id', $locked->id)
                ->where('recipient_id', $actor->id)
                ->where('audience', SalesNotificationService::AUDIENCE_COMPRAS)
                ->where('reason_code', SalesNotificationService::REASON_SOLICITUD_COMPRAS)
                ->whereNull('read_at')
                ->update(['read_at' => $now]);

            return $locked->fresh(['purchaseAssignee', 'purchaseAssignedByUser']);
        });
    }

    public function release(Quote $quote, User $actor): Quote
    {
        $this->assertPurchasingUser($actor);

        return DB::transaction(function () use ($quote, $actor) {
            $locked = Quote::query()
                ->with('purchaseAssignee')
                ->lockForUpdate()
                ->findOrFail($quote->id);

            if ($locked->purchase_assigned_to === null) {
                return $locked;
            }

            $isAssignee = (int) $locked->purchase_assigned_to === (int) $actor->id;
            if (! $isAssignee && $actor->role_slug !== 'administrador') {
                throw ValidationException::withMessages([
                    'quoteId' => 'Solo el comprador responsable o Administración puede liberar la solicitud.',
                ]);
            }

            $previous = $locked->purchaseAssignee?->name ?? 'Compras';
            $locked->forceFill([
                'purchase_assigned_to' => null,
                'purchase_assigned_by' => null,
                'purchase_assigned_at' => null,
            ])->save();

            QuoteInternalNote::query()->create([
                'quote_id' => $locked->id,
                'user_id' => $actor->id,
                'body' => "Solicitud liberada por {$actor->name}; responsable anterior: {$previous}.",
                'created_at' => now(),
            ]);

            return $locked->fresh(['purchaseAssignee', 'purchaseAssignedByUser']);
        });
    }

    public function assertCanEdit(Quote $quote, ?User $actor): void
    {
        if ($quote->status !== 'solicitud_cotizaciones' || $actor === null) {
            return;
        }

        if (! in_array($actor->role_slug, ['gerente_compras', 'administrador'], true)) {
            return;
        }

        if ($actor->role_slug === 'administrador') {
            return;
        }

        if ($quote->purchase_assigned_to === null) {
            throw ValidationException::withMessages([
                'quoteId' => 'Antes de editar, pulsa “Tomar solicitud”.',
            ]);
        }

        if ((int) $quote->purchase_assigned_to !== (int) $actor->id) {
            $quote->loadMissing('purchaseAssignee');
            throw ValidationException::withMessages([
                'quoteId' => 'La solicitud está siendo atendida por '.
                    ($quote->purchaseAssignee?->name ?? 'otro comprador').'.',
            ]);
        }
    }

    public function completeWhenAdvanced(Quote $quote, ?User $actor, ?string $previousStatus): Quote
    {
        if ($previousStatus !== 'solicitud_cotizaciones' || $quote->status === 'solicitud_cotizaciones') {
            return $quote;
        }

        $now = now();
        $quote->forceFill(['purchase_completed_at' => $now])->saveQuietly();

        SalesNotification::query()
            ->where('quote_id', $quote->id)
            ->where('audience', SalesNotificationService::AUDIENCE_COMPRAS)
            ->whereIn('reason_code', [
                SalesNotificationService::REASON_SOLICITUD_COMPRAS,
                SalesNotificationService::REASON_SOLICITUD_COMPRAS_URGENTE,
            ])
            ->whereNull('read_at')
            ->update(['read_at' => $now]);

        QuoteInternalNote::query()->create([
            'quote_id' => $quote->id,
            'user_id' => $actor?->id,
            'body' => 'Atención de Compras completada al avanzar la cotización a '.
                str_replace('_', ' ', $quote->status).'.',
            'created_at' => $now,
        ]);

        return $quote->fresh(['purchaseAssignee', 'purchaseAssignedByUser']);
    }

    private function assertPurchasingUser(User $actor): void
    {
        if (! $actor->active || ! in_array($actor->role_slug, ['gerente_compras', 'administrador'], true)) {
            throw ValidationException::withMessages([
                'quoteId' => 'Solo Compras o Administración puede realizar esta acción.',
            ]);
        }
    }
}
