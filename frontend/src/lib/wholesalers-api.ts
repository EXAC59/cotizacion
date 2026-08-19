import { apiFetch } from '@/lib/api-fetch'
import { getApiBase } from '@/lib/app-paths'
import type { ComparatorResult, LowStockAlert, Wholesaler } from '@/types'

export type LowStockResponse = {
  threshold: number
  items: LowStockAlert[]
  count: number
}

export type WholesalerApi = {
  id: string
  code: string
  name: string
  integration: Wholesaler['integration']
  active: boolean
  configured?: boolean
  config?: { envPrefix?: string | null }
  created_at?: string
  updated_at?: string
}

export type WholesalerOfferApi = {
  wholesalerId: string
  wholesalerCode: string
  wholesalerName: string
  partNumber: string
  cost: number
  stock: number
  warehouse: string
  leadDays: number
  error?: string | null
}

export function mapWholesalerApiToWholesaler(api: WholesalerApi): Wholesaler {
  return {
    id: api.id,
    code: api.code,
    name: api.name,
    integration: api.integration,
    active: api.active,
    configured: api.configured ?? false,
  }
}

export async function listWholesalers(): Promise<Wholesaler[]> {
  const response = await apiFetch(`${getApiBase()}/mayoristas`)
  const data = (await response.json()) as { data?: WholesalerApi[]; message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al listar mayoristas (${response.status})`)
  }

  return (data.data ?? []).map(mapWholesalerApiToWholesaler)
}

export async function fetchLowStock(limit = 100): Promise<LowStockResponse> {
  const params = new URLSearchParams({ limit: String(limit) })
  const response = await apiFetch(`${getApiBase()}/mayoristas/stock-bajo?${params}`)
  const data = (await response.json()) as LowStockResponse & { message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al cargar stock bajo (${response.status})`)
  }

  return {
    threshold: data.threshold ?? 0,
    items: data.items ?? [],
    count: data.count ?? (data.items?.length ?? 0),
  }
}

export async function updateWholesalerActive(id: string, active: boolean): Promise<Wholesaler> {
  const response = await apiFetch(`${getApiBase()}/mayoristas/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ active }),
  })

  const data = (await response.json()) as WholesalerApi & { message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al actualizar mayorista (${response.status})`)
  }

  return mapWholesalerApiToWholesaler(data)
}

export async function lookupPartNumber(
  partNumber: string,
  wholesalerIds?: string[],
): Promise<WholesalerOfferApi[]> {
  const response = await apiFetch(`${getApiBase()}/mayoristas/consultar`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ partNumber, wholesalerIds }),
  })

  const data = (await response.json()) as { offers?: WholesalerOfferApi[]; message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al consultar precios (${response.status})`)
  }

  return data.offers ?? []
}

export async function comparePartNumber(
  partNumber: string,
  quantity = 1,
  preferredWarehouse?: string,
): Promise<ComparatorResult> {
  const response = await apiFetch(`${getApiBase()}/mayoristas/comparar`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ partNumber, quantity, preferredWarehouse }),
  })

  const data = (await response.json()) as ComparatorResult & { message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al comparar precios (${response.status})`)
  }

  return data
}

export async function compareBatch(
  lines: Array<{ partNumber: string; quantity?: number; preferredWarehouse?: string }>,
  preferredWarehouse?: string,
): Promise<ComparatorResult[]> {
  const response = await apiFetch(`${getApiBase()}/mayoristas/comparar-lote`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ lines, preferredWarehouse }),
  })

  const data = (await response.json()) as { results?: ComparatorResult[]; message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al comparar lote (${response.status})`)
  }

  return data.results ?? []
}

export type CtAutocompleteItem = {
  clave: string
  partNumber: string | null
  nombre: string
  descripcion: string
  marca: string
  providers?: Array<'CT' | 'CVA'>
}

export async function autocompleteCtProducts(
  options: {
    q?: string
    sku?: string
    descripcion?: string
    limit?: number
    signal?: AbortSignal
  },
): Promise<CtAutocompleteItem[]> {
  const sku = (options.sku ?? '').trim()
  const descripcion = (options.descripcion ?? '').trim()
  const q = (options.q ?? '').trim()
  const limit = options.limit ?? 15

  if (sku.length < 2 && descripcion.length < 2 && q.length < 2) {
    return []
  }

  const params = new URLSearchParams({ limit: String(limit) })
  if (sku !== '' || descripcion !== '') {
    if (sku !== '') params.set('sku', sku)
    if (descripcion !== '') params.set('descripcion', descripcion)
  } else {
    params.set('q', q)
  }

  const response = await apiFetch(`${getApiBase()}/mayoristas/ct/autocomplete?${params}`, {
    headers: { Accept: 'application/json' },
    signal: options.signal,
  })

  const data = (await response.json()) as {
    data?: CtAutocompleteItem[]
    message?: string
  }

  if (!response.ok) {
    throw new Error(data.message || `Error al buscar productos CT (${response.status})`)
  }

  return (data.data ?? []).map((item) => ({
    ...item,
    providers: ['CT'],
  }))
}

export async function autocompleteCvaProducts(
  options: {
    q?: string
    sku?: string
    descripcion?: string
    limit?: number
    signal?: AbortSignal
  },
): Promise<CtAutocompleteItem[]> {
  const sku = (options.sku ?? '').trim()
  const descripcion = (options.descripcion ?? '').trim()
  const q = (options.q ?? '').trim()
  const limit = options.limit ?? 15

  if (sku.length < 2 && descripcion.length < 2 && q.length < 2) {
    return []
  }

  const params = new URLSearchParams({ limit: String(limit) })
  if (sku !== '' || descripcion !== '') {
    if (sku !== '') params.set('sku', sku)
    if (descripcion !== '') params.set('descripcion', descripcion)
  } else {
    params.set('q', q)
  }

  const response = await apiFetch(`${getApiBase()}/mayoristas/cva/autocomplete?${params}`, {
    headers: { Accept: 'application/json' },
    signal: options.signal,
  })

  const data = (await response.json()) as {
    data?: CtAutocompleteItem[]
    message?: string
  }

  if (!response.ok) {
    throw new Error(data.message || `Error al buscar productos CVA (${response.status})`)
  }

  return (data.data ?? []).map((item) => ({
    ...item,
    providers: ['CVA'],
  }))
}
