import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'
import { usePermission } from '@/hooks/usePermission'
import { LiveMessaging } from '../../../pruebas/mensajeria/LiveMessaging'
import '../../../pruebas/mensajeria/messaging.css'
import '../../../pruebas/mensajeria/workflow.css'

export function MessagingEntryPage() {
  const { user } = useAuth()
  const { can } = usePermission()

  if (!user) return <Navigate to="/login" replace />
  if (!['ventas', 'gerente_compras', 'administrador'].includes(user.role)) return <Navigate to="/dashboard" replace />

  return <LiveMessaging user={user} canEditFollowUp={can('cotizaciones', 'edit')} />
}

export function MessagingReminderRedirect() {
  const location = useLocation()
  const params = new URLSearchParams(location.search)
  params.set(params.has('quote') ? 'reminder' : 'filter', params.has('quote') ? '1' : 'reminders')
  return <Navigate to={{ pathname: '/mensajeria', search: params.toString() }} replace />
}
