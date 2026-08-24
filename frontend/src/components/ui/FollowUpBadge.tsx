import { Badge } from '@/components/ui/Badge'
import { FOLLOW_UP_STATUS_LABELS, type FollowUpStatus } from '@/types'

const variantMap: Record<FollowUpStatus, 'brand' | 'success' | 'danger'> = {
  negociacion: 'brand',
  ganada: 'success',
  perdida: 'danger',
}

/** Chip secundario de recordatorios (no confundir con QuoteStatusBadge). */
export function FollowUpBadge({ status }: { status: FollowUpStatus }) {
  return <Badge variant={variantMap[status]}>{FOLLOW_UP_STATUS_LABELS[status]}</Badge>
}
