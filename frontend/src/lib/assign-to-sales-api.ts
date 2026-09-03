import { apiFetch } from '@/lib/api-fetch'
import { parseApiJsonOrThrow, type ApiErrorBody } from '@/lib/api-response'
import { getApiBase } from '@/lib/app-paths'

export type SalespersonOption = {
  id: number
  name: string
  folioCode?: string | null
}

export async function listSalespeople(): Promise<SalespersonOption[]> {
  const response = await apiFetch(`${getApiBase()}/usuarios/ventas`, {
    headers: { Accept: 'application/json' },
  })
  const data = await parseApiJsonOrThrow<{ data?: SalespersonOption[]; message?: string }>(response)
  return data.data ?? []
}

export async function assignQuoteToSales(
  quoteId: string,
  recipientId: number,
  message?: string,
): Promise<{ folio: string; previousFolio: string; id: string }> {
  const response = await apiFetch(`${getApiBase()}/cotizaciones/${quoteId}/asignar-ventas`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({
      recipientId,
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
  recipientId: number,
): Promise<{ folio: string | null; previousFolio: string | null; id: string }> {
  const response = await apiFetch(`${getApiBase()}/solicitudes/${requestId}/asignar-ventas`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ recipientId }),
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
