import { apiFetch } from '@/lib/api-fetch'
import { parseApiJson } from '@/lib/api-response'
import { getApiBase } from '@/lib/app-paths'
import type { DashboardAnalytics, ReportesAnalytics } from '@/types'

export type AnalyticsPeriodPreset = 'current_month' | 'previous_month' | 'last_6_months' | 'all'

export function periodRangeForPreset(preset: AnalyticsPeriodPreset): { from?: string; to?: string } {
  const now = new Date()

  if (preset === 'all') {
    const to = new Date()
    return { from: '2000-01-01', to: toIsoDate(to) }
  }

  if (preset === 'last_6_months') {
    const from = new Date(now.getFullYear(), now.getMonth() - 5, 1)
    const to = new Date(now.getFullYear(), now.getMonth() + 1, 0)
    return { from: toIsoDate(from), to: toIsoDate(to) }
  }

  const monthOffset = preset === 'previous_month' ? -1 : 0
  const from = new Date(now.getFullYear(), now.getMonth() + monthOffset, 1)
  const to = new Date(now.getFullYear(), now.getMonth() + monthOffset + 1, 0)

  return { from: toIsoDate(from), to: toIsoDate(to) }
}

function toIsoDate(date: Date): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

function buildQuery(params?: { from?: string; to?: string }): string {
  if (!params?.from && !params?.to) return ''
  const qs = new URLSearchParams()
  if (params.from) qs.set('from', params.from)
  if (params.to) qs.set('to', params.to)
  const query = qs.toString()
  return query ? `?${query}` : ''
}

export async function fetchDashboard(params?: {
  from?: string
  to?: string
}): Promise<DashboardAnalytics> {
  const response = await apiFetch(`${getApiBase()}/dashboard${buildQuery(params)}`)
  const data = await parseApiJson<DashboardAnalytics & { message?: string }>(response)

  if (!response.ok) {
    if (response.status === 403) {
      throw new Error('No tienes permiso para ver el dashboard.')
    }
    throw new Error(data.message || `Error al cargar dashboard (${response.status})`)
  }

  return data
}

export async function fetchReportes(params?: {
  from?: string
  to?: string
}): Promise<ReportesAnalytics> {
  const response = await apiFetch(`${getApiBase()}/reportes${buildQuery(params)}`)
  const data = (await response.json()) as ReportesAnalytics & { message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al cargar reportes (${response.status})`)
  }

  return data
}
