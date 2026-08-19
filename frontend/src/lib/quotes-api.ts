import { apiFetch } from '@/lib/api-fetch'
import { salePriceFromCost } from '@/lib/calculations'
import { normalizeQuoteStatus } from '@/lib/quote-status'

import { getApiBase } from '@/lib/app-paths'

import type { Quote, QuoteEditLock, QuoteInternalNote, QuoteLine, QuoteStatus, QuoteStatusHistoryEntry, WholesalerOffer } from '@/types'

export class QuoteLockError extends Error {
  readonly lockedBy: QuoteEditLock

  readonly lockedAt?: string

  constructor(message: string, lockedBy: QuoteEditLock, lockedAt?: string) {
    super(message)
    this.name = 'QuoteLockError'
    this.lockedBy = lockedBy
    this.lockedAt = lockedAt
  }
}



type QuoteApiLine = {

  id: string

  quantity: number

  product: string

  partNumber: string

  cost: number

  marginPercent: number

  salePrice: number

  amount: number

  warehouse: string

  selectedWholesalerId?: string | null

  offers?: Array<{

    wholesalerId: string

    wholesalerName?: string

    cost: number

    stock: number

    warehouse: string

    leadDays: number

    isSelected: boolean

  }>

}



type QuoteApiResponse = {

  id: string

  folio: string

  clientId: string

  clientName?: string

  requestId?: string | null

  status: Quote['status']

  validityDays: number

  globalMarginPercent: number

  taxPercent: number

  notes: string

  customerObservations?: string

  internalNotes?: QuoteInternalNote[]

  createdAt?: string

  sentAt?: string | null

  invoiceNumber?: string | null

  editLock?: QuoteEditLock | null

  statusHistory?: Array<{
    fromStatus: Quote['status'] | null
    toStatus: Quote['status']
    userName?: string | null
    createdAt: string
  }>

  lockedBy?: { id: string; name: string; email: string }

  lockedAt?: string

  lines: QuoteApiLine[]

  message?: string

}



type QuoteSummaryApi = {

  id: string

  folio: string

  clientId: string

  clientName?: string

  status: Quote['status']

  taxPercent: number

  total: number

  linesCount: number

  createdAt?: string

  createdByName?: string | null

  invoiceNumber?: string | null

  editLock?: QuoteEditLock | null

}



export function mapQuoteFromApi(data: QuoteApiResponse): Quote {

  const globalMargin = data.globalMarginPercent



  return {

    id: data.id,

    folio: data.folio,

    clientId: data.clientId,

    clientName: data.clientName ?? '',

    requestId: data.requestId ?? undefined,

    status: normalizeQuoteStatus(data.status),

    validityDays: data.validityDays,

    globalMarginPercent: globalMargin,

    taxPercent: data.taxPercent,

    notes: data.notes ?? '',

    customerObservations: data.customerObservations ?? '',

    internalNotes: data.internalNotes ?? [],

    createdAt: data.createdAt ?? new Date().toISOString(),

    sentAt: data.sentAt ?? undefined,

    invoiceNumber: data.invoiceNumber ?? undefined,

    editLock: data.editLock ?? undefined,

    statusHistory: data.statusHistory,

    lines: (data.lines ?? []).map((line): QuoteLine => ({

      id: line.id,

      quantity: line.quantity,

      product: line.product,

      partNumber: line.partNumber,

      cost: line.cost,

      marginPercent: line.marginPercent,

      salePrice: line.salePrice,

      amount: line.amount,

      warehouse: line.warehouse,

      selectedWholesalerId: line.selectedWholesalerId ?? undefined,

      usesGlobalMargin: (() => {
        const derived = salePriceFromCost(line.cost, line.marginPercent)
        const customSalePrice = Math.abs(line.salePrice - derived) >= 0.0001
        const customMargin = Math.abs(line.marginPercent - globalMargin) >= 0.01
        return !customSalePrice && !customMargin
      })(),

      offers: (line.offers ?? []).map(

        (o): WholesalerOffer => ({

          wholesalerId: o.wholesalerId,

          wholesalerName: o.wholesalerName ?? '',

          cost: o.cost,

          stock: o.stock,

          warehouse: o.warehouse,

          leadDays: o.leadDays,

          isSelected: o.isSelected,

        }),

      ),

    })),

  }

}



export async function fetchNextQuoteFolio(): Promise<string> {
  const response = await apiFetch(`${getApiBase()}/cotizaciones/proximo-folio`)
  const data = (await response.json()) as { folio?: string; message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al obtener folio (${response.status})`)
  }

  if (!data.folio?.trim()) {
    throw new Error('No se recibió un folio válido del servidor.')
  }

  return data.folio.trim()
}

export async function persistQuote(quote: Quote): Promise<Quote> {

  const response = await apiFetch(`${getApiBase()}/cotizaciones`, {

    method: 'POST',

    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },

    body: JSON.stringify({

      id: quote.id,

      folio: quote.folio,

      clientId: quote.clientId,

      requestId: quote.requestId ?? null,

      status: quote.status,

      validityDays: quote.validityDays,

      globalMarginPercent: quote.globalMarginPercent,

      taxPercent: quote.taxPercent,

      notes: quote.notes,

      customerObservations: quote.customerObservations ?? '',

      sentAt: quote.sentAt ?? null,

      invoiceNumber: quote.invoiceNumber?.trim() || null,

      lines: quote.lines.map((line, index) => ({

        id: line.id,

        lineOrder: index,

        quantity: line.quantity,

        product: line.product,

        partNumber: line.partNumber,

        cost: line.cost,

        marginPercent: line.marginPercent,

        salePrice: line.salePrice,

        amount: line.amount,

        warehouse: line.warehouse,

        usesGlobalMargin: line.usesGlobalMargin ?? true,

        selectedWholesalerId: line.selectedWholesalerId ?? null,

        offers: (line.offers ?? []).map((o) => ({

          wholesalerId: o.wholesalerId,

          cost: o.cost,

          stock: o.stock,

          warehouse: o.warehouse,

          leadDays: o.leadDays ?? 0,

          isSelected: o.isSelected ?? o.wholesalerId === line.selectedWholesalerId,

        })),

      })),

    }),

  })



  const data = (await response.json()) as QuoteApiResponse



  if (!response.ok) {
    if (response.status === 423) {
      const lockedBy = data.lockedBy as QuoteEditLock | undefined
      if (lockedBy) {
        throw new QuoteLockError(
          data.message || 'Esta cotización está en seguimiento por otro usuario.',
          lockedBy,
          (data as { lockedAt?: string }).lockedAt,
        )
      }
    }

    throw new Error(data.message || `Error al guardar cotización (${response.status})`)
  }



  return mapQuoteFromApi(data)

}

export async function addQuoteInternalNote(id: string, body: string): Promise<QuoteInternalNote> {
  const response = await apiFetch(
    `${getApiBase()}/cotizaciones/${encodeURIComponent(id)}/notas-internas`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ body }),
    },
  )
  const data = (await response.json()) as QuoteInternalNote & { message?: string }
  if (!response.ok) {
    throw new Error(data.message || `No se pudo agregar la nota interna (${response.status})`)
  }
  return data
}



export async function listQuotes(params?: {
  search?: string
  status?: Quote['status']
}): Promise<Quote[]> {

  const qs = new URLSearchParams()
  if (params?.search?.trim()) qs.set('search', params.search.trim())
  if (params?.status) qs.set('status', params.status)
  const query = qs.toString()

  const response = await apiFetch(`${getApiBase()}/cotizaciones${query ? `?${query}` : ''}`)

  const data = (await response.json()) as { data?: QuoteSummaryApi[]; message?: string }



  if (!response.ok) {

    throw new Error(data.message || `Error al listar cotizaciones (${response.status})`)

  }



  return (data.data ?? []).map((row) => ({

    id: row.id,

    folio: row.folio,

    clientId: row.clientId,

    clientName: row.clientName ?? '',

    status: normalizeQuoteStatus(row.status),

    validityDays: 15,

    globalMarginPercent: 30,

    taxPercent: row.taxPercent,

    notes: '',

    createdAt: row.createdAt ?? new Date().toISOString(),

    lines: [],

    linesCount: row.linesCount,

    total: row.total,

    invoiceNumber: row.invoiceNumber ?? undefined,

    createdByName: row.createdByName ?? undefined,

    editLock: row.editLock ?? undefined,

  }))

}



export async function getQuoteById(id: string): Promise<Quote> {

  const response = await apiFetch(`${getApiBase()}/cotizaciones/${id}`)

  const data = (await response.json()) as QuoteApiResponse



  if (!response.ok) {

    throw new Error(data.message || `Cotización no encontrada (${response.status})`)

  }



  return mapQuoteFromApi(data)

}



export function getQuotePdfUrl(id: string, download = false): string {

  const qs = download ? '?download=1' : ''

  return `${getApiBase()}/cotizaciones/${encodeURIComponent(id)}/pdf${qs}`

}



const UUID_RE =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i



export function isPersistedQuoteId(id: string): boolean {

  return UUID_RE.test(id)

}



export async function openQuotePdf(id: string, download = false): Promise<void> {

  if (!isPersistedQuoteId(id)) {

    alert('Guarda la cotización en el servidor antes de generar el PDF.')

    return

  }



  const url = getQuotePdfUrl(id, download)



  try {

    // Must use apiFetch so Sanctum Bearer token is sent (raw fetch → 401 Unauthenticated).
    const response = await apiFetch(url, {

      headers: { Accept: 'application/pdf' },

    })



    if (!response.ok) {

      const data = (await response.json().catch(() => null)) as { message?: string } | null

      const message = data?.message ?? `No se pudo generar el PDF (${response.status}).`
      alert(
        response.status === 401
          ? 'Sesión no válida para el PDF. Cierra sesión, vuelve a entrar e inténtalo de nuevo.'
          : message,
      )

      return

    }



    const blob = await response.blob()

    if (!blob.size) {

      alert('El PDF llegó vacío. Revisa que la cotización tenga partidas.')

      return

    }



    const blobUrl = URL.createObjectURL(blob)



    if (download) {

      const link = document.createElement('a')

      link.href = blobUrl

      link.download = `cotizacion-${id.slice(0, 8)}.pdf`

      link.click()

    } else {

      window.open(blobUrl, '_blank', 'noopener,noreferrer')

    }



    window.setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000)

  } catch {

    alert('Error de red al generar el PDF. Inténtalo de nuevo.')

  }

}

export type SendQuoteEmailOptions = {
  to?: string
  subject?: string
  message?: string
}

export type SendQuoteEmailResult = {
  queued: boolean
  message: string
}

export async function sendQuoteByEmail(
  id: string,
  options: SendQuoteEmailOptions = {},
): Promise<SendQuoteEmailResult> {
  if (!isPersistedQuoteId(id)) {
    throw new Error('Guarda la cotización en el servidor antes de enviar por correo.')
  }

  const response = await apiFetch(`${getApiBase()}/cotizaciones/${encodeURIComponent(id)}/enviar`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({
      to: options.to?.trim() || undefined,
      subject: options.subject?.trim() || undefined,
      message: options.message?.trim() || undefined,
    }),
  })

  const data = (await response.json()) as SendQuoteEmailResult & { message?: string }

  if (!response.ok) {
    throw new Error(data.message || `No se pudo encolar el envío (${response.status}).`)
  }

  return {
    queued: data.queued ?? true,
    message: data.message ?? 'Cotización encolada para envío por correo.',
  }
}

type QuoteLockApiResponse = {
  message?: string
  locked?: boolean
  lockedBy?: { id: string; name: string; email: string }
  lockedAt?: string
  heartbeatSeconds?: number
  editLock?: QuoteEditLock
  status?: string
  statusChanged?: boolean
  statusHistory?: QuoteStatusHistoryEntry[]
}

function mapLockedByPayload(data: QuoteLockApiResponse): QuoteLockError | null {
  if (!data.lockedBy) {
    return null
  }

  return new QuoteLockError(
    data.message ?? 'Esta cotización está en seguimiento por otro usuario.',
    {
      userId: data.lockedBy.id,
      userName: data.lockedBy.name,
      userEmail: data.lockedBy.email,
    },
    data.lockedAt,
  )
}

export type AcquireQuoteLockResult = {
  heartbeatSeconds: number
  status?: QuoteStatus
  statusChanged?: boolean
  statusHistory?: QuoteStatusHistoryEntry[]
}

export async function acquireQuoteLock(id: string): Promise<AcquireQuoteLockResult> {
  const response = await apiFetch(`${getApiBase()}/cotizaciones/${encodeURIComponent(id)}/bloqueo`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
  })

  const data = (await response.json()) as QuoteLockApiResponse

  if (response.status === 423) {
    const err = mapLockedByPayload(data)
    if (err) {
      throw err
    }
  }

  if (!response.ok) {
    throw new Error(data.message || `No se pudo reservar la cotización (${response.status})`)
  }

  return {
    heartbeatSeconds: data.heartbeatSeconds ?? 60,
    status: data.status ? normalizeQuoteStatus(data.status) : undefined,
    statusChanged: Boolean(data.statusChanged),
    statusHistory: data.statusHistory,
  }
}

export async function releaseQuoteLock(id: string): Promise<void> {
  await apiFetch(`${getApiBase()}/cotizaciones/${encodeURIComponent(id)}/bloqueo`, {
    method: 'DELETE',
    headers: { Accept: 'application/json' },
    keepalive: true,
  })
}
