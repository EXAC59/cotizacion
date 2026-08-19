import type {
  AppSettings,
  DashboardStats,
  InventoryCatalogItem,
  Quote,
  QuoteRequest,
  QuoteStatus,
  Wholesaler,
} from '@/types'
import { computeLowStockAlerts } from '@/lib/inventory-alerts'

export const DEFAULT_SETTINGS: AppSettings = {
  minStockAlert: 5,
  defaultMarginPercent: 30,
  taxPercent: 16,
  quoteValidityDays: 15,
  currencyCode: 'MXN',
}

export const EMPTY_WHOLESALERS: Wholesaler[] = []

export function buildDashboardStats(
  quotes: Quote[] = [],
  requests: QuoteRequest[] = [],
  inventory: InventoryCatalogItem[] = [],
  settings: AppSettings = DEFAULT_SETTINGS,
): DashboardStats {
  const quotesByStatus: Record<QuoteStatus, number> = {
    solicitud_cotizaciones: 0,
    en_elaboracion: 0,
    pendiente_envio: 0,
    enviada: 0,
    modificacion: 0,
    aceptada: 0,
    facturada: 0,
  }

  for (const quote of quotes) {
    quotesByStatus[quote.status] += 1
  }

  return {
    recentQuotes: quotes.slice(0, 5),
    pendingRequests: requests.filter(
      (r) => r.status === 'pendiente' || r.status === 'procesando',
    ).length,
    topProducts: [],
    quotesByStatus,
    lowStockItems: computeLowStockAlerts(inventory, settings),
  }
}
