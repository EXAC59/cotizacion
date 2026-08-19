import { DEMO_ACCOUNTS } from '@/data/demo-users'
import type { StoredUser } from '@/types/rbac'
import type { RolePermissionMap, ModuleId, PermissionAction } from '@/types/rbac'
import { MODULE_ACTIONS, MODULE_IDS } from '@/types/rbac'

function allActions(module: ModuleId): PermissionAction[] {
  return [...MODULE_ACTIONS[module]]
}

function fullAccess(): Record<ModuleId, PermissionAction[]> {
  const row = {} as Record<ModuleId, PermissionAction[]>
  for (const m of MODULE_IDS) {
    row[m] = allActions(m)
  }
  return row
}

export const DEFAULT_ROLE_PERMISSIONS: RolePermissionMap = {
  administrador: fullAccess(),
  gerente_compras: {
    dashboard: ['view'],
    solicitudes: ['view', 'create', 'edit'],
    cotizaciones: ['view', 'create', 'edit', 'send', 'approve', 'edit_margin'],
    clientes: ['view', 'create', 'edit', 'import'],
    mayoristas: ['view'],
    reportes: ['view'],
    configuracion: ['view'],
    admin: [],
  },
  ventas: {
    dashboard: [],
    solicitudes: ['view', 'create', 'edit'],
    cotizaciones: ['view', 'create', 'edit', 'send'],
    clientes: ['view', 'create', 'edit'],
    mayoristas: [],
    reportes: [],
    configuracion: ['view'],
    admin: [],
  },
}

export function seedUsers(): StoredUser[] {
  return DEMO_ACCOUNTS.map((a) => ({
    id: a.id,
    name: a.name,
    email: a.email,
    folioCode: a.folioCode,
    role: a.role,
    password: a.password,
    active: true,
    createdAt: '2026-01-01T00:00:00Z',
  }))
}
