import { useCallback, useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Bell } from 'lucide-react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/hooks/useAuth'
import { usePermission } from '@/hooks/usePermission'
import {
  listNotifications,
  markNotificationRead,
} from '@/lib/notifications-api'
import { goToDashboardAlerts } from '@/lib/dashboard-alerts-nav'
import {
  DASHBOARD_SECTIONS,
  navigateWithNotificationFocus,
  quoteDashboardFocus,
  quoteDetailFocus,
  recordatorioQuoteFocus,
  requestDetailFocus,
} from '@/lib/notification-focus'
import { fetchDashboard } from '@/lib/dashboard-api'
import { formatDateTime } from '@/lib/format'
import type { DashboardAlertQuote, SalesNotificationItem } from '@/types'

function canUseInbox(role: string | undefined): boolean {
  return role === 'ventas' || role === 'gerente_compras' || role === 'administrador'
}

type OperationalAlert = {
  id: string
  quoteId: string
  folio: string
  label: string
  detail: string
}

function mapOperationalAlerts(unanswered: DashboardAlertQuote[]): OperationalAlert[] {
  return unanswered.map((quote) => ({
    id: `unanswered-${quote.id}`,
    quoteId: quote.id,
    folio: quote.folio,
    label: 'Sin avance',
    detail: `${quote.clientName || 'Sin cliente'} · ${quote.daysWaiting ?? 0} días sin actividad`,
  }))
}

export function NotificationsBell() {
  const { user } = useAuth()
  const { can } = usePermission()
  const navigate = useNavigate()
  const location = useLocation()
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<SalesNotificationItem[]>([])
  const [operational, setOperational] = useState<OperationalAlert[]>([])
  const [unreadCount, setUnreadCount] = useState(0)
  const [loading, setLoading] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const [panelStyle, setPanelStyle] = useState<{ top: number; left: number; width: number } | null>(
    null,
  )

  const enabled = Boolean(user && can('cotizaciones', 'view') && canUseInbox(user.role))
  const canViewDashboard = can('dashboard', 'view')
  const showOperational = canViewDashboard && user?.role !== 'ventas'

  const refresh = useCallback(async () => {
    if (!enabled) return
    setLoading(true)
    try {
      const tasks: [Promise<{ data: SalesNotificationItem[]; unreadCount: number }>, Promise<OperationalAlert[]>] = [
        listNotifications(),
        showOperational
          ? fetchDashboard()
              .then((data) => mapOperationalAlerts(data.alerts.unansweredQuotes))
              .catch(() => [] as OperationalAlert[])
          : Promise.resolve([] as OperationalAlert[]),
      ]
      const [inbox, operationalAlerts] = await Promise.all(tasks)
      setItems(inbox.data)
      setUnreadCount(inbox.unreadCount)
      setOperational(operationalAlerts)
    } catch {
      // Silencioso: la campana no debe romper el layout
    } finally {
      setLoading(false)
    }
  }, [enabled, showOperational])

  useEffect(() => {
    void refresh()
    if (!enabled) return
    const timer = window.setInterval(() => void refresh(), 60_000)
    return () => window.clearInterval(timer)
  }, [enabled, refresh])

  useEffect(() => {
    if (!open) return
    const updatePosition = () => {
      const anchor = rootRef.current
      if (!anchor) return
      const rect = anchor.getBoundingClientRect()
      const width = Math.min(352, window.innerWidth - 16)
      setPanelStyle({
        top: rect.bottom + 8,
        left: Math.max(8, rect.right - width),
        width,
      })
    }
    updatePosition()
    window.addEventListener('resize', updatePosition)
    window.addEventListener('scroll', updatePosition, true)
    return () => {
      window.removeEventListener('resize', updatePosition)
      window.removeEventListener('scroll', updatePosition, true)
    }
  }, [open])

  useEffect(() => {
    if (!open) return
    const onDoc = (event: MouseEvent) => {
      const target = event.target as Node
      if (rootRef.current?.contains(target) || panelRef.current?.contains(target)) return
      setOpen(false)
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    window.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      window.removeEventListener('keydown', onKey)
    }
  }, [open])

  if (!enabled) return null

  const badgeCount = unreadCount + operational.length

  const goToOperationalAlerts = () => {
    setOpen(false)
    goToDashboardAlerts(navigate, location, 'cotizaciones-sin-avance')
  }

  const openOperationalAlert = (alert: OperationalAlert) => {
    setOpen(false)
    navigateWithNotificationFocus(
      navigate,
      location,
      { pathname: '/dashboard', hash: 'alertas' },
      quoteDashboardFocus(alert.quoteId, alert.folio, alert.label),
    )
  }

  const openQuote = async (item: SalesNotificationItem) => {
    if (!item.read && item.kind !== 'pipeline' && item.kind !== 'pipeline_request') {
      try {
        await markNotificationRead(item.id)
        setItems((prev) =>
          prev.map((n) => (n.id === item.id ? { ...n, read: true, readAt: new Date().toISOString() } : n)),
        )
        setUnreadCount((c) => Math.max(0, c - 1))
      } catch {
        // Continuar al detalle aunque falle marcar leída
      }
    }
    setOpen(false)

    if (item.requestId && item.kind === 'pipeline_request') {
      navigateWithNotificationFocus(
        navigate,
        location,
        { pathname: `/solicitudes/${item.requestId}` },
        requestDetailFocus(item.folio ?? 'Solicitud', item.reasonLabel),
      )
      return
    }

    if (item.quoteId && item.kind === 'pipeline') {
      navigateWithNotificationFocus(
        navigate,
        location,
        { pathname: `/cotizaciones/${item.quoteId}` },
        quoteDetailFocus(item.folio ?? 'Cotización', item.reasonLabel),
      )
      return
    }

    if (item.quoteId) {
      navigateWithNotificationFocus(
        navigate,
        location,
        { pathname: '/recordatorios', search: `?quote=${item.quoteId}` },
        recordatorioQuoteFocus(
          item.quoteId,
          item.folio ?? 'Cotización',
          item.reasonLabel,
        ),
      )
      return
    }

    navigate('/recordatorios')
  }

  const pipelineQuoteItems = items.filter((item) => item.kind === 'pipeline')
  const pipelineRequestItems = items.filter((item) => item.kind === 'pipeline_request')
  const inboxItems = items.filter(
    (item) => item.kind !== 'pipeline' && item.kind !== 'pipeline_request',
  )
  const pipelineTotal = pipelineQuoteItems.length + pipelineRequestItems.length

  const panel =
    open && panelStyle && typeof document !== 'undefined'
      ? createPortal(
          <div
            ref={panelRef}
            className="fixed z-[60] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl"
            style={{ top: panelStyle.top, left: panelStyle.left, width: panelStyle.width }}
            role="menu"
          >
            <div className="border-b border-slate-100 px-3 py-2">
              <p className="text-sm font-semibold text-slate-900">Notificaciones y recordatorios</p>
              <p className="text-xs text-slate-500">
                {loading
                  ? 'Actualizando…'
                  : user?.role === 'ventas'
                    ? `${pipelineTotal} pendientes · ${inboxItems.filter((i) => !i.read).length} avisos`
                    : `${unreadCount} en bandeja · ${operational.length} operativas`}
              </p>
            </div>
            <ul className="max-h-80 overflow-y-auto">
              {operational.length > 0 && (
                <>
                  <li className="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    Operativas
                  </li>
                  {operational.map((alert) => (
                    <li key={alert.id}>
                      <button
                        type="button"
                        className="w-full px-3 py-2.5 text-left text-sm hover:bg-amber-50/60"
                        onClick={() => openOperationalAlert(alert)}
                      >
                        <span className="block font-medium text-slate-900">
                          {alert.folio} · {alert.label}
                        </span>
                        <span className="mt-0.5 block text-xs text-slate-600">{alert.detail}</span>
                      </button>
                    </li>
                  ))}
                </>
              )}
              {pipelineRequestItems.length > 0 && (
                <>
                  <li className="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    Tus solicitudes
                  </li>
                  {pipelineRequestItems.map((item) => (
                    <li key={item.id}>
                      <button
                        type="button"
                        className="w-full px-3 py-2.5 text-left text-sm hover:bg-amber-50/60 bg-indigo-50/40"
                        onClick={() => void openQuote(item)}
                      >
                        <span className="block font-medium text-slate-900">
                          {item.folio ?? 'Solicitud'} · {item.reasonLabel}
                        </span>
                        <span className="mt-0.5 block text-xs text-slate-600 line-clamp-2">
                          {item.message}
                        </span>
                      </button>
                    </li>
                  ))}
                </>
              )}
              {pipelineQuoteItems.length > 0 && (
                <>
                  <li className="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    Tus cotizaciones
                  </li>
                  {pipelineQuoteItems.map((item) => (
                    <li key={item.id}>
                      <button
                        type="button"
                        className="w-full px-3 py-2.5 text-left text-sm hover:bg-amber-50/60 bg-indigo-50/40"
                        onClick={() => void openQuote(item)}
                      >
                        <span className="block font-medium text-slate-900">
                          {item.folio ?? 'Cotización'} · {item.reasonLabel}
                        </span>
                        <span className="mt-0.5 block text-xs text-slate-600 line-clamp-2">
                          {item.message}
                        </span>
                      </button>
                    </li>
                  ))}
                </>
              )}
              {inboxItems.length > 0 && (
                <li className="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                  Bandeja
                </li>
              )}
              {inboxItems.map((item) => (
                <li key={item.id}>
                  <button
                    type="button"
                    className={`w-full px-3 py-2.5 text-left text-sm hover:bg-slate-50 ${
                      item.read ? 'opacity-70' : 'bg-indigo-50/40'
                    }`}
                    onClick={() => void openQuote(item)}
                  >
                    <span className="block font-medium text-slate-900">
                      {item.folio ?? 'Cotización'} · {item.reasonLabel}
                    </span>
                    <span className="mt-0.5 block text-xs text-slate-600 line-clamp-2">
                      {item.message}
                    </span>
                    <span className="mt-1 block text-[11px] text-slate-400">
                      {item.createdAt ? formatDateTime(item.createdAt) : ''}
                      {item.senderName ? ` · ${item.senderName}` : ''}
                    </span>
                  </button>
                </li>
              ))}
              {items.length === 0 && operational.length === 0 && (
                <li className="px-3 py-4 text-center text-sm text-slate-500">
                  Sin notificaciones ni recordatorios.
                  {user?.role === 'ventas' ? (
                    <Link
                      to="/recordatorios"
                      className="mt-2 block font-medium text-indigo-600 hover:text-indigo-700"
                      onClick={() => setOpen(false)}
                    >
                      Ir a Recordatorios
                    </Link>
                  ) : canViewDashboard ? (
                    <button
                      type="button"
                      className="mt-2 block w-full font-medium text-indigo-600 hover:text-indigo-700"
                      onClick={goToOperationalAlerts}
                    >
                      Ver {DASHBOARD_SECTIONS.alertas.label.toLowerCase()}
                    </button>
                  ) : null}
                </li>
              )}
            </ul>
          </div>,
          document.body,
        )
      : null

  return (
    <div className="relative" ref={rootRef}>
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className="relative"
        aria-label={badgeCount > 0 ? `Notificaciones (${badgeCount})` : 'Notificaciones y recordatorios'}
        aria-expanded={open}
        onClick={() => {
          setOpen((v) => !v)
          if (!open) void refresh()
        }}
      >
        <Bell className="h-4 w-4" />
        {badgeCount > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-bold text-white">
            {badgeCount > 9 ? '9+' : badgeCount}
          </span>
        )}
      </Button>
      {panel}
    </div>
  )
}
