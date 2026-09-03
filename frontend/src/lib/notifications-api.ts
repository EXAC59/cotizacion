import { apiFetch } from '@/lib/api-fetch'
import { getApiBase } from '@/lib/app-paths'
import { reloadOnceForSpaBuild } from '@/lib/spa-build-reload'
import type {
  FollowUpStatus,
  QuoteFollowUp,
  QuoteFollowUpHistoryEntry,
  QuoteNotifyEligibility,
  SalesNotificationItem,
} from '@/types'

type NotificationsListResponse = {
  data?: SalesNotificationItem[]
  unreadCount?: number
  message?: string
}

export async function listNotifications(): Promise<{
  data: SalesNotificationItem[]
  unreadCount: number
}> {
  const response = await apiFetch(`${getApiBase()}/notificaciones`)
  const remoteBuild = response.headers.get('X-Spa-Build-Id')
  if (remoteBuild && remoteBuild !== __SPA_BUILD_ID__) {
    reloadOnceForSpaBuild()
    return { data: [], unreadCount: 0 }
  }
  const data = (await response.json()) as NotificationsListResponse & {
    spaBuildId?: string
    spaUpgradeRequired?: boolean
  }
  if (data.spaUpgradeRequired) {
    reloadOnceForSpaBuild()
    return { data: [], unreadCount: 0 }
  }
  if (data.spaBuildId && data.spaBuildId !== __SPA_BUILD_ID__) {
    reloadOnceForSpaBuild()
    return { data: [], unreadCount: 0 }
  }
  if (!response.ok) {
    throw new Error(data.message || `Error al listar notificaciones (${response.status})`)
  }
  return {
    data: data.data ?? [],
    unreadCount: data.unreadCount ?? 0,
  }
}

export async function notifySalesAboutQuote(
  quoteId: string,
  message?: string,
): Promise<SalesNotificationItem> {
  const response = await apiFetch(`${getApiBase()}/notificaciones`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ quoteId, message: message ?? null }),
  })
  const data = (await response.json()) as SalesNotificationItem & {
    message?: string
    errors?: Record<string, string[]>
  }
  if (!response.ok) {
    const firstError = data.errors ? Object.values(data.errors).flat()[0] : undefined
    throw new Error(firstError || data.message || `Error al avisar (${response.status})`)
  }
  return data
}

export async function markNotificationRead(id: string): Promise<SalesNotificationItem> {
  const response = await apiFetch(`${getApiBase()}/notificaciones/${id}/leer`, {
    method: 'PATCH',
    headers: { Accept: 'application/json' },
  })
  const data = (await response.json()) as SalesNotificationItem & { message?: string }
  if (!response.ok) {
    throw new Error(data.message || `Error al marcar leída (${response.status})`)
  }
  return data
}

export async function updateQuoteFollowUp(
  quoteId: string,
  payload: {
    status: FollowUpStatus
    remindDate?: string | null
    invoice?: string | null
    comments?: string | null
  },
): Promise<{
  followUp: QuoteFollowUp | null
  followUpHistory: QuoteFollowUpHistoryEntry[]
  eligibility: QuoteNotifyEligibility
}> {
  const response = await apiFetch(`${getApiBase()}/cotizaciones/${quoteId}/seguimiento`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = (await response.json()) as {
    followUp?: QuoteFollowUp | null
    followUpHistory?: QuoteFollowUpHistoryEntry[]
    eligibility?: QuoteNotifyEligibility
    message?: string
    errors?: Record<string, string[]>
  }
  if (!response.ok) {
    const firstError = data.errors ? Object.values(data.errors).flat()[0] : undefined
    throw new Error(firstError || data.message || `Error al guardar recordatorio (${response.status})`)
  }
  return {
    followUp: data.followUp ?? null,
    followUpHistory: data.followUpHistory ?? [],
    eligibility: data.eligibility ?? {
      eligible: false,
      reasonCode: null,
      blockReason: null,
      daysIdle: 0,
    },
  }
}
