import { normalizeQuoteStatus } from '@/lib/quote-status'
import { QUOTE_STATUS_LABELS, type QuoteStatus } from '@/types'

/** Colores de estatus: rojo / naranja / amarillo / verde. */
export const QUOTE_STATUS_TONE: Record<QuoteStatus, string> = {
  solicitud_cotizaciones: 'bg-red-600 text-white ring-1 ring-red-700',
  en_elaboracion: 'bg-orange-500 text-white ring-1 ring-orange-600',
  pendiente_envio: 'bg-yellow-400 text-yellow-950 ring-1 ring-yellow-500',
  enviada: 'bg-green-600 text-white ring-1 ring-green-700',
  modificacion: 'bg-red-600 text-white ring-1 ring-red-700',
  aceptada: 'bg-green-600 text-white ring-1 ring-green-700',
  facturada: 'bg-green-700 text-white ring-1 ring-green-800',
}

export const QUOTE_STATUS_STEP_TONE: Record<
  QuoteStatus,
  { active: string; done: string }
> = {
  solicitud_cotizaciones: {
    active: 'border-red-500 bg-red-50 font-semibold text-red-900',
    done: 'border-red-200 bg-red-50 text-red-800',
  },
  en_elaboracion: {
    active: 'border-orange-500 bg-orange-50 font-semibold text-orange-900',
    done: 'border-orange-200 bg-orange-50 text-orange-800',
  },
  pendiente_envio: {
    active: 'border-yellow-400 bg-yellow-50 font-semibold text-yellow-950',
    done: 'border-yellow-200 bg-yellow-50 text-yellow-800',
  },
  enviada: {
    active: 'border-green-600 bg-green-50 font-semibold text-green-900',
    done: 'border-green-200 bg-green-50 text-green-800',
  },
  modificacion: {
    active: 'border-red-500 bg-red-50 font-semibold text-red-900',
    done: 'border-red-200 bg-red-50 text-red-800',
  },
  aceptada: {
    active: 'border-green-600 bg-green-50 font-semibold text-green-900',
    done: 'border-green-200 bg-green-50 text-green-800',
  },
  facturada: {
    active: 'border-green-700 bg-green-50 font-semibold text-green-900',
    done: 'border-green-200 bg-green-50 text-green-800',
  },
}

export function QuoteStatusBadge({ status }: { status: QuoteStatus | string }) {
  const normalized = normalizeQuoteStatus(status)
  return (
    <span
      className={`inline-flex shrink-0 items-center whitespace-nowrap rounded-lg px-2.5 py-0.5 text-xs font-medium ${QUOTE_STATUS_TONE[normalized]}`}
    >
      {QUOTE_STATUS_LABELS[normalized]}
    </span>
  )
}
