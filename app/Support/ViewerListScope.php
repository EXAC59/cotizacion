<?php

namespace App\Support;

use App\Models\User;

final class ViewerListScope
{
    public const MINE = 'mine';

    public const TEAM = 'team';

    public const ALL = 'all';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::MINE, self::TEAM, self::ALL];
    }

    public static function defaultFor(?User $user): string
    {
        $slug = $user?->role_slug;

        return in_array($slug, ['ventas', 'gerente_compras'], true)
            ? self::MINE
            : self::ALL;
    }
}
