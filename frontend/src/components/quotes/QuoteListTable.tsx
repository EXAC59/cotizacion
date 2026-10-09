import { QuoteReminderLink } from './QuoteReminderLink'
import { Link } from 'react-router-dom'
import { ArrowDownAZ, ArrowUpDown, FileDown } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { QuoteStatusBadge } from '@/components/ui/QuoteStatusBadge'
import { quoteTotals } from '@/lib/calculations'
import { formatCurrency, formatDateTime } from '@/lib/format'
import { openQuotePdf, isPersistedQuoteId } from '@/lib/quotes-api'
import type { Quote, QuoteLine } from '@/types'

export type QuoteTableRow = Pick<Quote, 'id' | 'folio' | 'clientName' | 'createdByName' | 'involucrado' | 'status' | 'total' | 'taxPercent' | 'createdAt' | 'elaborationDate' | 'editLock'> & {
  lines?: QuoteLine[]
  sentAt?: string | null
  viewedAt?: string | null
}

export function QuoteListTable({ rows, sortingByStatus, onToggleSort, showTracking = false, reminderQuoteIds }: {
  rows: QuoteTableRow[]
  sortingByStatus: boolean
  onToggleSort: () => void
  reminderQuoteIds?: ReadonlySet<string>
  showTracking?: boolean
}) {
  return (
    <div className="table-scroll-mobile [&_th]:whitespace-nowrap [&_td]:align-top">
      <table className="w-full text-left text-sm">
        <thead className="border-b border-slate-100 bg-slate-50 text-slate-500">
          <tr>
            <th className="px-5 py-3 font-medium">Folio</th>
            <th className="px-5 py-3 font-medium">Cliente</th>
            <th className="px-5 py-3 font-medium">Hecha por</th>
            <th className="px-5 py-3 font-medium">Involucrado</th>
            <th className="px-5 py-3 font-medium">
              <button
                type="button"
                className={`inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 transition hover:bg-slate-200/70 hover:text-slate-800 ${
                  sortingByStatus ? 'bg-indigo-50 text-indigo-700' : ''
                }`}
                title="Orden: Solicitud → Elaboración → Lista/Terminada → Enviada"
                aria-pressed={sortingByStatus}
                onClick={onToggleSort}
              >
                Estado
                {sortingByStatus ? (
                  <ArrowDownAZ className="h-3.5 w-3.5" aria-hidden />
                ) : (
                  <ArrowUpDown className="h-3.5 w-3.5" aria-hidden />
                )}
              </button>
            </th>
            <th className="px-5 py-3 font-medium text-right">Total</th>
            <th className="px-5 py-3 font-medium">Fecha de elaboración</th>
            <th className="px-5 py-3 font-medium text-right">PDF</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((q) => (
            <tr key={q.id} className="border-b border-slate-50 hover:bg-slate-50/80">
              <td className="whitespace-nowrap px-5 py-3">
                <Link
                  to={`/cotizaciones/${q.id}`}
                  className="font-medium text-indigo-600 hover:underline"
                >
                  {q.folio}
                </Link>
                {reminderQuoteIds?.has(q.id) && <div className="mt-2"><QuoteReminderLink id={q.id} folio={q.folio} /></div>}
                {showTracking && (
                  <p className={`mt-1 text-xs ${q.viewedAt ? 'text-slate-500' : 'font-medium text-indigo-700'}`}>
                    {q.viewedAt ? 'Abierta por ti' : 'Sin lectura registrada'}
                  </p>
                )}
              </td>
              <td className="px-5 py-3">{q.clientName}</td>
              <td className="px-5 py-3 font-medium text-slate-800">
                {q.createdByName?.trim() || 'Sin asignar'}
              </td>
              <td className="whitespace-pre-line px-5 py-3 text-slate-600">
                {q.involucrado ?? '—'}
                {q.status === 'modificacion' && !q.involucrado?.includes('Aviso:') && (
                  <span className="mt-1 block text-xs font-medium text-purple-700">
                    Aviso: cotización enviada en modificación.
                  </span>
                )}
              </td>
              <td className="px-5 py-3">
                <QuoteStatusBadge status={q.status} />
                {showTracking && <p className="mt-1 text-xs text-slate-500">{q.sentAt ? 'Enviada al cliente' : 'No enviada'}</p>}
                {q.editLock && !q.editLock.isOwn && (
                  <p className="mt-1 text-xs text-amber-700">
                    En seguimiento: {q.editLock.userName}
                  </p>
                )}
              </td>
              <td className="px-5 py-3 text-right font-medium">
                {formatCurrency(
                  q.total ?? quoteTotals(q.lines ?? [], q.taxPercent).total,
                )}
              </td>
              <td className="whitespace-nowrap px-5 py-3 text-slate-500">
                {q.elaborationDate
                  ? new Date(`${q.elaborationDate}T12:00:00`).toLocaleDateString('es-MX')
                  : formatDateTime(q.createdAt)}
              </td>
              <td className="px-5 py-3 text-right">
                <Button
                  aria-label={`Ver PDF de ${q.folio}`}
                  variant="secondary"
                  size="sm"
                  disabled={!isPersistedQuoteId(q.id)}
                  title={
                    isPersistedQuoteId(q.id)
                      ? 'Ver PDF'
                      : 'Guarda la cotización en el servidor para ver PDF'
                  }
                  onClick={() => void openQuotePdf(q.id)}
                >
                  <FileDown className="h-4 w-4" />
                </Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
