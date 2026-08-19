import { DEFAULT_SETTINGS } from '@/data/mock'
import type { AppSettings, Client, InventoryCatalogItem, Quote, QuoteRequest, Wholesaler } from '@/types'

const STORAGE_KEY = 'cotizacion_app_v2'

export type DemoStore = {
  clients: Client[]
  quotes: Quote[]
  requests: QuoteRequest[]
  wholesalers: Wholesaler[]
  inventory: InventoryCatalogItem[]
  settings: AppSettings
}

const EMPTY_STORE: DemoStore = {
  clients: [],
  quotes: [],
  requests: [],
  wholesalers: [],
  inventory: [],
  settings: { ...DEFAULT_SETTINGS },
}

export function loadDemoStore(): DemoStore {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) {
      const parsed = JSON.parse(raw) as Partial<DemoStore>
      return {
        clients: [],
        quotes: parsed.quotes ?? [],
        requests: [],
        wholesalers: parsed.wholesalers ?? [],
        inventory: parsed.inventory ?? [],
        settings: { ...DEFAULT_SETTINGS, ...parsed.settings },
      }
    }
  } catch {
    /* defaults */
  }

  return { ...EMPTY_STORE, settings: { ...DEFAULT_SETTINGS } }
}

export function saveDemoStore(store: DemoStore): void {
  try {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        quotes: store.quotes,
        wholesalers: store.wholesalers,
        inventory: store.inventory,
        settings: store.settings,
      }),
    )
  } catch {
    /* ignore */
  }
}
