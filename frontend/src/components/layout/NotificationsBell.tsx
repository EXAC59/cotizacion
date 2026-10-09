import { useCallback, useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Bell } from 'lucide-react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/hooks/useAuth'
import { usePermission } from '@/hooks/usePermission'
import { MESSAGING_UNREAD_CHANGED } from '@/hooks/useMessagingUnreadCount'
import {
  listNotifications,
  markNotificationRead,
  type NotificationOperationalAlert,
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
import { formatDateTime } from '@/lib/format'
import type { SalesNotificationItem } from '@/types'

export const OPEN_NOTIFICATIONS_EVENT = 'cotizacion:open-notifications'
export const NOTIFICATIONS_BELL_ID = 'app-notifications-bell'

function canUseInbox(role: string | undefined): boolean {
  return role === 'ventas' || role === 'gerente_compras' || role === 'administrador'
}

type OperationalAlert = NotificationOperationalAlert

export function NotificationsBell({ sharedPendingCount, onPendingCountChange }: {
  sharedPendingCount?: number; onPendingCountChange?: (count: number) => void
}) {
  const { user } = useAuth()
  const { can } = usePermission()
  const navigate = useNavigate()
  const location = useLocation()
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<SalesNotificationItem[]>([])
  const [operational, setOperational] = useState<OperationalAlert[]>([])
  const [purchaseRequests, setPurchaseRequests] = useState<OperationalAlert[]>([])
  const [unreadCount, setUnreadCount] = useState(0)
  const [serverBadgeCount, setBadgeCount] = useState(0)
  const [loading, setLoading] = useState(false)
  const [bannerPulse, setBannerPulse] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const refreshRequestRef = useRef({ id: 0 })
  const [panelStyle, setPanelStyle] = useState<{ top: number; left: number; width: number } | null>(
    null,
  )

  const enabled = Boolean(user && can('cotizaciones', 'view') && canUseInbox(user.role))
  const canViewDashboard = can('dashboard', 'view')

  const refresh = useCallback(async () => {
    if (!enabled || !user?.id || document.hidden) return
    const requests = refreshRequestRef.current
    const requestId = ++requests.id
    setLoading(true)
    try {
      const inbox = await listNotifications()
      if (requestId !== requests.id) return
      setItems(inbox.data)
      setUnreadCount(inbox.unreadCount)
      setBadgeCount(inbox.pendingCount)
      onPendingCountChange?.(inbox.pendingCount)
      setOperational(inbox.operational)
      setPurchaseRequests(inbox.purchaseRequests)
    } catch {
      // Silencioso: la campana no debe romper el layout
    } finally {
      if (requestId === requests.id) setLoading(false)
    }
  }, [enabled, user?.id, onPendingCountChange])

  useEffect(() => {
    void refresh()
    if (!enabled) return
    const requests = refreshRequestRef.current
    const timer = window.setInterval(() => void refresh(), 15_000)
    const onMessagesRead = () => void refresh()
    window.addEventListener(MESSAGING_UNREAD_CHANGED, onMessagesRead)
    window.addEventListener('focus', onMessagesRead)
    document.addEventListener('visibilitychange', onMessagesRead)
    return () => {
      ++requests.id
      window.clearInterval(timer)
      window.removeEventListener(MESSAGING_UNREAD_CHANGED, onMessagesRead)
      window.removeEventListener('focus', onMessagesRead)
      document.removeEventListener('visibilitychange', onMessagesRead)
    }
  }, [enabled, refresh])

  useEffect(() => {
    if (!enabled) return
    const onOpenFromBanner = () => {
      setBannerPulse(true)
      setOpen(true)
      void refresh()
      window.setTimeout(() => setBannerPulse(false), 1600)
    }
    window.addEventListener(OPEN_NOTIFICATIONS_EVENT, onOpenFromBanner)
    return () => window.removeEventListener(OPEN_NOTIFICATIONS_EVENT, onOpenFromBanner)
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

  const goToOperationalAlerts = () => {
    setOpen(false)
    goToDashboardAlerts(navigate, location, 'cotizaciones-sin-avance')
  }

  const openOperationalAlert = (alert: OperationalAlert) => {
    setOpen(false)
    if (import.meta.env.VITE_MENSAJERIA_LIVE === 'true') {
      navigate({ pathname: '/mensajeria', search: `?quote=${encodeURIComponent(alert.quoteId)}` })
      return
    }
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
        void refresh()
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

    if (item.quoteId && import.meta.env.VITE_MENSAJERIA_LIVE === 'true') {
      navigate({ pathname: '/mensajeria', search: `?quote=${encodeURIComponent(item.quoteId)}` })
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
        recordatorioQuoteFocus(item.quoteId, item.folio ?? 'Cotización', item.reasonLabel),
      )
      return
    }

    navigate(import.meta.env.VITE_MENSAJERIA_LIVE === 'true' ? '/mensajeria?filter=reminders' : '/recordatorios')
  }

  const pipelineQuoteItems = items.filter((item) => item.kind === 'pipeline')
  const pipelineRequestItems = items.filter((item) => item.kind === 'pipeline_request')
  const inboxItems = items.filter(
    (item) => item.kind !== 'pipeline' && item.kind !== 'pipeline_request',
  )
  const purchaseRequestFallback = purchaseRequests
  const pipelineTotal = pipelineQuoteItems.length + pipelineRequestItems.length
  const badgeCount = sharedPendingCount ?? serverBadgeCount

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
                    : `${unreadCount} avisos sin leer · ${purchaseRequestFallback.length} solicitudes nuevas · ${operational.length} pendientes por atender`}
              </p>
            </div>
            <ul className="max-h-80 overflow-y-auto">
              {inboxItems.length > 0 && (
                <li className="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                  Avisos de cotizaciones
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
              {purchaseRequestFallback.length > 0 && (
                <>
                  <li className="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    Solicitudes nuevas
                  </li>
                  {purchaseRequestFallback.map((alert) => (
                    <li key={alert.id}>
                      <button
                        type="button"
                        className="w-full bg-indigo-50/40 px-3 py-2.5 text-left text-sm hover:bg-amber-50/60"
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
              {operational.length > 0 && (
                <>
                  <li className="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    Pendientes por atender
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
              {items.length === 0 && purchaseRequestFallback.length === 0 && operational.length === 0 && (
                <li className="px-3 py-4 text-center text-sm text-slate-500">
                  Sin notificaciones ni recordatorios.
                  {user?.role === 'ventas' ? (
                    <Link
                      to={import.meta.env.VITE_MENSAJERIA_LIVE === 'true' ? '/mensajeria?filter=reminders' : '/recordatorios'}
                      className="mt-2 block font-medium text-indigo-600 hover:text-indigo-700"
                      onClick={() => setOpen(false)}
                    >
                      {import.meta.env.VITE_MENSAJERIA_LIVE === 'true' ? 'Ir a Mensajería' : 'Ir a Recordatorios'}
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
    <div className="relative" ref={rootRef} id={NOTIFICATIONS_BELL_ID}>
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className={`relative h-12 w-12 shrink-0 rounded-xl p-0 ${
          bannerPulse ? 'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-100' : ''
        } ${badgeCount > 0 ? 'text-indigo-700' : 'text-slate-700'}`}
        aria-label={
          badgeCount > 0 ? `Notificaciones (${badgeCount})` : 'Notificaciones y recordatorios'
        }
        aria-expanded={open}
        title="Notificaciones y recordatorios"
        onClick={() => {
          setOpen((v) => !v)
          if (!open) void refresh()
        }}
      >
        <span className="relative inline-flex h-8 w-8 items-center justify-center">
          <Bell className="h-7 w-7" strokeWidth={2.35} aria-hidden="true" />
          {badgeCount > 0 && (
            <span className="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1 text-[11px] font-bold leading-none whitespace-nowrap text-white shadow-sm ring-2 ring-white">
              {badgeCount.toLocaleString('es-MX')}
            </span>
          )}
        </span>
      </Button>
      {panel}
    </div>
  )
}
