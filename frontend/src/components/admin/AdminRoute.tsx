import { Link, Navigate, Outlet } from 'react-router-dom'
import { usePermission } from '@/hooks/usePermission'

export function AdminRoute() {
  const { canModule, isAdmin, user } = usePermission()

  if (!user) {
    return <Navigate to="/login" replace />
  }

  if (!isAdmin && !canModule('admin')) {
    return (
      <div className="mx-auto max-w-lg rounded-xl border border-amber-200 bg-amber-50 p-6 text-center">
        <p className="font-medium text-amber-900">Sin permiso para administración</p>
        <p className="mt-2 text-sm text-amber-800">
          Tu rol no incluye el módulo de administración. Cierra sesión e ingresa como administrador.
        </p>
        <Link
          to="/login/admin"
          className="mt-4 inline-block text-sm font-medium text-indigo-600 underline"
        >
          Entrar como administrador
        </Link>
      </div>
    )
  }

  return <Outlet />
}
