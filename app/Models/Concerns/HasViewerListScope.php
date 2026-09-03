<?php

namespace App\Models\Concerns;

use App\Models\Quote;
use App\Models\User;
use App\Support\ViewerListScope;

trait HasViewerListScope
{
    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function scopeMadeByDisplayName($query, User $user)
    {
        $normalized = Quote::normalizeDisplayName($user->name);
        if ($normalized === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('creator', function ($creator) use ($normalized) {
            $creator->whereRaw('LOWER(TRIM(name)) = ?', [$normalized]);
        });
    }

    /**
     * Creador por id o por nombre normal (“Hecha por”).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function scopeOwnedByUser($query, User $user)
    {
        return $query->where(function ($outer) use ($user) {
            $outer->where('created_by', $user->id)
                ->orWhere(fn ($byName) => $byName->madeByDisplayName($user));
        });
    }

    public function isOwnedByUser(User $user): bool
    {
        return static::query()->whereKey($this->getKey())->ownedByUser($user)->exists();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function scopeForViewerList($query, User $user, string $scope)
    {
        if ($scope === ViewerListScope::ALL) {
            return $query;
        }

        $constrainMine = function ($inner) use ($user) {
            if ($this instanceof Quote && $user->role_slug === 'ventas') {
                $inner->visibleToSalesperson($user);

                return;
            }

            $inner->ownedByUser($user);
        };

        if ($scope === ViewerListScope::TEAM) {
            return $query->whereNot($constrainMine);
        }

        return $query->where($constrainMine);
    }
}
