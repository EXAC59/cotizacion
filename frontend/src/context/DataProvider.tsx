import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { DataContext } from '@/context/data-context'
import { buildDashboardStats } from '@/data/mock'
import { computeLowStockAlerts } from '@/lib/inventory-alerts'
import { loadDemoStore, saveDemoStore } from '@/lib/demo-storage'
import type { Quote, QuoteRequest, RequestPricingLine } from '@/types'

export function DataProvider({ children }: { children: ReactNode }) {
  const [store, setStore] = useState(loadDemoStore)
  const { quotes, requests, wholesalers, inventory, settings } = store

  useEffect(() => {
    saveDemoStore(store)
  }, [store])

  const lowStockItems = useMemo(
    () => computeLowStockAlerts(inventory, settings),
    [inventory, settings],
  )

  const activeWholesalers = useMemo(
    () => wholesalers.filter((w) => w.active),
    [wholesalers],
  )

  const dashboard = useMemo(
    () => buildDashboardStats(quotes, requests, inventory, settings),
    [quotes, requests, inventory, settings],
  )

  const getQuote = useCallback((id: string) => quotes.find((q) => q.id === id), [quotes])
  const getRequest = useCallback((id: string) => requests.find((r) => r.id === id), [requests])

  const saveQuote = useCallback((quote: Quote) => {
    setStore((prev) => {
      const idx = prev.quotes.findIndex((q) => q.id === quote.id)
      const existing = idx >= 0 ? prev.quotes[idx] : undefined

      // Resúmenes del listado API traen lines:[] — no pisar partidas ya guardadas
      const merged: Quote =
        existing &&
        quote.lines.length === 0 &&
        existing.lines.length > 0
          ? { ...existing, ...quote, lines: existing.lines }
          : quote

      let nextQuotes =
        idx >= 0
          ? prev.quotes.map((q, i) => (i === idx ? merged : q))
          : [...prev.quotes, merged]

      // Tras guardar en API el id pasa de temporal (q*) a UUID — quitar duplicado por folio
      if (idx < 0 && merged.folio) {
        nextQuotes = nextQuotes.filter(
          (q) => q.id === merged.id || q.folio !== merged.folio,
        )
      }

      return { ...prev, quotes: nextQuotes }
    })
  }, [])

  const saveRequest = useCallback((request: QuoteRequest) => {
    setStore((prev) => {
      const idx = prev.requests.findIndex((r) => r.id === request.id)
      const nextRequests =
        idx >= 0
          ? prev.requests.map((r, i) => (i === idx ? request : r))
          : [...prev.requests, request]
      return { ...prev, requests: nextRequests }
    })
  }, [])

  const toggleWholesaler = useCallback((id: string, active: boolean) => {
    setStore((prev) => ({
      ...prev,
      wholesalers: prev.wholesalers.map((w) => (w.id === id ? { ...w, active } : w)),
    }))
  }, [])

  const setMinStockAlert = useCallback((value: number) => {
    setStore((prev) => ({
      ...prev,
      settings: { ...prev.settings, minStockAlert: Math.max(0, value) },
    }))
  }, [])

  const uploadRequestPricing = useCallback((requestId: string, lines: RequestPricingLine[]) => {
    setStore((prev) => ({
      ...prev,
      requests: prev.requests.map((r) =>
        r.id === requestId
          ? {
              ...r,
              status: 'precios_listos',
              pricingLines: lines,
              pricingUploadedAt: new Date().toISOString(),
            }
          : r,
      ),
    }))
  }, [])

  const value = useMemo(
    () => ({
      clients: [],
      quotes,
      requests,
      wholesalers,
      inventory,
      settings,
      dashboard,
      lowStockItems,
      activeWholesalers,
      getClient: () => undefined,
      getQuote,
      getRequest,
      saveClient: () => {},
      deleteClient: () => {},
      saveQuote,
      saveRequest,
      toggleWholesaler,
      setMinStockAlert,
      uploadRequestPricing,
    }),
    [
      quotes,
      requests,
      wholesalers,
      inventory,
      settings,
      dashboard,
      lowStockItems,
      activeWholesalers,
      getQuote,
      getRequest,
      saveQuote,
      saveRequest,
      toggleWholesaler,
      setMinStockAlert,
      uploadRequestPricing,
    ],
  )

  return <DataContext.Provider value={value}>{children}</DataContext.Provider>
}
