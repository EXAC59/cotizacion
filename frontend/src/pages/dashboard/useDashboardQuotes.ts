import { useEffect, useState } from 'react'
import { fetchDashboardWorkspace, type DashboardWorkspaceData } from '@/lib/dashboard-workspace-api'

export function useDashboardQuotes(query: string) {
  const [data, setData] = useState<DashboardWorkspaceData | null>(null)
  const [loadedQuery, setLoadedQuery] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [retryKey, setRetryKey] = useState(0)

  useEffect(() => {
    let active = true
    let controller: AbortController | null = null
    const refresh = () => {
      controller?.abort()
      controller = new AbortController()
      fetchDashboardWorkspace(new URLSearchParams(query), controller.signal)
        .then((next) => {
          if (!active) return
          setData(next)
          setError(null)
          setLoadedQuery(query)
        })
        .catch((err: unknown) => {
          if (!active || (err instanceof DOMException && err.name === 'AbortError')) return
          setError(err instanceof Error ? err.message : 'No se pudieron cargar las cotizaciones.')
          setLoadedQuery(query)
        })
    }
    const visibleRefresh = () => { if (!document.hidden) refresh() }
    refresh()
    const timer = window.setInterval(visibleRefresh, 15_000)
    window.addEventListener('focus', visibleRefresh)
    document.addEventListener('visibilitychange', visibleRefresh)
    window.addEventListener('cotizacion:data-changed', visibleRefresh)
    return () => {
      active = false
      controller?.abort()
      window.clearInterval(timer)
      window.removeEventListener('focus', visibleRefresh)
      document.removeEventListener('visibilitychange', visibleRefresh)
      window.removeEventListener('cotizacion:data-changed', visibleRefresh)
    }
  }, [query, retryKey])

  return { data, loading: loadedQuery !== query, error, retry: () => setRetryKey((key) => key + 1) }
}
