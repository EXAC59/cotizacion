import type { QuoteStatus } from '@/types'

/** Todos los estatus conocidos (filtros / historial). */
export const QUOTE_STATUS_ORDER: QuoteStatus[] = [
  'solicitud_cotizaciones',
  'en_elaboracion',
  'pendiente_envio',
  'enviada',
  'modificacion',
  'aceptada',
  'facturada',
]

/** Flujo automático visible en UI (1–5). */
export const QUOTE_WORKFLOW_ORDER: QuoteStatus[] = [
  'solicitud_cotizaciones',
  'en_elaboracion',
  'pendiente_envio',
  'enviada',
  'modificacion',
]

export const LEGACY_QUOTE_STATUS_MAP: Record<string, QuoteStatus> = {
  pendiente: 'en_elaboracion',
  en_revision: 'en_elaboracion',
  rechazada: 'en_elaboracion',
  aprobada: 'aceptada',
  comprada: 'facturada',
}

export function normalizeQuoteStatus(status: string): QuoteStatus {
  if (QUOTE_STATUS_ORDER.includes(status as QuoteStatus)) {
    return status as QuoteStatus
  }

  return LEGACY_QUOTE_STATUS_MAP[status] ?? 'en_elaboracion'
}

export function isPreSentStatus(status: QuoteStatus): boolean {
  return (
    status === 'solicitud_cotizaciones' ||
    status === 'en_elaboracion' ||
    status === 'pendiente_envio' ||
    status === 'modificacion'
  )
}

/** Recordatorios: aún no enviada por ventas al cliente. */
export function isUnsentForClientQuote(quote: {
  status: QuoteStatus | string
  sentAt?: string | null
}): boolean {
  if (quote.sentAt) {
    return false
  }

  const status = normalizeQuoteStatus(String(quote.status))
  return (
    status === 'solicitud_cotizaciones' ||
    status === 'en_elaboracion' ||
    status === 'pendiente_envio'
  )
}

export function quoteStatusIndex(status: QuoteStatus | string): number {
  const normalized = normalizeQuoteStatus(String(status))
  const index = QUOTE_STATUS_ORDER.indexOf(normalized)
  return index === -1 ? 0 : index
}

export function quoteWorkflowIndex(status: QuoteStatus | string): number {
  const normalized = normalizeQuoteStatus(String(status))
  const index = QUOTE_WORKFLOW_ORDER.indexOf(normalized)
  return index === -1 ? -1 : index
}

/** @deprecated El select manual se eliminó; se conserva por compatibilidad. */
export function allowedQuoteStatusesFrom(floorStatus?: QuoteStatus): QuoteStatus[] {
  void floorStatus
  return [...QUOTE_STATUS_ORDER]
}

export function isQuoteStatusRegression(from: QuoteStatus, to: QuoteStatus): boolean {
  return quoteStatusIndex(to) < quoteStatusIndex(from)
}
