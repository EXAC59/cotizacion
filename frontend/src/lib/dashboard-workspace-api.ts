import { apiFetch } from '@/lib/api-fetch'
import { getApiBase } from '@/lib/app-paths'
import { parseApiJson } from '@/lib/api-response'
import type { QuoteTableRow } from '@/components/quotes/QuoteListTable'

export type WorkspaceFocus = 'all' | 'pending' | 'unread' | 'progress' | 'ready' | 'sent' | 'unsent'
export type DashboardWorkspaceData = {
  counts: Record<WorkspaceFocus, number>
  data: QuoteTableRow[]
  meta: { page: number; lastPage: number; total: number; perPage: number; from: number | null; to: number | null }
  updatedAt: string
}

export async function fetchDashboardWorkspace(query: URLSearchParams, signal: AbortSignal): Promise<DashboardWorkspaceData> {
  const response = await apiFetch(`${getApiBase()}/dashboard/quotes?${query}`, { signal })
  const data = await parseApiJson<DashboardWorkspaceData & { message?: string }>(response)
  if (!response.ok) throw new Error(data.message ?? 'No se pudieron cargar las cotizaciones.')
  return data
}
