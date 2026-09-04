import { apiFetch } from '@/lib/api-fetch'
import { parseApiJsonOrThrow, type ApiErrorBody } from '@/lib/api-response'
import { getApiBase } from '@/lib/app-paths'

export type AssignRecipientOption = {
  id: number
  name: string
  folioCode?: string | null
}

/** @deprecated Prefer AssignRecipientOption */
export type SalespersonOption = AssignRecipientOption

export async function listSalespeople(): Promise<AssignRecipientOption[]> {
  const response = await apiFetch(`${getApiBase()}/usuarios/ventas`, {
    headers: { Accept: 'application/json' },
  })
  const data = await parseApiJsonOrThrow<{ data?: AssignRecipientOption[]; message?: string }>(
    response,
  )
  return data.data ?? []
}

export async function listComprasUsers(): Promise<AssignRecipientOption[]> {
  const response = await apiFetch(`${getApiBase()}/usuarios/compras`, {
    headers: { Accept: 'application/json' },
  })
  const data = await parseApiJsonOrThrow<{ data?: AssignRecipientOption[]; message?: string }>(
    response,
  )
  return data.data ?? []
}

export async function assignQuoteToSales(
  quoteId: string,
  recipientId?: number,
  message?: string,
): Promise<{ folio: string; previousFolio: string; id: string }> {
  return assignQuote(quoteId, 'ventas', recipientId, message)
}

export async function assignQuoteToCompras(
  quoteId: string,
  recipientId?: number,
  message?: string,
): Promise<{ folio: string; previousFolio: string; id: string }> {
  return assignQuote(quoteId, 'compras', recipientId, message)
}

async function assignQuote(
  quoteId: string,
  target: 'ventas' | 'compras',
  recipientId?: number,
  message?: string,
): Promise<{ folio: string; previousFolio: string; id: string }> {
  const response = await apiFetch(`${getApiBase()}/cotizaciones/${quoteId}/asignar-${target}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({
      ...(recipientId != null ? { recipientId } : {}),
      message: message?.trim() || undefined,
    }),
  })
  const data = await parseApiJsonOrThrow<
    ApiErrorBody & { folio?: string; previousFolio?: string; id?: string }
  >(response)

  return {
    id: data.id ?? quoteId,
    folio: data.folio ?? '',
    previousFolio: data.previousFolio ?? '',
  }
}

export async function assignSolicitudToSales(
  requestId: string,
  recipientId?: number,
): Promise<{ folio: string | null; previousFolio: string | null; id: string }> {
  return assignSolicitud(requestId, 'ventas', recipientId)
}

export async function assignSolicitudToCompras(
  requestId: string,
  recipientId?: number,
): Promise<{ folio: string | null; previousFolio: string | null; id: string }> {
  return assignSolicitud(requestId, 'compras', recipientId)
}

async function assignSolicitud(
  requestId: string,
  target: 'ventas' | 'compras',
  recipientId?: number,
): Promise<{ folio: string | null; previousFolio: string | null; id: string }> {
  const response = await apiFetch(`${getApiBase()}/solicitudes/${requestId}/asignar-${target}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(recipientId != null ? { recipientId } : {}),
  })
  const data = await parseApiJsonOrThrow<
    ApiErrorBody & { folio?: string | null; previousFolio?: string | null; id?: string }
  >(response)

  return {
    id: data.id ?? requestId,
    folio: data.folio ?? null,
    previousFolio: data.previousFolio ?? null,
  }
}
