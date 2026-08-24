import type { User } from '@/types'
import type { ModuleId } from '@/types/rbac'
import { canAccessModule, canAccessDashboard } from '@/lib/permissions'
import type { RolePermissionMap } from '@/types/rbac'
import {
  BarChart3,
  Bell,
  Building2,
  FileText,
  LayoutDashboard,
  Package,
  Settings,
  Shield,
  Upload,
} from 'lucide-react'

export const MODULE_ROUTES: {
  module: ModuleId
  path: string
  label: string
  icon: typeof LayoutDashboard
  end?: boolean
}[] = [
  { module: 'dashboard', path: '/dashboard', label: 'Dashboard', icon: LayoutDashboard, end: true },
  { module: 'solicitudes', path: '/solicitudes', label: 'Solicitudes', icon: Upload },
  { module: 'cotizaciones', path: '/cotizaciones', label: 'Cotizaciones', icon: FileText },
  { module: 'cotizaciones', path: '/recordatorios-ventas', label: 'Recordatorios ventas', icon: Bell, end: true },
  { module: 'clientes', path: '/clientes', label: 'Clientes', icon: Building2 },
  { module: 'mayoristas', path: '/mayoristas', label: 'Mayoristas', icon: Package },
  { module: 'reportes', path: '/reportes', label: 'Reportes', icon: BarChart3 },
  { module: 'configuracion', path: '/configuracion', label: 'Configuración', icon: Settings },
  { module: 'admin', path: '/admin', label: 'Administración', icon: Shield },
]

/** Primera ruta accesible según permisos del usuario (p. ej. tras login). */
export function getDefaultRoute(
  rolePermissions: RolePermissionMap,
  user: User | null,
): string {
  if (!user) return '/login'

  for (const { module, path } of MODULE_ROUTES) {
    const allowed =
      module === 'dashboard'
        ? canAccessDashboard(rolePermissions, user)
        : canAccessModule(rolePermissions, user, module)
    if (allowed) {
      return path
    }
  }

  return '/login'
}

export function modulePath(module: ModuleId): string {
  return MODULE_ROUTES.find((item) => item.module === module)?.path ?? '/login'
}
