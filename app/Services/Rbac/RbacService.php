<?php

namespace App\Services\Rbac;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RbacService
{
    public function isAvailable(): bool
    {
        return Schema::hasTable('roles')
            && Schema::hasTable('modules')
            && Schema::hasTable('permissions')
            && Schema::hasTable('role_permissions');
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    public function getRolePermissionMap(): array
    {
        $this->ensureAvailable();

        $map = $this->emptyMap();

        $rows = DB::table('role_permissions as rp')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('modules as m', 'm.id', '=', 'p.module_id')
            ->select(['r.slug as role_slug', 'm.slug as module_slug', 'p.action'])
            ->orderBy('r.slug')
            ->orderBy('m.sort_order')
            ->orderBy('p.action')
            ->get();

        foreach ($rows as $row) {
            if (! isset($map[$row->role_slug][$row->module_slug])) {
                $map[$row->role_slug][$row->module_slug] = [];
            }

            $map[$row->role_slug][$row->module_slug][] = $row->action;
        }

        $map['administrador'] = $this->fullAccessMap();

        return $map;
    }

    /**
     * @param  array<string, array<string, list<string>>>  $map
     * @return array<string, array<string, list<string>>>
     */
    public function updateRolePermissionMap(array $map): array
    {
        $this->ensureAvailable();
        $this->validateMap($map);

        DB::transaction(function () use ($map): void {
            $roleIds = DB::table('roles')->pluck('id', 'slug');

            foreach ($map as $roleSlug => $modules) {
                if ($roleSlug === 'administrador') {
                    continue;
                }

                if (! isset($roleIds[$roleSlug])) {
                    continue;
                }

                DB::table('role_permissions')->where('role_id', $roleIds[$roleSlug])->delete();
                $this->insertRolePermissions((int) $roleIds[$roleSlug], $modules);
            }
        });

        return $this->getRolePermissionMap();
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    public function resetToDefaults(): array
    {
        $this->ensureAvailable();

        DB::transaction(function (): void {
            DB::table('role_permissions')->delete();

            foreach (config('rbac.default_role_permissions', []) as $roleSlug => $modules) {
                $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
                if (! $roleId) {
                    continue;
                }

                if ($roleSlug === 'administrador' || $modules === 'all') {
                    $permissionIds = DB::table('permissions')->pluck('id');
                    foreach ($permissionIds as $permissionId) {
                        DB::table('role_permissions')->insert([
                            'role_id' => $roleId,
                            'permission_id' => $permissionId,
                        ]);
                    }

                    continue;
                }

                $this->insertRolePermissions((int) $roleId, $modules);
            }
        });

        return $this->getRolePermissionMap();
    }

    /**
     * Agrega permisos por defecto que falten sin quitar personalizaciones existentes.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function syncMissingDefaults(): array
    {
        $this->ensureAvailable();

        DB::transaction(function (): void {
            foreach (config('rbac.default_role_permissions', []) as $roleSlug => $modules) {
                if ($roleSlug === 'administrador' || $modules === 'all') {
                    continue;
                }

                $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
                if (! $roleId) {
                    continue;
                }

                $existing = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->pluck('permission_id')
                    ->all();

                $moduleIds = DB::table('modules')->pluck('id', 'slug');

                foreach ($modules as $moduleSlug => $actions) {
                    if (! isset($moduleIds[$moduleSlug]) || ! is_array($actions)) {
                        continue;
                    }

                    foreach ($actions as $action) {
                        $permissionId = DB::table('permissions')
                            ->where('module_id', $moduleIds[$moduleSlug])
                            ->where('action', $action)
                            ->value('id');

                        if (! $permissionId || in_array($permissionId, $existing, true)) {
                            continue;
                        }

                        DB::table('role_permissions')->insert([
                            'role_id' => $roleId,
                            'permission_id' => $permissionId,
                        ]);
                        $existing[] = $permissionId;
                    }
                }
            }
        });

        return $this->getRolePermissionMap();
    }

    public function userCan(User $user, string $module, string $action): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        $user->loadMissing('role');
        $roleSlug = $user->role_slug;

        if (! $roleSlug) {
            return false;
        }

        if ($roleSlug === 'administrador') {
            return true;
        }

        if ($roleSlug === 'ventas' && $module === 'dashboard' && $action === 'view') {
            return true;
        }

        if (! in_array($module, config('rbac.modules', []), true)) {
            return false;
        }

        $allowedActions = config('rbac.module_actions.'.$module, []);
        if (! in_array($action, $allowedActions, true)) {
            return false;
        }

        $map = $this->getRolePermissionMap();
        $actions = $map[$roleSlug][$module] ?? [];

        return in_array($action, $actions, true);
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private function emptyMap(): array
    {
        $map = [];

        foreach (config('rbac.roles', []) as $roleSlug) {
            $map[$roleSlug] = [];
            foreach (config('rbac.modules', []) as $moduleSlug) {
                $map[$roleSlug][$moduleSlug] = [];
            }
        }

        return $map;
    }

    /**
     * @return array<string, list<string>>
     */
    private function fullAccessMap(): array
    {
        $row = [];
        foreach (config('rbac.module_actions', []) as $moduleSlug => $actions) {
            $row[$moduleSlug] = $actions;
        }

        return $row;
    }

    /**
     * @param  array<string, list<string>>  $modules
     */
    private function insertRolePermissions(int $roleId, array $modules): void
    {
        $moduleIds = DB::table('modules')->pluck('id', 'slug');
        $allowedActions = config('rbac.module_actions', []);

        foreach ($modules as $moduleSlug => $actions) {
            if (! isset($moduleIds[$moduleSlug], $allowedActions[$moduleSlug])) {
                continue;
            }

            foreach ($actions as $action) {
                if (! in_array($action, $allowedActions[$moduleSlug], true)) {
                    continue;
                }

                $permissionId = DB::table('permissions')
                    ->where('module_id', $moduleIds[$moduleSlug])
                    ->where('action', $action)
                    ->value('id');

                if (! $permissionId) {
                    continue;
                }

                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    /**
     * @param  array<string, array<string, list<string>>>  $map
     */
    private function validateMap(array $map): void
    {
        $roles = config('rbac.roles', []);
        $modules = config('rbac.modules', []);
        $moduleActions = config('rbac.module_actions', []);

        foreach ($roles as $roleSlug) {
            if ($roleSlug === 'administrador') {
                continue;
            }

            if (! array_key_exists($roleSlug, $map)) {
                throw ValidationException::withMessages([
                    'rolePermissions' => "Falta el rol {$roleSlug}.",
                ]);
            }
        }

        foreach ($map as $roleSlug => $roleModules) {
            if ($roleSlug === 'administrador') {
                continue;
            }

            if (! is_array($roleModules)) {
                throw ValidationException::withMessages([
                    'rolePermissions' => "Permisos inválidos para {$roleSlug}.",
                ]);
            }

            foreach ($roleModules as $moduleSlug => $actions) {
                if (! in_array($moduleSlug, $modules, true)) {
                    throw ValidationException::withMessages([
                        'rolePermissions' => "Módulo desconocido: {$moduleSlug}.",
                    ]);
                }

                if (! is_array($actions)) {
                    throw ValidationException::withMessages([
                        'rolePermissions' => "Acciones inválidas en {$moduleSlug}.",
                    ]);
                }

                foreach ($actions as $action) {
                    if (! in_array($action, $moduleActions[$moduleSlug] ?? [], true)) {
                        throw ValidationException::withMessages([
                            'rolePermissions' => "Acción inválida {$action} en {$moduleSlug}.",
                        ]);
                    }
                }
            }
        }
    }

    private function ensureAvailable(): void
    {
        if (! $this->isAvailable()) {
            abort(503, 'El módulo de roles y permisos no está disponible. Ejecuta las migraciones o db:init-domain.');
        }
    }
}
