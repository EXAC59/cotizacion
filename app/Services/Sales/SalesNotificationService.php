<?php

namespace App\Services\Sales;

use App\Models\AppSetting;
use App\Models\Quote;
use App\Models\Role;
use App\Models\SalesNotification;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SalesNotificationService
{
    public const AUDIENCE_VENTAS = 'ventas';

    public const AUDIENCE_COMPRAS = 'compras';

    public const REASON_LISTA = 'lista_terminada';

    public const REASON_SIN_AVANCE = 'sin_avance';

    public const REASON_SEGUIMIENTO = 'seguimiento_actualizado';

    /**
     * @return array{eligible: bool, reasonCode: string|null, blockReason: string|null, daysIdle: int, pendingUnread: bool}
     */
    public function eligibility(Quote $quote): array
    {
        $daysIdle = $this->daysIdle($quote);

        if (in_array($quote->follow_up_status, ['ganada', 'perdida'], true)) {
            return [
                'eligible' => false,
                'reasonCode' => null,
                'blockReason' => 'Ya cerrada (ganada/perdida).',
                'daysIdle' => $daysIdle,
                'pendingUnread' => false,
            ];
        }

        if ($quote->status === 'pendiente_envio') {
            return [
                'eligible' => true,
                'reasonCode' => self::REASON_LISTA,
                'blockReason' => null,
                'daysIdle' => $daysIdle,
                'pendingUnread' => $this->hasUnreadDedupe($quote->id, self::REASON_LISTA, self::AUDIENCE_VENTAS),
            ];
        }

        $threshold = AppSetting::current()->resolvedUnansweredQuoteDays();
        if ($quote->status === 'en_elaboracion' && $daysIdle >= $threshold) {
            return [
                'eligible' => true,
                'reasonCode' => self::REASON_SIN_AVANCE,
                'blockReason' => null,
                'daysIdle' => $daysIdle,
                'pendingUnread' => $this->hasUnreadDedupe($quote->id, self::REASON_SIN_AVANCE, self::AUDIENCE_VENTAS),
            ];
        }

        if ($quote->status === 'en_elaboracion') {
            return [
                'eligible' => false,
                'reasonCode' => null,
                'blockReason' => "Aún en elaboración ({$daysIdle} día(s); se avisa desde {$threshold}).",
                'daysIdle' => $daysIdle,
                'pendingUnread' => false,
            ];
        }

        return [
            'eligible' => false,
            'reasonCode' => null,
            'blockReason' => 'No candidata a avisar.',
            'daysIdle' => $daysIdle,
            'pendingUnread' => false,
        ];
    }

    public function daysIdle(Quote $quote): int
    {
        $lastActivity = $quote->last_opened_at && $quote->last_opened_at->gt($quote->updated_at)
            ? $quote->last_opened_at
            : $quote->updated_at;

        if (! $lastActivity) {
            return 0;
        }

        return (int) $lastActivity->diffInDays(now());
    }

    public function defaultMessage(Quote $quote, string $reasonCode): string
    {
        if ($reasonCode === self::REASON_LISTA) {
            return 'Lista/Terminada: lista para que ventas continúe.';
        }

        if ($reasonCode === self::REASON_SIN_AVANCE) {
            $days = $this->daysIdle($quote);

            return "Sin avance: lleva {$days} días en elaboración.";
        }

        return "Cotización {$quote->folio}: revisar seguimiento.";
    }

    public function reasonLabel(string $reasonCode): string
    {
        return match ($reasonCode) {
            self::REASON_LISTA => 'Lista / Terminada',
            self::REASON_SIN_AVANCE => 'Sin avance en elaboración',
            self::REASON_SEGUIMIENTO => 'Recordatorio actualizado',
            default => 'Aviso',
        };
    }

    /**
     * @throws ValidationException
     */
    public function notifySales(Quote $quote, User $sender, ?string $message = null): SalesNotification
    {
        if (! in_array($sender->role_slug, ['administrador', 'gerente_compras'], true)) {
            throw ValidationException::withMessages([
                'quoteId' => 'Solo compras o administrador pueden avisar a ventas.',
            ]);
        }

        $eligibility = $this->eligibility($quote);
        if (! $eligibility['eligible'] || ! $eligibility['reasonCode']) {
            throw ValidationException::withMessages([
                'quoteId' => $eligibility['blockReason'] ?? 'No candidata a avisar.',
            ]);
        }

        $reasonCode = $eligibility['reasonCode'];
        if ($this->hasUnreadDedupe($quote->id, $reasonCode, self::AUDIENCE_VENTAS)) {
            throw ValidationException::withMessages([
                'quoteId' => 'Ya hay un aviso pendiente para esta cotización.',
            ]);
        }

        $recipient = $this->resolveVentasRecipient($quote);
        $text = trim((string) $message);
        if ($text === '') {
            $text = $this->defaultMessage($quote, $reasonCode);
        }

        return SalesNotification::query()->create([
            'quote_id' => $quote->id,
            'sender_id' => $sender->id,
            'recipient_id' => $recipient?->id,
            'audience' => self::AUDIENCE_VENTAS,
            'reason_code' => $reasonCode,
            'message' => $text,
            'read_at' => null,
        ]);
    }

    public function notifyComprasFollowUp(Quote $quote, User $actor, string $statusLabel): SalesNotification
    {
        // Marcar leídos avisos a ventas de esta cotización
        SalesNotification::query()
            ->where('quote_id', $quote->id)
            ->where('audience', self::AUDIENCE_VENTAS)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        // Evitar spam: si ya hay unread de seguimiento para esta quote, actualizar mensaje
        $existing = SalesNotification::query()
            ->where('quote_id', $quote->id)
            ->where('audience', self::AUDIENCE_COMPRAS)
            ->where('reason_code', self::REASON_SEGUIMIENTO)
            ->whereNull('read_at')
            ->first();

        $message = "{$actor->name} marcó {$statusLabel} en {$quote->folio}.";

        if ($existing) {
            $existing->update([
                'sender_id' => $actor->id,
                'message' => $message,
            ]);

            return $existing->fresh();
        }

        return SalesNotification::query()->create([
            'quote_id' => $quote->id,
            'sender_id' => $actor->id,
            'recipient_id' => null,
            'audience' => self::AUDIENCE_COMPRAS,
            'reason_code' => self::REASON_SEGUIMIENTO,
            'message' => $message,
            'read_at' => null,
        ]);
    }

    /**
     * @return array{data: list<array<string, mixed>>, unreadCount: int}
     */
    public function listForUser(User $user, int $limit = 40): array
    {
        $query = SalesNotification::query()
            ->with(['quote.client', 'sender'])
            ->orderByDesc('created_at')
            ->limit($limit);

        $role = $user->role_slug;
        if ($role === 'ventas') {
            $query->where('audience', self::AUDIENCE_VENTAS)
                ->where(function ($q) use ($user) {
                    $q->where('recipient_id', $user->id)
                        ->orWhereNull('recipient_id');
                });
        } elseif (in_array($role, ['gerente_compras', 'administrador'], true)) {
            $query->where('audience', self::AUDIENCE_COMPRAS);
        } else {
            return ['data' => [], 'unreadCount' => 0];
        }

        $items = $query->get();
        $unreadCount = $items->whereNull('read_at')->count();

        return [
            'data' => $items->map(fn (SalesNotification $n) => $this->toApiArray($n))->values()->all(),
            'unreadCount' => $unreadCount,
        ];
    }

    public function markRead(SalesNotification $notification, User $user): SalesNotification
    {
        $role = $user->role_slug;
        $allowed = false;

        if ($notification->audience === self::AUDIENCE_VENTAS) {
            $allowed = $role === 'administrador'
                || ($role === 'ventas' && ($notification->recipient_id === null || (int) $notification->recipient_id === (int) $user->id));
        }

        if ($notification->audience === self::AUDIENCE_COMPRAS) {
            $allowed = in_array($role, ['gerente_compras', 'administrador'], true);
        }

        if (! $allowed) {
            throw ValidationException::withMessages([
                'id' => 'No puedes marcar esta notificación.',
            ]);
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return $notification->fresh()->load(['quote.client', 'sender']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(SalesNotification $n): array
    {
        return [
            'id' => $n->id,
            'quoteId' => $n->quote_id,
            'folio' => $n->quote?->folio,
            'clientName' => $n->quote?->client?->company ?? '',
            'audience' => $n->audience,
            'reasonCode' => $n->reason_code,
            'reasonLabel' => $this->reasonLabel($n->reason_code),
            'message' => $n->message,
            'senderName' => $n->sender?->name,
            'createdAt' => $n->created_at?->toIso8601String(),
            'readAt' => $n->read_at?->toIso8601String(),
            'read' => $n->read_at !== null,
        ];
    }

    private function hasUnreadDedupe(string $quoteId, string $reasonCode, string $audience): bool
    {
        return SalesNotification::query()
            ->where('quote_id', $quoteId)
            ->where('reason_code', $reasonCode)
            ->where('audience', $audience)
            ->whereNull('read_at')
            ->exists();
    }

    private function resolveVentasRecipient(Quote $quote): ?User
    {
        $quote->loadMissing('creator.role');
        $creator = $quote->creator;
        if ($creator && $creator->active && $creator->role_slug === 'ventas') {
            return $creator;
        }

        $ventasRoleId = Role::query()->where('slug', 'ventas')->value('id');
        if (! $ventasRoleId) {
            return null;
        }

        return User::query()
            ->where('role_id', $ventasRoleId)
            ->where('active', true)
            ->orderBy('id')
            ->first();
    }
}
