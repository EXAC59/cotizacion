import { apiFetch } from '@/lib/api-fetch'
import { getApiBase } from '@/lib/app-paths'
import type { Client, ClientImportResult, ClientQuoteSummary, ClientStats } from '@/types'

export type ClientApi = {
  id: string
  company: string
  rfc: string
  address: string
  contact: string
  email: string
  whatsapp: string
  paymentTerms: string
  created_at?: string
  updated_at?: string
  quotesCount?: number
  stats?: ClientStats
}

export function mapClientApiToClient(api: ClientApi): Client {
  return {
    id: api.id,
    company: api.company,
    rfc: api.rfc,
    address: api.address,
    contact: api.contact,
    email: api.email,
    whatsapp: api.whatsapp,
    paymentTerms: api.paymentTerms,
    createdAt: api.created_at ?? new Date().toISOString(),
    quotesCount: api.quotesCount ?? api.stats?.quotesCount,
    stats: api.stats,
  }
}

export class ClientDeleteError extends Error {
  quotesCount?: number
  code?: string

  constructor(message: string, extras?: { quotesCount?: number; code?: string }) {
    super(message)
    this.name = 'ClientDeleteError'
    this.quotesCount = extras?.quotesCount
    this.code = extras?.code
  }
}

export async function listClients(options?: { q?: string }): Promise<Client[]> {
  const qs = options?.q?.trim() ? `?q=${encodeURIComponent(options.q.trim())}` : ''
  const response = await apiFetch(`${getApiBase()}/clientes${qs}`)
  const data = (await response.json()) as { data?: ClientApi[]; message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al listar clientes (${response.status})`)
  }

  return (data.data ?? []).map(mapClientApiToClient)
}

export async function getClient(id: string): Promise<Client> {
  const response = await apiFetch(`${getApiBase()}/clientes/${id}`)
  const data = (await response.json()) as ClientApi & { message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Cliente no encontrado (${response.status})`)
  }

  return mapClientApiToClient(data)
}

export async function getClientDetail(id: string): Promise<Client> {
  return getClient(id)
}

export async function getClientQuotes(
  id: string,
  options?: { limit?: number; status?: string },
): Promise<ClientQuoteSummary[]> {
  const params = new URLSearchParams()
  if (options?.limit) params.set('limit', String(options.limit))
  if (options?.status) params.set('status', options.status)
  const qs = params.toString() ? `?${params.toString()}` : ''

  const response = await apiFetch(`${getApiBase()}/clientes/${id}/cotizaciones${qs}`)
  const data = (await response.json()) as { data?: ClientQuoteSummary[]; message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al cargar cotizaciones (${response.status})`)
  }

  return data.data ?? []
}

export async function createClient(
  payload: Omit<Client, 'id' | 'createdAt' | 'stats'>,
): Promise<Client> {
  const response = await apiFetch(`${getApiBase()}/clientes`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(payload),
  })

  const data = (await response.json()) as ClientApi & { message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al crear cliente (${response.status})`)
  }

  return mapClientApiToClient(data)
}

export async function updateClient(
  id: string,
  payload: Omit<Client, 'id' | 'createdAt' | 'stats'>,
): Promise<Client> {
  const response = await apiFetch(`${getApiBase()}/clientes/${id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(payload),
  })

  const data = (await response.json()) as ClientApi & { message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al actualizar cliente (${response.status})`)
  }

  return mapClientApiToClient(data)
}

export async function deleteClient(id: string): Promise<void> {
  const response = await apiFetch(`${getApiBase()}/clientes/${id}`, {
    method: 'DELETE',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    const data = (await response.json()) as {
      message?: string
      quotesCount?: number
      code?: string
    }
    throw new ClientDeleteError(
      data.message || `Error al eliminar cliente (${response.status})`,
      { quotesCount: data.quotesCount, code: data.code },
    )
  }
}

export async function importClients(file: File): Promise<ClientImportResult> {
  const form = new FormData()
  form.append('archivo', file)

  const response = await apiFetch(`${getApiBase()}/clientes/import`, {
    method: 'POST',
    body: form,
  })

  const data = (await response.json()) as ClientImportResult & { message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al importar clientes (${response.status})`)
  }

  return data
}

export function getClientsTemplateUrl(): string {
  return `${getApiBase()}/clientes/import/plantilla`
}

export async function downloadClientsTemplate(): Promise<void> {
  const response = await fetch(getClientsTemplateUrl(), { credentials: 'include' })
  if (!response.ok) {
    throw new Error(`No se pudo descargar la plantilla (${response.status})`)
  }

  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'plantilla-clientes.xlsx'
  link.click()
  window.setTimeout(() => URL.revokeObjectURL(url), 30_000)
}
