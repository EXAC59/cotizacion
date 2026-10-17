import { apiFetch } from '@/lib/api-fetch'
import { getApiBase } from '@/lib/app-paths'
import type { QuoteStatus } from '@/types'
import type { Stage } from './workflow-data'

export const isMessagingTest = import.meta.env.VITE_MENSAJERIA_ENVIRONMENT !== 'production'
export type LiveSection = 'new_requests' | 'own_requests' | 'own_quotes' | 'ready_to_send' | 'quote_notices' | 'pending' | 'history'
export type LiveNotice = { id: string; group: LiveSection; label: string; detail: string; read: boolean; reminder: boolean; createdAt?: string | null }

export type LiveQuote = {
  id: string; folio: string; client: string; sales: string; purchasing: string
  status: QuoteStatus; total: number; createdAt: string; stage: Stage
  section: LiveSection; daysIdle: number
  requestId?: string | null; sections?: LiveSection[]; notices?: LiveNotice[]
  alertType: 'notification' | 'reminder' | null; alertDetail: string | null
  hasChat: boolean; canChat: boolean; canView?: boolean; canManageWorkflow?: boolean; canTake: boolean
  hasNotifications: boolean; notificationCount: number; lastNotification: string | null; lastNotificationAt: string | null
  checks: { stock: boolean; price: boolean; delivery: boolean }
  reminder: string | null; unreadCount: number; lastMessage: string; lastMessageAt: string | null
  products: { description: string; quantity: number }[]
  nextAction?: string
}
export type LiveMessage = { id: string; text: string; mine: boolean; system: boolean; authorName: string; createdAt: string; read: boolean }
export type LiveChatNotice = { id: string; label: string; detail: string; persistent: boolean; read: boolean; createdAt: string | null }
export type LiveDetail = { quote: LiveQuote; messages: LiveMessage[]; notices: LiveChatNotice[]; hasMore: boolean }
export type LiveIndex = { data: LiveQuote[]; page: number; lastPage: number; total: number
  sections: Record<LiveSection, number>; unreadQuoteCount: number; reminderCount: number; unreadCount: number }

export async function chatApi<T>(path = '', payload?: object, signal?: AbortSignal): Promise<T> {
  const response = await apiFetch(`${getApiBase()}/mensajeria${path}`, payload ? {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload), signal,
  } : { signal })
  const data = await response.json()
  if (!response.ok) {
    if (response.status === 401) throw new Error('Tu sesión terminó. Vuelve a iniciar sesión en el sistema.')
    throw new Error(data.errors ? Object.values(data.errors).flat()[0] as string : data.message || `Error de Mensajería (${response.status})`)
  }
  return data as T
}

export function serverDate(value: string): Date {
  return new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z')
}

export function quoteUrl(id?: string) {
  const root = getApiBase().replace(/\/api\/?$/, '/spa')
  return `${root}/cotizaciones/${id || 'nueva'}`
}
