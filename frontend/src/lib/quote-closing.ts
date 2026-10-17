import { apiFetch } from '@/lib/api-fetch'
import { getApiBase } from '@/lib/app-paths'
import type { QuoteClosingStatus, QuoteLine } from '@/types'

export const CLOSING_LABELS = {
  enviada: 'Enviada al cliente', perdida: 'Perdida', negociacion: 'Negociación',
  ganada_parcialmente: 'Ganada parcialmente', ganada: 'Ganada',
} as const
export type ClosingStatus = keyof typeof CLOSING_LABELS
export const CLOSING_STYLES: Record<ClosingStatus, string> = {
  enviada: 'border-blue-200 bg-blue-50 text-blue-800',
  negociacion: 'border-purple-200 bg-purple-50 text-purple-800',
  ganada: 'border-green-200 bg-green-50 text-green-800',
  perdida: 'border-red-200 bg-red-50 text-red-800',
  ganada_parcialmente: 'border-yellow-300 bg-yellow-100 text-yellow-900',
}
export type ClosingFinancials = { profit: number; marginPercent: number }
export type ClosingLine = Pick<QuoteLine, 'id' | 'product' | 'partNumber' | 'quantity' | 'salePrice' | 'amount'> & { financials?: ClosingFinancials }
export type ClosingDetail = {
  id: string; folio: string; clientName: string; canEdit: boolean
  version: number; quoteVersion: string; status: ClosingStatus; invoice: string; comments: string
  selectedLines: ClosingLine[]; lines: ClosingLine[]
  subtotal: number; taxAmount: number; taxPercent: number; total: number
  financials?: ClosingFinancials
}
export type ClosingSave = {
  status: QuoteClosingStatus; invoice: string; comments: string; selectedLineIds: string[]
  version: number; quoteVersion: string; requestId: string
}
export class ClosingError extends Error {
  fields: Record<string, string[]>
  constructor(message: string, fields: Record<string, string[]> = {}) {
    super(message)
    this.fields = fields
  }
}
export async function closingDetail(id: string, payload?: ClosingSave): Promise<ClosingDetail> {
  const response = await apiFetch(`${getApiBase()}/cotizaciones/${encodeURIComponent(id)}/cierre`, payload ? {
    method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
  } : {})
  const data = await response.json()
  if (!response.ok) throw new ClosingError(data.message || 'No se pudo cargar la cotización.', data.errors ?? {})
  return data as ClosingDetail
}
