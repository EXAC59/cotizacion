import { Navigate } from 'react-router-dom'
import type { ReactNode } from 'react'
import { usePermission } from '@/hooks/usePermission'
import { useRbac } from '@/hooks/useRbac'
import { canAccessDashboard } from '@/lib/permissions'
import { getDefaultRoute, modulePath } from '@/lib/module-routes'
import type { ModuleId } from '@/types/rbac'

export function ModuleRoute({ module, children }: { module: ModuleId; children: ReactNode }) {
  const { user, canModule } = usePermission()
  const { rolePermissions } = useRbac()

  const allowed =
    module === 'dashboard'
      ? canAccessDashboard(rolePermissions, user)
      : canModule(module)

  if (!allowed) {
    const fallback = getDefaultRoute(rolePermissions, user)
    if (fallback === modulePath(module)) {
      return (
        <div className="mx-auto max-w-lg rounded-xl border border-amber-200 bg-amber-50 p-6 text-center">
          <p className="font-medium text-amber-900">Sin permisos asignados</p>
          <p className="mt-2 text-sm text-amber-800">
            Tu rol no tiene acceso a ningún módulo. Contacta al administrador.
          </p>
        </div>
      )
    }

    return <Navigate to={fallback} replace />
  }

  return <>{children}</>
}
