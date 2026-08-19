import { Badge } from '@/components/ui/Badge'
import { normalizeQuoteStatus } from '@/lib/quote-status'
import { QUOTE_STATUS_LABELS, type QuoteStatus } from '@/types'

const variantMap: Record<
  QuoteStatus,
  'default' | 'brand' | 'success' | 'warning' | 'danger' | 'muted'
> = {
  solicitud_cotizaciones: 'default',
  en_elaboracion: 'muted',
  pendiente_envio: 'warning',
  enviada: 'brand',
  modificacion: 'danger',
  aceptada: 'success',
  facturada: 'success',
}

export function QuoteStatusBadge({ status }: { status: QuoteStatus | string }) {
  const normalized = normalizeQuoteStatus(status)
  return <Badge variant={variantMap[normalized]}>{QUOTE_STATUS_LABELS[normalized]}</Badge>
}
