import { createContext } from 'react'
import type {
  AppSettings,
  Client,
  DashboardStats,
  InventoryCatalogItem,
  LowStockAlert,
  Quote,
  QuoteRequest,
  RequestPricingLine,
  Wholesaler,
} from '@/types'

export interface DataContextValue {
  clients: Client[]
  quotes: Quote[]
  requests: QuoteRequest[]
  wholesalers: Wholesaler[]
  inventory: InventoryCatalogItem[]
  settings: AppSettings
  dashboard: DashboardStats
  lowStockItems: LowStockAlert[]
  activeWholesalers: Wholesaler[]
  getClient: (id: string) => Client | undefined
  getQuote: (id: string) => Quote | undefined
  getRequest: (id: string) => QuoteRequest | undefined
  saveClient: (client: Client) => void
  deleteClient: (id: string) => void
  saveQuote: (quote: Quote) => void
  saveRequest: (request: QuoteRequest) => void
  toggleWholesaler: (id: string, active: boolean) => void
  setMinStockAlert: (value: number) => void
  uploadRequestPricing: (requestId: string, lines: RequestPricingLine[]) => void
}

export const DataContext = createContext<DataContextValue | null>(null)
