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

export async function listComprasUsers(): Promise<AssignRecipientOption[]> {
  const response = await apiFetch(`${getApiBase()}/usuarios/compras`, {
    headers: { Accept: 'application/json' },
  })
  const data = await parseApiJsonOrThrow<{ data?: AssignRecipientOption[]; message?: string }>(
    response,
  )
  return data.data ?? []
}

export async function assignQuoteToCompras(
  quoteId: string,
  recipientId?: number,
  message?: string,
): Promise<{ folio: string; previousFolio: string; id: string }> {
  return assignQuote(quoteId, recipientId, message)
}

async function assignQuote(
  quoteId: string,
  recipientId?: number,
  message?: string,
): Promise<{ folio: string; previousFolio: string; id: string }> {
  const response = await apiFetch(`${getApiBase()}/cotizaciones/${quoteId}/asignar-compras`, {
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

export async function assignSolicitudToCompras(
  requestId: string,
  recipientId?: number,
): Promise<{ folio: string | null; previousFolio: string | null; id: string }> {
  return assignSolicitud(requestId, recipientId)
}

async function assignSolicitud(
  requestId: string,
  recipientId?: number,
): Promise<{ folio: string | null; previousFolio: string | null; id: string }> {
  const response = await apiFetch(`${getApiBase()}/solicitudes/${requestId}/asignar-compras`, {
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
