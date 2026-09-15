import { useEffect, useState } from 'react'
import { Bell } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { usePermission } from '@/hooks/usePermission'
import { fetchDashboard } from '@/lib/dashboard-api'
import { listNotifications } from '@/lib/notifications-api'
import {
  NOTIFICATIONS_BELL_ID,
  OPEN_NOTIFICATIONS_EVENT,
} from '@/components/layout/NotificationsBell'

function canUseInbox(role: string | undefined): boolean {
  return role === 'ventas' || role === 'gerente_compras' || role === 'administrador'
}

export function AppNotificationsBanner() {
  const { user } = useAuth()
  const { can, isAdmin } = usePermission()
  const canViewDashboard = can('dashboard', 'view')
  const inboxEnabled = Boolean(user && can('cotizaciones', 'view') && canUseInbox(user.role))
  const [pendingCount, setPendingCount] = useState(0)
  const [summary, setSummary] = useState('')

  useEffect(() => {
    if (!canViewDashboard && !inboxEnabled) return

    let cancelled = false

    const load = async () => {
      try {
        const parts: string[] = []
        let total = 0

        if (inboxEnabled) {
          const inbox = await listNotifications().catch(() => ({ data: [], unreadCount: 0 }))
          if (cancelled) return
          const unread = inbox.unreadCount
          if (unread > 0) {
            total += unread
            parts.push(
              `${unread} notificación${unread === 1 ? '' : 'es'} / recordatorio${unread === 1 ? '' : 's'}`,
            )
          }
        }

        if (canViewDashboard) {
          const data = await fetchDashboard()
          if (cancelled) return
          const unanswered = data.alerts.unansweredQuotes.length
          const stuck = isAdmin ? (data.alerts.stuckProcessingRequests?.length ?? 0) : 0
          if (unanswered > 0) {
            total += unanswered
            parts.push(
              `${unanswered} cotización${unanswered === 1 ? '' : 'es'} sin avance`,
            )
          }
          if (stuck > 0) {
            total += stuck
            parts.push(
              `${stuck} lectura${stuck === 1 ? '' : 's'} atascada${stuck === 1 ? '' : 's'}`,
            )
          }
        }

        if (cancelled) return
        setPendingCount(total)
        setSummary(parts.join(' · '))
      } catch {
        // La barra no debe romper el layout
      }
    }

    void load()
    return () => {
      cancelled = true
    }
  }, [canViewDashboard, inboxEnabled, isAdmin])

  if ((!canViewDashboard && !inboxEnabled) || pendingCount === 0) {
    return null
  }

  const openBell = () => {
    const bell = document.getElementById(NOTIFICATIONS_BELL_ID)
    bell?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
    window.dispatchEvent(new Event(OPEN_NOTIFICATIONS_EVENT))
  }

  return (
    <div className="sticky top-0 z-20 border-b border-amber-200/80 bg-amber-50/90 px-4 py-2.5 backdrop-blur-md sm:px-6">
      <div className="mx-auto flex w-full max-w-screen-2xl items-center justify-between gap-3 sm:gap-4">
        <div className="flex min-w-0 items-center gap-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-amber-700 shadow-sm ring-1 ring-amber-200">
            <Bell className="h-4 w-4" aria-hidden="true" />
          </span>
          <div className="min-w-0">
            <p className="text-sm font-semibold leading-5 text-amber-950">
              Revisa el icono de notificaciones y recordatorios
            </p>
            <p className="truncate text-xs leading-4 text-amber-800 sm:text-sm">
              {summary || `${pendingCount} pendiente${pendingCount === 1 ? '' : 's'}`}
              <span className="hidden sm:inline"> · está arriba a la derecha</span>
            </p>
          </div>
        </div>
        <button
          type="button"
          onClick={openBell}
          className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-amber-200 bg-white px-3 py-1.5 text-sm font-semibold text-indigo-700 shadow-sm transition hover:border-amber-300 hover:bg-amber-100/70"
          title="Abrir notificaciones y recordatorios"
        >
          <Bell className="h-4 w-4" aria-hidden="true" />
          <span className="hidden sm:inline">Abrir icono</span>
          <span className="sm:hidden">Abrir</span>
        </button>
      </div>
    </div>
  )
}
