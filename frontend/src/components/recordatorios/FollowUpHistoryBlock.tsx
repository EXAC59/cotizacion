import { formatDate, formatDateTime } from '@/lib/format'
import { FOLLOW_UP_STATUS_LABELS, type QuoteFollowUpHistoryEntry } from '@/types'

export function FollowUpHistoryBlock({
  history,
  compact = false,
}: {
  history: QuoteFollowUpHistoryEntry[]
  compact?: boolean
}) {
  const items = compact ? history.slice(0, 2) : history

  if (items.length === 0) {
    return (
      <div className="rounded-xl border border-sky-200/80 bg-sky-50/50 p-3">
        <h3 className="text-sm font-semibold text-slate-900">Historial de seguimiento</h3>
        <p className="mt-1 text-xs text-slate-500">Aún no hay movimientos de ventas.</p>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-sky-200/80 bg-sky-50/50 p-3">
      <h3 className="text-sm font-semibold text-slate-900">Historial de seguimiento</h3>
      <ul className="mt-2 space-y-2">
        {items.map((ev) => {
          const from = ev.fromStatus
            ? FOLLOW_UP_STATUS_LABELS[ev.fromStatus]
            : 'Sin seguimiento'
          const to = FOLLOW_UP_STATUS_LABELS[ev.toStatus]
          let detail = ''
          if (ev.toStatus === 'negociacion' && ev.remindAt) {
            detail = `Fecha: ${formatDate(ev.remindAt)}`
          } else if (ev.toStatus === 'ganada' && ev.invoice) {
            detail = `Factura/ticket: ${ev.invoice}`
          } else if (ev.toStatus === 'perdida' && ev.comments) {
            detail = `Comentarios: ${ev.comments}`
          }
          return (
            <li key={ev.id} className="text-xs text-slate-700">
              <div>
                <strong>{ev.userName || 'Ventas'}</strong>{' '}
                <span>
                  {from} → {to}
                </span>
              </div>
              {detail ? <div className="mt-0.5 text-slate-600">{detail}</div> : null}
              <div className="mt-0.5 text-slate-400">{formatDateTime(ev.createdAt)}</div>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
