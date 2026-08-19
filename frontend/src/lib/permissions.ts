import type { User, UserRole } from '@/types'
import type { ModuleId, PermissionAction, RolePermissionMap } from '@/types/rbac'
import { DEFAULT_ROLE_PERMISSIONS } from '@/data/rbac-defaults'
import { MODULE_IDS } from '@/types/rbac'

export function normalizeRolePermissions(parsed?: Partial<RolePermissionMap>): RolePermissionMap {
  const defaults = structuredClone(DEFAULT_ROLE_PERMISSIONS)
  const merged = {
    ...defaults,
    ...(parsed ?? {}),
    administrador: defaults.administrador,
  } as RolePermissionMap

  for (const role of Object.keys(defaults) as UserRole[]) {
    for (const module of MODULE_IDS) {
      merged[role][module] = [...(merged[role][module] ?? [])]
    }
  }

  return merged
}

export function can(
  rolePermissions: RolePermissionMap,
  role: UserRole,
  module: ModuleId,
  action: PermissionAction,
): boolean {
  if (role === 'administrador') return true
  const actions = rolePermissions[role]?.[module] ?? []
  return actions.includes(action)
}

export function canAccessModule(
  rolePermissions: RolePermissionMap,
  user: User | null,
  module: ModuleId,
): boolean {
  if (!user) return false
  if (user.active === false) return false
  if (module === 'dashboard') {
    return canAccessDashboard(rolePermissions, user)
  }
  return can(rolePermissions, user.role, module, 'view')
}

/** Acceso al dashboard (ventas siempre; otros roles requieren permiso Ver). */
export function canAccessDashboard(
  rolePermissions: RolePermissionMap,
  user: User | null,
): boolean {
  if (!user) return false
  if (user.active === false) return false
  if (user.role === 'ventas') return true
  return can(rolePermissions, user.role, 'dashboard', 'view')
}

/** Vista ejecutiva: financiero, stock bajo en mayoristas (no aplica a ventas). */
export function canViewDashboardExecutive(
  rolePermissions: RolePermissionMap,
  user: User | null,
): boolean {
  if (!user) return false
  if (user.active === false) return false
  if (user.role === 'ventas') return false
  return can(rolePermissions, user.role, 'dashboard', 'view')
}

export function userHasPermission(
  rolePermissions: RolePermissionMap,
  user: User | null,
  module: ModuleId,
  action: PermissionAction,
): boolean {
  if (!user) return false
  if (user.active === false) return false
  return can(rolePermissions, user.role, module, action)
}
