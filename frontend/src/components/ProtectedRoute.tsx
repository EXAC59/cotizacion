import { Navigate, Outlet } from 'react-router-dom'
import { LoadingState } from '@/components/ui/LoadingState'
import { useAuth } from '@/hooks/useAuth'

export function ProtectedRoute() {
  const { isAuthenticated, authLoading } = useAuth()

  if (authLoading) {
    return (
      <div className="flex min-h-svh items-center justify-center bg-slate-50">
        <LoadingState label="Verificando sesión…" variant="page" />
      </div>
    )
  }

  if (!isAuthenticated) return <Navigate to="/login" replace />
  return <Outlet />
}
