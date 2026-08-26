import { useCallback, useEffect, useRef, useState } from 'react'
import { Bell } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/hooks/useAuth'
import { usePermission } from '@/hooks/usePermission'
import {
  listNotifications,
  markNotificationRead,
} from '@/lib/notifications-api'
import { formatDateTime } from '@/lib/format'
import type { SalesNotificationItem } from '@/types'

function canUseInbox(role: string | undefined): boolean {
  return role === 'ventas' || role === 'gerente_compras' || role === 'administrador'
}

export function NotificationsBell() {
  const { user } = useAuth()
  const { can } = usePermission()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<SalesNotificationItem[]>([])
  const [unreadCount, setUnreadCount] = useState(0)
  const [loading, setLoading] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)

  const enabled = Boolean(user && can('cotizaciones', 'view') && canUseInbox(user.role))

  const refresh = useCallback(async () => {
    if (!enabled) return
    setLoading(true)
    try {
      const result = await listNotifications()
      setItems(result.data)
      setUnreadCount(result.unreadCount)
    } catch {
      // Silencioso: la campana no debe romper el layout
    } finally {
      setLoading(false)
    }
  }, [enabled])

  useEffect(() => {
    void refresh()
    if (!enabled) return
    const timer = window.setInterval(() => void refresh(), 60_000)
    return () => window.clearInterval(timer)
  }, [enabled, refresh])

  useEffect(() => {
    if (!open) return
    const onDoc = (event: MouseEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false)
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

  const openQuote = async (item: SalesNotificationItem) => {
    if (!item.read) {
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
    const qs = new URLSearchParams()
    if (item.quoteId) qs.set('quote', item.quoteId)
    const suffix = qs.toString() ? `?${qs.toString()}` : ''
    navigate(`/recordatorios${suffix}`)
  }

  return (
    <div className="relative" ref={rootRef}>
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className="relative"
        aria-label={unreadCount > 0 ? `Recordatorios (${unreadCount} sin leer)` : 'Recordatorios'}
        aria-expanded={open}
        onClick={() => {
          setOpen((v) => !v)
          if (!open) void refresh()
        }}
      >
        <Bell className="h-4 w-4" />
        {unreadCount > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-bold text-white">
            {unreadCount > 9 ? '9+' : unreadCount}
          </span>
        )}
      </Button>
      {open && (
        <div
          className="absolute right-0 z-40 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl"
          role="menu"
        >
          <div className="border-b border-slate-100 px-3 py-2">
            <p className="text-sm font-semibold text-slate-900">Recordatorios</p>
            <p className="text-xs text-slate-500">
              {loading ? 'Actualizando…' : `${unreadCount} sin leer`}
            </p>
          </div>
          <ul className="max-h-80 overflow-y-auto">
            {items.length === 0 && (
              <li className="px-3 py-6 text-center text-sm text-slate-500">Sin recordatorios</li>
            )}
            {items.map((item) => (
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
          </ul>
        </div>
      )}
    </div>
  )
}
