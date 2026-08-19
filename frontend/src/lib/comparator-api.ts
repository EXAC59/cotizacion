import { apiFetch } from '@/lib/api-fetch'
import { getApiBase } from '@/lib/app-paths'
import type { ComparatorResult, WholesalerOffer } from '@/types'

export type ComparisonJobApi = ComparatorResult & {
  id: string
  status: 'procesando' | 'done' | 'error'
  context?: Record<string, unknown>
  errorMessage?: string | null
  notFoundSummary?: string | null
  notFound?: ComparatorResult['notFound']
  createdAt?: string
  updatedAt?: string
}

const POLL_INTERVAL_MS = 2000

function isTechnicalFailureMessage(message: string): boolean {
  const m = message.toLowerCase()
  return (
    m.includes('tiempo de espera') ||
    m.includes('timeout') ||
    m.includes('network') ||
    m.includes('failed to fetch') ||
    m.includes('error al disparar') ||
    m.includes('error al consultar') ||
    m.includes('sin jobid') ||
    m.includes('500') ||
    m.includes('503')
  )
}

export async function dispatchComparison(params: {
  partNumber: string
  quantity?: number
  preferredWarehouse?: string
  preferredWarehouses?: string[]
  context?: Record<string, string | undefined>
}): Promise<{ jobId: string; status: string }> {
  const fromCsv = (params.preferredWarehouse ?? '')
    .split(/[,\s]+/)
    .map((w) => w.trim().toUpperCase())
    .filter(Boolean)
  const list =
    params.preferredWarehouses && params.preferredWarehouses.length > 0
      ? params.preferredWarehouses.map((w) => w.trim().toUpperCase()).filter(Boolean)
      : fromCsv

  const response = await apiFetch(`${getApiBase()}/comparador/disparar`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({
      partNumber: params.partNumber,
      quantity: params.quantity ?? 1,
      preferredWarehouse: list[0] ?? params.preferredWarehouse,
      preferredWarehouses: list.length > 0 ? list : undefined,
      context: params.context,
    }),
  })

  const data = (await response.json()) as { jobId?: string; status?: string; message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al disparar comparador (${response.status})`)
  }

  if (!data.jobId) {
    throw new Error('Respuesta sin jobId')
  }

  return { jobId: data.jobId, status: data.status ?? 'procesando' }
}

export async function getComparisonJob(jobId: string): Promise<ComparisonJobApi> {
  const response = await apiFetch(`${getApiBase()}/comparador/${jobId}`)
  const data = (await response.json()) as ComparisonJobApi & { message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al consultar job (${response.status})`)
  }

  return data
}

export async function pollComparisonJob(
  jobId: string,
  options?: { signal?: AbortSignal; timeoutMs?: number },
): Promise<ComparisonJobApi> {
  const timeoutMs = options?.timeoutMs ?? 60000
  const started = Date.now()

  while (Date.now() - started < timeoutMs) {
    if (options?.signal?.aborted) {
      throw new DOMException('Poll cancelado', 'AbortError')
    }

    const job = await getComparisonJob(jobId)

    if (job.status === 'done') {
      return job
    }

    if (job.status === 'error') {
      const msg = job.errorMessage || 'Error en comparación'
      if (!isTechnicalFailureMessage(msg) && /no se encontr|sin existencia|sin oferta/i.test(msg)) {
        return { ...job, status: 'done', offers: job.offers ?? [], best: null }
      }
      throw new Error(msg)
    }

    await new Promise<void>((resolve, reject) => {
      const timer = setTimeout(resolve, POLL_INTERVAL_MS)
      options?.signal?.addEventListener(
        'abort',
        () => {
          clearTimeout(timer)
          reject(new DOMException('Poll cancelado', 'AbortError'))
        },
        { once: true },
      )
    })
  }

  throw new Error('Tiempo de espera agotado al comparar precios')
}

export function jobToComparatorResult(job: ComparisonJobApi): ComparatorResult {
  const offers = ((job.offers ?? []) as WholesalerOffer[]).filter((o) => {
    const id = String(o.wholesalerId ?? '')
    const name = String(o.wholesalerName ?? '').toLowerCase()
    if (id.startsWith('demo-')) return false
    if (name.includes('ingram') || name.includes('exel del norte')) return false
    if (Number(o.stock ?? 0) <= 0) return false
    return true
  })

  const best =
    offers.find((o) => o.isBest) ??
    offers[0] ??
    null

  const ctxNotFound = Array.isArray(job.context?.notFound)
    ? (job.context?.notFound as ComparatorResult['notFound'])
    : []
  const rawNotFound = (job.notFound && job.notFound.length > 0 ? job.notFound : ctxNotFound) ?? []
  const offerCodes = new Set(
    offers
      .map((o) => String(o.wholesalerCode ?? '').toUpperCase())
      .filter((c) => c !== ''),
  )
  // No mostrar «sin existencias» de un mayorista que sí trae oferta usable.
  const notFound = rawNotFound.filter(
    (row) => !offerCodes.has(String(row.wholesalerCode ?? '').toUpperCase()),
  )

  const sku = (job.partNumber ?? '').trim()
  const summary =
    job.notFoundSummary?.trim() ||
    (offers.length === 0
      ? job.errorMessage?.trim() ||
        (sku
          ? `Sin existencias de «${sku}».`
          : null)
      : null)

  return {
    partNumber: job.partNumber,
    quantity: job.quantity,
    preferredWarehouse: job.preferredWarehouse,
    offers,
    best,
    demoMode: false,
    errorMessage: null,
    notFoundSummary: summary,
    notFound,
  }
}
