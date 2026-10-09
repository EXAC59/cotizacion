import { useEffect, useState } from 'react'
import { usePermission } from '@/hooks/usePermission'
import { apiFetch } from '@/lib/api-fetch'
import { getApiBase } from '@/lib/app-paths'
import { MESSAGING_UNREAD_CHANGED } from '@/lib/notifications-events'

export { MESSAGING_UNREAD_CHANGED } from '@/lib/notifications-events'

export function useMessagingUnreadCount() {
  const { user, canModule } = usePermission()
  const [count, setCount] = useState(0)
  const enabled = import.meta.env.VITE_MENSAJERIA_LIVE === 'true'
    && Boolean(user?.active && ['ventas', 'gerente_compras', 'administrador'].includes(user.role))
    && canModule('cotizaciones')

  useEffect(() => {
    let cancelled = false
    let requestId = 0
    setCount(0)
    if (!enabled) return
    const refresh = async () => {
      if (document.hidden) return
      const currentRequest = ++requestId
      try {
        const response = await apiFetch(`${getApiBase()}/mensajeria/pendientes`)
        if (!response.ok) return
        const data = await response.json() as { unreadCount: number; pendingCount?: number }
        if (!cancelled && currentRequest === requestId) setCount(data.pendingCount ?? data.unreadCount)
      } catch { /* Retain the last count when the connection is interrupted. */ }
    }
    void refresh()
    const timer = window.setInterval(() => void refresh(), 15000)
    window.addEventListener('focus', refresh)
    window.addEventListener(MESSAGING_UNREAD_CHANGED, refresh)
    document.addEventListener('visibilitychange', refresh)
    return () => {
      cancelled = true
      window.clearInterval(timer)
      window.removeEventListener('focus', refresh)
      window.removeEventListener(MESSAGING_UNREAD_CHANGED, refresh)
      document.removeEventListener('visibilitychange', refresh)
    }
  }, [enabled, user?.id])

  return { count, enabled, updateCount: setCount }
}
