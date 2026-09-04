import { apiFetch } from '@/lib/api-fetch'
import {
  ApiRequestError,
  ClientRequiredApiError,
  formatApiErrorMessage,
  parseApiJson,
  parseApiJsonOrThrow,
} from '@/lib/api-response'
import { getApiBase } from '@/lib/app-paths'
import type {
  QuoteRequest,
  RequestLine,
  RequestStatus,
  RequestWorkflowStatus,
} from '@/types'

export type InterpretacionVia = 'parser'

export type LecturaLineaApi = {
  quantity: number
  product: string
  partNumber: string
  brand: string
  description: string
  unit: string
}

export type SolicitudApi = {
  id: string
  folio?: string | null
  client_id: string | null
  client_name?: string | null
  created_by?: string | null
  created_by_name?: string | null
  assigned_to_sales?: boolean
  assigned_to_compras?: boolean
  needs_external_review?: boolean
  reviewed_by?: string | null
  reviewed_by_name?: string | null
  reviewed_at?: string | null
  source: QuoteRequest['source']
  status: RequestStatus
  workflow_status?: RequestWorkflowStatus | null
  workflow_status_label?: string | null
  file_name?: string | null
  raw_text?: string | null
  interpretacion_via?: InterpretacionVia | null
  error_message?: string | null
  involucrado?: string | null
  created_at: string
  updated_at?: string
  lectura_at?: string | null
  lineas: Array<{
    id: string
    quantity: number
    product: string
    partNumber: string
    brand: string
    description: string
    unit: string
    referenceCost?: number | null
    selectedWholesalerId?: string | null
    warehouse?: string | null
  }>
  lineas_count: number
}

export type SolicitudCotizacionApi = {
  id: string
  folio: string
  status: string
  total: number
  createdAt: string
  clientName: string
}

export type LecturaVia = 'docling' | 'n8n'

export type LecturaProcessingMode = 'n8n' | 'docling' | 'docling-fallback'

function mapLineaApiToRequestLine(
  line: SolicitudApi['lineas'][number],
  index: number,
): RequestLine {
  return {
    id: line.id || `pl${index + 1}`,
    quantity: line.quantity,
    product: line.product,
    partNumber: line.partNumber,
    brand: line.brand,
    description: line.description,
    unit: line.unit,
    referenceCost: line.referenceCost ?? undefined,
    selectedWholesalerId: line.selectedWholesalerId ?? undefined,
    warehouse: line.warehouse ?? undefined,
  }
}

export function mapSolicitudApiToQuoteRequest(
  api: SolicitudApi,
  clientName?: string,
): QuoteRequest {
  const lines = api.lineas.map(mapLineaApiToRequestLine)

  return {
    id: api.id,
    folio: api.folio ?? undefined,
    clientId: api.client_id,
    clientName: clientName ?? api.client_name ?? undefined,
    createdBy: api.created_by ?? null,
    createdByName: api.created_by_name ?? undefined,
    assignedToSales: api.assigned_to_sales ?? false,
    assignedToCompras: api.assigned_to_compras ?? false,
    needsExternalReview: api.needs_external_review ?? false,
    reviewedBy: api.reviewed_by ?? null,
    reviewedByName: api.reviewed_by_name ?? undefined,
    reviewedAt: api.reviewed_at ?? undefined,
    source: api.source,
    fileName: api.file_name ?? undefined,
    rawText: api.raw_text ?? undefined,
    status: api.status,
    workflowStatus: api.workflow_status ?? undefined,
    createdAt: api.created_at,
    updatedAt: api.updated_at,
    lecturaAt: api.lectura_at ?? undefined,
    lines,
    previewLines: lines,
    interpretacionVia: api.interpretacion_via ?? undefined,
    errorMessage: api.error_message ?? undefined,
    involucrado: api.involucrado ?? undefined,
  }
}

export function mapLecturaLineasToRequestLines(
  lineas: LecturaLineaApi[],
  idPrefix = 'pl',
): RequestLine[] {
  return lineas.map((line, index) => ({
    id: `${idPrefix}${index + 1}`,
    quantity: line.quantity,
    product: line.product,
    partNumber: line.partNumber,
    brand: line.brand,
    description: line.description ?? line.product,
    unit: line.unit ?? 'pza',
  }))
}

const jsonHeaders = { Accept: 'application/json' } as const

export async function listSolicitudes(params: {
  status?: RequestStatus
  workflowStatus?: RequestWorkflowStatus
  q?: string
  scope?: 'mine' | 'all'
  from?: string
  to?: string
} = {}): Promise<SolicitudApi[]> {
  const search = new URLSearchParams()
  if (params.status) search.set('status', params.status)
  if (params.workflowStatus) search.set('workflow_status', params.workflowStatus)
  if (params.q?.trim()) search.set('q', params.q.trim())
  if (params.scope) search.set('scope', params.scope)
  if (params.from) search.set('from', params.from)
  if (params.to) search.set('to', params.to)

  const query = search.toString()
  const url = `${getApiBase()}/solicitudes${query ? `?${query}` : ''}`
  const response = await apiFetch(url, { headers: jsonHeaders })
  const data = await parseApiJsonOrThrow<{ data?: SolicitudApi[]; message?: string }>(response)

  return data.data ?? []
}

export async function getSolicitud(id: string): Promise<SolicitudApi> {
  const response = await apiFetch(`${getApiBase()}/solicitudes/${id}`, { headers: jsonHeaders })
  return parseApiJsonOrThrow<SolicitudApi & { message?: string }>(response)
}

export async function listCotizacionesBySolicitud(id: string): Promise<SolicitudCotizacionApi[]> {
  const response = await apiFetch(`${getApiBase()}/solicitudes/${id}/cotizaciones`, {
    headers: jsonHeaders,
  })
  const data = await parseApiJsonOrThrow<{ data?: SolicitudCotizacionApi[]; message?: string }>(
    response,
  )

  return data.data ?? []
}

export async function reprocesarSolicitud(
  id: string,
  options: { do_ocr?: boolean } = {},
): Promise<SolicitudApi> {
  const response = await apiFetch(`${getApiBase()}/solicitudes/${id}/reprocesar`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(options),
  })

  return parseApiJsonOrThrow<SolicitudApi & { message?: string }>(response)
}

export async function pollSolicitud(
  id: string,
  options: {
    intervalMs?: number
    maxAttempts?: number
    signal?: AbortSignal
  } = {},
): Promise<SolicitudApi> {
  const intervalMs = options.intervalMs ?? 2000
  const maxAttempts = options.maxAttempts ?? 90

  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    if (options.signal?.aborted) {
      throw new DOMException('Polling cancelado', 'AbortError')
    }

    const solicitud = await getSolicitud(id)

    if (solicitud.status === 'procesada' || solicitud.status === 'error') {
      return solicitud
    }

    await new Promise((resolve, reject) => {
      const timer = setTimeout(resolve, intervalMs)
      options.signal?.addEventListener(
        'abort',
        () => {
          clearTimeout(timer)
          reject(new DOMException('Polling cancelado', 'AbortError'))
        },
        { once: true },
      )
    })
  }

  throw new Error('Tiempo de espera agotado. La solicitud sigue en procesamiento.')
}

export async function createSolicitudFromText(payload: {
  raw_text: string
  client_id?: string | null
  lineas?: Array<{
    quantity: number
    product: string
    partNumber: string
    brand: string
    description: string
    unit: string
  }>
}): Promise<SolicitudApi & { request_id: string; interpretacion_via?: InterpretacionVia }> {
  const response = await apiFetch(`${getApiBase()}/solicitudes`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(payload),
  })

  const data = await parseApiJsonOrThrow<
    SolicitudApi & {
      message?: string
      request_id?: string
      interpretacion_via?: InterpretacionVia
    }
  >(response)

  const requestId = data.request_id ?? data.id

  return { ...data, id: requestId, request_id: requestId }
}

export type LecturaDoclingResponse = {
  message: string
  modo: 'docling'
  request_id: string
  archivo: string
  interpretacion_via?: InterpretacionVia
  do_ocr?: boolean
  markdown?: string
  lineas: LecturaLineaApi[]
  lineas_count?: number
  solicitud?: SolicitudApi
}

export type LecturaN8nResponse = {
  message: string
  modo: 'n8n'
  request_id: string
  archivo: string
  status: 'procesando'
}

async function uploadSolicitudLecturaOnce(
  file: File,
  options: {
    via: LecturaVia
    client_id: string
    do_ocr?: boolean
    signal?: AbortSignal
  },
): Promise<LecturaDoclingResponse | LecturaN8nResponse> {
  const form = new FormData()
  form.append('archivo', file)
  form.append('via', options.via)
  form.append('client_id', options.client_id)
  if (options.do_ocr !== undefined) {
    form.append('do_ocr', options.do_ocr ? '1' : '0')
  }

  const response = await apiFetch(`${getApiBase()}/solicitudes/lectura`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
    body: form,
    signal: options.signal,
  })

  const data = await parseApiJson<LecturaDoclingResponse | LecturaN8nResponse & { message?: string; errors?: Record<string, string[] | string> }>(response)

  if (!response.ok) {
    if (data && typeof data === 'object' && 'errors' in data && data.errors?.client_id) {
      throw new ClientRequiredApiError()
    }
    throw new ApiRequestError(
      formatApiErrorMessage(data ?? {}, response.status),
      response.status,
      data && typeof data === 'object' && 'errors' in data ? data.errors : undefined,
    )
  }

  return data
}

async function uploadSolicitudLecturaTextoOnce(
  rawText: string,
  options: {
    via: LecturaVia
    client_id: string
    signal?: AbortSignal
  },
): Promise<LecturaDoclingResponse | LecturaN8nResponse> {
  const response = await apiFetch(`${getApiBase()}/solicitudes/lectura-texto`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({
      raw_text: rawText,
      via: options.via,
      client_id: options.client_id,
    }),
    signal: options.signal,
  })

  const data = await parseApiJson<LecturaDoclingResponse | LecturaN8nResponse & { message?: string; errors?: Record<string, string[] | string> }>(response)

  if (!response.ok) {
    if (data && typeof data === 'object' && 'errors' in data && data.errors?.client_id) {
      throw new ClientRequiredApiError()
    }
    throw new ApiRequestError(
      formatApiErrorMessage(data ?? {}, response.status),
      response.status,
      data && typeof data === 'object' && 'errors' in data ? data.errors : undefined,
    )
  }

  return data
}

export async function uploadSolicitudLecturaTexto(
  rawText: string,
  options: {
    via?: LecturaVia
    client_id?: string | null
    signal?: AbortSignal
    resilient?: boolean
  } = {},
): Promise<
  (LecturaDoclingResponse | LecturaN8nResponse) & { processingMode?: LecturaProcessingMode }
> {
  if (!options.client_id) {
    throw new ClientRequiredApiError()
  }

  const via = options.via ?? 'n8n'
  const resilient = options.resilient ?? via === 'n8n'

  if (!resilient) {
    const result = await uploadSolicitudLecturaTextoOnce(rawText, {
      via,
      client_id: options.client_id,
      signal: options.signal,
    })

    return { ...result, processingMode: via }
  }

  try {
    const result = await uploadSolicitudLecturaTextoOnce(rawText, {
      via: 'n8n',
      client_id: options.client_id,
      signal: options.signal,
    })

    return { ...result, processingMode: 'n8n' }
  } catch (err: unknown) {
    if (
      err instanceof ApiRequestError &&
      [404, 502, 503].includes(err.status)
    ) {
      const result = await uploadSolicitudLecturaTextoOnce(rawText, {
        via: 'docling',
        client_id: options.client_id,
        signal: options.signal,
      })

      return { ...result, processingMode: 'docling-fallback' }
    }

    throw err
  }
}

export async function uploadSolicitudLectura(
  file: File,
  options: {
    via?: LecturaVia
    client_id?: string | null
    do_ocr?: boolean
    signal?: AbortSignal
    resilient?: boolean
  } = {},
): Promise<
  (LecturaDoclingResponse | LecturaN8nResponse) & { processingMode?: LecturaProcessingMode }
> {
  if (!options.client_id) {
    throw new ClientRequiredApiError()
  }

  const via = options.via ?? 'docling'
  const resilient = options.resilient ?? via === 'n8n'

  if (!resilient) {
    const result = await uploadSolicitudLecturaOnce(file, {
      via,
      client_id: options.client_id,
      do_ocr: options.do_ocr,
      signal: options.signal,
    })

    return { ...result, processingMode: via }
  }

  try {
    const result = await uploadSolicitudLecturaOnce(file, {
      via: 'n8n',
      client_id: options.client_id,
      do_ocr: options.do_ocr,
      signal: options.signal,
    })

    return { ...result, processingMode: 'n8n' }
  } catch (err: unknown) {
    if (
      err instanceof ApiRequestError &&
      [404, 502, 503].includes(err.status)
    ) {
      const result = await uploadSolicitudLecturaOnce(file, {
        via: 'docling',
        client_id: options.client_id,
        do_ocr: options.do_ocr,
        signal: options.signal,
      })

      return { ...result, processingMode: 'docling-fallback' }
    }

    throw err
  }
}

export type SolicitudLineaPayload = {
  quantity: number
  product: string
  partNumber: string
  brand: string
  description: string
  unit: string
  referenceCost?: number | null
  selectedWholesalerId?: string | null
  warehouse?: string | null
}

export function requestLineToPayload(line: RequestLine): SolicitudLineaPayload {
  return {
    quantity: line.quantity,
    product: line.product,
    partNumber: line.partNumber,
    brand: line.brand.trim() || 'Genérico',
    description: line.description ?? line.product,
    unit: line.unit ?? 'pza',
    referenceCost: line.referenceCost ?? null,
    selectedWholesalerId: line.selectedWholesalerId ?? null,
    warehouse: line.warehouse ?? null,
  }
}

/** Partida en blanco (solo agregada): no se envía al guardar. */
export function isBlankRequestDraftLine(line: RequestLine): boolean {
  return line.product.trim() === '' && line.partNumber.trim() === ''
}

export async function updateSolicitudLineas(
  id: string,
  lineas: SolicitudLineaPayload[],
  workflowStatus: Extract<RequestWorkflowStatus, 'en_elaboracion' | 'pendiente_envio'>,
): Promise<SolicitudApi> {
  const response = await apiFetch(`${getApiBase()}/solicitudes/${id}/lineas`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ lineas, workflow_status: workflowStatus }),
  })

  return parseApiJsonOrThrow<SolicitudApi & { message?: string }>(response)
}

export function formatSolicitudValidationErrors(
  errors?: Record<string, string[] | string>,
): string[] {
  if (!errors) return []

  const messages: string[] = []
  const columnas = errors.columnas_faltantes

  if (Array.isArray(columnas) && columnas.length > 0) {
    messages.push(`Columnas faltantes en el Excel: ${columnas.join(', ')}`)
  }

  for (const [key, value] of Object.entries(errors)) {
    if (key === 'columnas_faltantes') continue
    if (Array.isArray(value)) {
      value.forEach((item) => messages.push(item))
    } else if (typeof value === 'string') {
      messages.push(value)
    }
  }

  return messages
}
