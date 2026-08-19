import { useCallback, useEffect, useRef, useState } from 'react'
import {
  dispatchComparison,
  jobToComparatorResult,
  pollComparisonJob,
} from '@/lib/comparator-api'
import type { ComparatorResult } from '@/types'

export type ComparatorContext = {
  screen?: string
  lineId?: string
  quoteId?: string
  requestId?: string
}

export function useProductComparator(params: {
  partNumber: string
  quantity?: number
  preferredWarehouse?: string
  enabled?: boolean
  context?: ComparatorContext
  /** @deprecated Ya no se autoaplica; se ignora (selección manual). */
  autoApplyBest?: boolean
  /** @deprecated Ya no se invoca al terminar la comparación. */
  onBestOffer?: (result: ComparatorResult) => void
}) {
  const {
    partNumber,
    quantity = 1,
    preferredWarehouse,
    enabled = true,
    context,
  } = params

  const [result, setResult] = useState<ComparatorResult | null>(null)
  const [status, setStatus] = useState<'idle' | 'procesando' | 'done' | 'error'>('idle')
  const [error, setError] = useState<string | null>(null)
  const abortRef = useRef<AbortController | null>(null)
  const runIdRef = useRef(0)
  const contextRef = useRef(context)
  const paramsRef = useRef({ partNumber, quantity, preferredWarehouse, enabled })

  useEffect(() => {
    contextRef.current = context
  }, [context])

  useEffect(() => {
    paramsRef.current = { partNumber, quantity, preferredWarehouse, enabled }
  }, [partNumber, quantity, preferredWarehouse, enabled])

  const skuKey = `${partNumber.trim()}|${quantity}|${preferredWarehouse ?? ''}|${enabled}`

  const refresh = useCallback(async () => {
    const {
      partNumber: currentPart,
      quantity: currentQty,
      preferredWarehouse: currentWarehouse,
      enabled: currentEnabled,
    } = paramsRef.current
    const sku = currentPart.trim()

    if (!currentEnabled || sku === '') {
      runIdRef.current += 1
      abortRef.current?.abort()
      setResult(null)
      setStatus('idle')
      setError(null)
      return
    }

    abortRef.current?.abort()
    const controller = new AbortController()
    abortRef.current = controller
    const runId = ++runIdRef.current

    setStatus('procesando')
    setError(null)
    setResult(null)

    try {
      const { jobId } = await dispatchComparison({
        partNumber: sku,
        quantity: currentQty,
        preferredWarehouse: currentWarehouse,
        context: contextRef.current as Record<string, string | undefined>,
      })

      if (controller.signal.aborted || runId !== runIdRef.current) {
        return
      }

      const job = await pollComparisonJob(jobId, {
        signal: controller.signal,
        timeoutMs: 25000,
      })

      if (controller.signal.aborted || runId !== runIdRef.current) {
        return
      }

      const finalResult = jobToComparatorResult(job)

      setResult(finalResult)
      setStatus('done')

      // No autoaplicar mejor oferta: el usuario elige en el inventario.
    } catch (err: unknown) {
      if (
        (err instanceof DOMException && err.name === 'AbortError') ||
        runId !== runIdRef.current
      ) {
        return
      }

      setStatus('error')
      setError(err instanceof Error ? err.message : 'Error al comparar precios')
      setResult(null)
    }
  }, [])

  useEffect(() => {
    const sku = partNumber.trim()
    if (!enabled || sku === '') {
      runIdRef.current += 1
      abortRef.current?.abort()
      setResult(null)
      setStatus('idle')
      setError(null)
      return
    }

    const timer = setTimeout(() => {
      void refresh()
    }, 400)

    return () => {
      clearTimeout(timer)
      // Solo abortar si cambia el skuKey (cleanup de este efecto), no por
      // identidad de callbacks como autoApplyBest.
      abortRef.current?.abort()
      runIdRef.current += 1
    }
  }, [skuKey, enabled, partNumber, refresh])

  return {
    result,
    status,
    loading: status === 'procesando',
    error,
    refresh,
  }
}
