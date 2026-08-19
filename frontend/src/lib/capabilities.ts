import { can } from '@/lib/permissions'
import type { User, UserRole } from '@/types'
import { ROLE_LABELS } from '@/types'
import type { RolePermissionMap } from '@/types/rbac'

export const CAPABILITY_LABELS = [
  'Crear cotizaciones',
  'Editar márgenes',
  'Aprobar cotizaciones',
  'Enviar cotizaciones',
  'Consultar inventarios',
  'Alta y edición clientes',
  'Importar Excel clientes',
  'Ver reportes y analítica',
  'Gestión de usuarios',
  'Config. integraciones',
] as const

export type CapabilityId = (typeof CAPABILITY_LABELS)[number]

export const CAPABILITY_ROLES = Object.keys(ROLE_LABELS) as UserRole[]

function hasAction(
  rolePermissions: RolePermissionMap,
  role: UserRole,
  module: keyof RolePermissionMap[UserRole],
  action: string,
): boolean {
  return can(rolePermissions, role, module, action as never)
}

export function canCreateQuotes(
  rolePermissions: RolePermissionMap,
  user: User | null,
): boolean {
  return user ? hasAction(rolePermissions, user.role, 'cotizaciones', 'create') : false
}

export function canEditMargins(
  rolePermissions: RolePermissionMap,
  user: User | null,
): boolean {
  return user ? hasAction(rolePermissions, user.role, 'cotizaciones', 'edit_margin') : false
}

export function canApproveQuotes(
  rolePermissions: RolePermissionMap,
  user: User | null,
): boolean {
  return user ? hasAction(rolePermissions, user.role, 'cotizaciones', 'approve') : false
}

export function canSendQuotes(
  rolePermissions: RolePermissionMap,
  user: User | null,
): boolean {
  return user ? hasAction(rolePermissions, user.role, 'cotizaciones', 'send') : false
}

export function canConsultInventory(
  rolePermissions: RolePermissionMap,
  user: User | null,
): boolean {
  return user ? hasAction(rolePermissions, user.role, 'mayoristas', 'view') : false
}

export function canManageClients(
  rolePermissions: RolePermissionMap,
  user: User | null,
): boolean {
  if (!user) return false
  return (
    hasAction(rolePermissions, user.role, 'clientes', 'create') ||
    hasAction(rolePermissions, user.role, 'clientes', 'edit')
  )
}

export function canImportClients(
  rolePermissions: RolePermissionMap,
  user: User | null,
): boolean {
  return user ? hasAction(rolePermissions, user.role, 'clientes', 'import') : false
}

export function canViewReports(
  rolePermissions: RolePermissionMap,
  user: User | null,
): boolean {
  return user ? hasAction(rolePermissions, user.role, 'reportes', 'view') : false
}

export function canManageUsers(
  rolePermissions: RolePermissionMap,
  user: User | null,
): boolean {
  return user ? hasAction(rolePermissions, user.role, 'admin', 'manage') : false
}

export function canConfigIntegrations(
  rolePermissions: RolePermissionMap,
  user: User | null,
): boolean {
  return user ? hasAction(rolePermissions, user.role, 'configuracion', 'edit') : false
}

/** Parámetros comerciales globales (margen/IVA): admin y gerente de compras. */
export function canEditCommercialSettings(user: User | null): boolean {
  if (!user) return false
  return user.role === 'administrador' || user.role === 'gerente_compras'
}

/** Alertas de integración de mayoristas en dashboard: compras y admin (no ventas). */
export function canViewWholesalerIntegrationAlerts(user: User | null): boolean {
  if (!user) return false
  return user.role === 'administrador' || user.role === 'gerente_compras'
}

/** Detalle de mejor oferta + flete en comparador: compras y admin (no ventas). */
export function canViewComparatorFreightInfo(user: User | null): boolean {
  if (!user) return false
  return user.role === 'administrador' || user.role === 'gerente_compras'
}


function roleHasCapability(
  rolePermissions: RolePermissionMap,
  role: UserRole,
  capability: CapabilityId,
): boolean {
  const pseudoUser = { role } as User
  switch (capability) {
    case 'Crear cotizaciones':
      return canCreateQuotes(rolePermissions, pseudoUser)
    case 'Editar márgenes':
      return canEditMargins(rolePermissions, pseudoUser)
    case 'Aprobar cotizaciones':
      return canApproveQuotes(rolePermissions, pseudoUser)
    case 'Enviar cotizaciones':
      return canSendQuotes(rolePermissions, pseudoUser)
    case 'Consultar inventarios':
      return canConsultInventory(rolePermissions, pseudoUser)
    case 'Alta y edición clientes':
      return canManageClients(rolePermissions, pseudoUser)
    case 'Importar Excel clientes':
      return canImportClients(rolePermissions, pseudoUser)
    case 'Ver reportes y analítica':
      return canViewReports(rolePermissions, pseudoUser)
    case 'Gestión de usuarios':
      return canManageUsers(rolePermissions, pseudoUser)
    case 'Config. integraciones':
      return canConfigIntegrations(rolePermissions, pseudoUser)
    default:
      return false
  }
}

/** Matriz rol × capacidad de negocio para la pantalla de configuración. */
export function getRoleCapabilityMatrix(
  rolePermissions: RolePermissionMap,
): Record<UserRole, Record<CapabilityId, boolean>> {
  const matrix = {} as Record<UserRole, Record<CapabilityId, boolean>>

  for (const role of CAPABILITY_ROLES) {
    matrix[role] = {} as Record<CapabilityId, boolean>
    for (const capability of CAPABILITY_LABELS) {
      matrix[role][capability] = roleHasCapability(rolePermissions, role, capability)
    }
  }

  return matrix
}
