import { useEffect, useState } from 'react'
import { X } from 'lucide-react'
import { FollowUpHistoryBlock } from '@/components/recordatorios/FollowUpHistoryBlock'
import { QuoteFollowUpPanel } from '@/components/quotes/QuoteFollowUpPanel'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { FollowUpBadge } from '@/components/ui/FollowUpBadge'
import { InlineBusy } from '@/components/ui/LoadingState'
import { QuoteStatusBadge } from '@/components/ui/QuoteStatusBadge'
import { formatCurrency, formatDate } from '@/lib/format'
import { notifySalesAboutQuote } from '@/lib/notifications-api'
import { getQuoteById, isPersistedQuoteId, openQuotePdf } from '@/lib/quotes-api'
import type { Quote } from '@/types'

function needsRemindAgain(quote: Quote): boolean {
  const fu = quote.followUp
  if (!fu || fu.status !== 'negociacion' || !fu.remindAt) return false
  return fu.remindAt.slice(0, 10) <= new Date().toISOString().slice(0, 10)
}

export function RecordatorioDetailPanel({
  quoteId,
  summary,
  comprasView,
  canEdit,
  canNotify,
  onClose,
  onSaved,
  forceNegociacion = false,
}: {
  quoteId: string
  summary: Quote
  comprasView: boolean
  canEdit: boolean
  canNotify: boolean
  onClose: () => void
  onSaved: () => void
  forceNegociacion?: boolean
}) {
  const [quote, setQuote] = useState<Quote | null>(null)
  const [loading, setLoading] = useState(true)
  const [notifyMsg, setNotifyMsg] = useState('')
  const [notifying, setNotifying] = useState(false)
  const [notifyError, setNotifyError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    void getQuoteById(quoteId)
      .then((full) => {
        if (!cancelled) setQuote(full)
      })
      .catch(() => {
        if (!cancelled) setQuote({ ...summary, lines: summary.lines ?? [] })
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [quoteId, summary])

  const data = quote ?? summary
  const fu = data.followUp
  const eligibility = data.eligibility ?? summary.eligibility
  const history = data.followUpHistory ?? []
  const pending = Boolean(eligibility?.pendingUnread)
  const allowNotify =
    comprasView && canNotify && eligibility?.eligible && !pending

  const handleNotify = async () => {
    setNotifying(true)
    setNotifyError(null)
    try {
      await notifySalesAboutQuote(quoteId, notifyMsg.trim() || undefined)
      onSaved()
    } catch (e) {
      setNotifyError(e instanceof Error ? e.message : 'No se pudo avisar.')
    } finally {
      setNotifying(false)
    }
  }

  const handleFollowUpSaved = async () => {
    const fresh = await getQuoteById(quoteId)
    setQuote(fresh)
    onSaved()
  }

  return (
    <aside className="sticky top-20 flex max-h-[calc(100vh-6rem)] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-lg">
      <header className="flex shrink-0 items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
        <h2 className="truncate font-semibold text-slate-900">{data.folio}</h2>
        <Button type="button" variant="ghost" size="sm" onClick={onClose}>
          <X className="h-4 w-4" />
          Cerrar
        </Button>
      </header>

      <div className="flex-1 space-y-4 overflow-y-auto p-4">
        {loading ? (
          <InlineBusy label="Cargando detalle…" />
        ) : (
          <>
            <FollowUpHistoryBlock history={history} />

            <dl className="grid gap-2 text-sm">
              <div className="flex flex-wrap gap-x-2">
                <dt className="font-medium text-slate-700">Cliente:</dt>
                <dd className="text-slate-900">{data.clientName || '—'}</dd>
              </div>
              <div className="flex flex-wrap gap-x-2">
                <dt className="font-medium text-slate-700">Monto:</dt>
                <dd className="text-slate-900">{formatCurrency(data.total ?? 0)}</dd>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <dt className="font-medium text-slate-700">Estado de cotización:</dt>
                <dd>
                  <QuoteStatusBadge status={data.status} />
                </dd>
              </div>
              <div className="flex flex-wrap gap-x-2">
                <dt className="font-medium text-slate-700">Días sin avance:</dt>
                <dd className="text-slate-900">{eligibility?.daysIdle ?? 0}</dd>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <dt className="font-medium text-slate-700">Seguimiento:</dt>
                <dd className="flex flex-wrap items-center gap-1">
                  {fu?.status ? (
                    <FollowUpBadge status={fu.status} />
                  ) : (
                    <span className="text-slate-500">Sin seguimiento</span>
                  )}
                  {needsRemindAgain(data) ? (
                    <Badge variant="warning">Recordar de nuevo</Badge>
                  ) : null}
                </dd>
              </div>
              {fu?.invoice ? (
                <div className="flex flex-wrap gap-x-2">
                  <dt className="font-medium text-slate-700">Factura/ticket:</dt>
                  <dd className="text-slate-900">{fu.invoice}</dd>
                </div>
              ) : null}
              {fu?.comments ? (
                <div className="flex flex-wrap gap-x-2">
                  <dt className="font-medium text-slate-700">Comentarios:</dt>
                  <dd className="text-slate-900">{fu.comments}</dd>
                </div>
              ) : null}
              {fu?.status === 'negociacion' && fu.remindAt ? (
                <div className="flex flex-wrap gap-x-2">
                  <dt className="font-medium text-slate-700">Fecha agendada:</dt>
                  <dd className="text-slate-900">{formatDate(fu.remindAt)}</dd>
                </div>
              ) : null}
              {fu?.byUser ? (
                <div className="flex flex-wrap gap-x-2">
                  <dt className="font-medium text-slate-700">Último seguimiento por:</dt>
                  <dd className="text-slate-900">{fu.byUser}</dd>
                </div>
              ) : null}
            </dl>

            <Button
              type="button"
              variant="secondary"
              size="sm"
              disabled={!isPersistedQuoteId(quoteId)}
              onClick={() => void openQuotePdf(quoteId)}
            >
              Ver PDF de la cotización
            </Button>

            {comprasView && (
              <div className="space-y-3 rounded-xl border border-slate-200 bg-slate-50/80 p-3">
                {eligibility?.eligible ? (
                  pending ? (
                    <p className="text-xs text-amber-700">
                      Ya hay un aviso pendiente (sin leer).
                    </p>
                  ) : (
                    <p className="text-xs text-slate-600">
                      Motivo del aviso:{' '}
                      <strong>
                        {eligibility.reasonCode === 'lista_terminada'
                          ? 'Lista / Terminada'
                          : eligibility.reasonCode === 'sin_avance'
                            ? 'Sin avance en elaboración'
                            : 'Aviso'}
                      </strong>
                    </p>
                  )
                ) : (
                  <p className="text-xs text-slate-500">{eligibility?.blockReason}</p>
                )}
                <label className="block text-xs font-medium text-slate-700">
                  Mensaje para ventas {allowNotify ? '(opcional)' : ''}
                  <textarea
                    className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm disabled:opacity-60"
                    rows={3}
                    disabled={!allowNotify}
                    value={notifyMsg}
                    onChange={(e) => setNotifyMsg(e.target.value)}
                    placeholder={
                      allowNotify
                        ? 'Lista/Terminada: lista para que ventas continúe.'
                        : 'No disponible'
                    }
                  />
                </label>
                {notifyError ? (
                  <p className="text-xs text-amber-800">{notifyError}</p>
                ) : null}
                <Button
                  type="button"
                  size="sm"
                  disabled={!allowNotify || notifying}
                  onClick={() => void handleNotify()}
                >
                  {notifying ? 'Enviando…' : 'Avisar a ventas'}
                </Button>
              </div>
            )}

            {!comprasView && canEdit && isPersistedQuoteId(quoteId) && (
              <QuoteFollowUpPanel
                quoteId={quoteId}
                initialFollowUp={fu ?? null}
                initialHistory={history}
                initialEligibility={eligibility ?? null}
                canEdit
                canNotifySales={false}
                embedded
                detailMode
                showHistory={false}
                forceNegociacion={forceNegociacion}
                onSaved={() => void handleFollowUpSaved()}
              />
            )}
          </>
        )}
      </div>
    </aside>
  )
}
