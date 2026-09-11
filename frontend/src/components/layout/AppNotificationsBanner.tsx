import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { ArrowRight, Bell } from 'lucide-react'
import { goToDashboardAlerts } from '@/lib/dashboard-alerts-nav'
import { DASHBOARD_SECTIONS } from '@/lib/notification-focus'
import { usePermission } from '@/hooks/usePermission'
import { fetchDashboard } from '@/lib/dashboard-api'

export function AppNotificationsBanner() {
  const { can, isAdmin } = usePermission()
  const canViewDashboard = can('dashboard', 'view')
  const location = useLocation()
  const navigate = useNavigate()
  const [alertCount, setAlertCount] = useState(0)
  const [focusTargetId, setFocusTargetId] = useState('alertas')
  const [summary, setSummary] = useState('')

  useEffect(() => {
    if (!canViewDashboard) return

    let cancelled = false

    fetchDashboard()
      .then((data) => {
        if (cancelled) return
        const alerts = data.alerts
        const unanswered = alerts.unansweredQuotes.length
        const stuck = isAdmin ? (alerts.stuckProcessingRequests?.length ?? 0) : 0
        const total = unanswered + stuck

        setAlertCount(total)

        let targetId = 'alertas'
        if (unanswered > 0) targetId = 'cotizaciones-sin-avance'
        else if (stuck > 0) targetId = 'lecturas-atascadas'
        setFocusTargetId(targetId)

        const parts: string[] = []
        if (unanswered)
          parts.push(
            `${unanswered} cotización${unanswered === 1 ? '' : 'es'} sin avance`,
          )
        if (stuck)
          parts.push(`${stuck} lectura${stuck === 1 ? '' : 's'} atascada${stuck === 1 ? '' : 's'}`)
        setSummary(parts.join(' · '))
      })
      .catch(() => {})

    return () => {
      cancelled = true
    }
  }, [canViewDashboard, isAdmin])

  if (!canViewDashboard || alertCount === 0) {
    return null
  }

  const goToAlertas = () => {
    goToDashboardAlerts(navigate, location, focusTargetId)
  }

  const focusLabel = DASHBOARD_SECTIONS[focusTargetId]?.label ?? 'Alertas'

  return (
    <div className="sticky top-0 z-20 border-b border-amber-200/80 bg-amber-50/90 px-4 py-2.5 backdrop-blur-md sm:px-6">
      <div className="mx-auto flex w-full max-w-screen-2xl items-center justify-between gap-4">
        <div className="flex min-w-0 items-center gap-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-amber-700 shadow-sm ring-1 ring-amber-200">
            <Bell className="h-4 w-4" aria-hidden="true" />
          </span>
          <div className="min-w-0">
            <p className="text-sm font-semibold leading-5 text-amber-950">
              {alertCount} alerta{alertCount === 1 ? '' : 's'} pendiente
              {alertCount === 1 ? '' : 's'}
            </p>
            <p className="truncate text-xs leading-4 text-amber-800 sm:text-sm">{summary}</p>
          </div>
        </div>
        <button
          type="button"
          onClick={goToAlertas}
          className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-amber-200 bg-white px-3 py-1.5 text-sm font-semibold text-indigo-700 shadow-sm transition hover:border-amber-300 hover:bg-amber-100/70"
          title={`Ir a ${focusLabel}`}
        >
          <span className="hidden sm:inline">Ver alertas</span>
          <span className="sm:hidden">Ver</span>
          <ArrowRight className="h-4 w-4" aria-hidden="true" />
        </button>
      </div>
    </div>
  )
}
