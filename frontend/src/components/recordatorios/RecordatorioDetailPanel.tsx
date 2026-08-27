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
        if (!cancelled) {
          setQuote({
            ...full,
            // Conservar total del listado si el detalle no lo trae
            total: full.total ?? summary.total,
            followUp: full.followUp ?? summary.followUp,
            eligibility: full.eligibility ?? summary.eligibility,
          })
        }
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
    // Solo al cambiar de cotización; summary se usa como respaldo en catch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [quoteId])

  const data = quote ?? summary
  /** Detalle a veces llega sin total en el mapper; no perder el del listado. */
  const displayTotal = data.total ?? summary.total ?? 0
  const fu = data.followUp ?? summary.followUp
  const eligibility = data.eligibility ?? summary.eligibility
  const history = data.followUpHistory ?? []
  const pending = Boolean(eligibility?.pendingUnread)
  const recipientName =
    eligibility?.notifyRecipientName || summary.createdByName || 'ventas'
  const canSendComment =
    comprasView && canNotify && isPersistedQuoteId(quoteId) && Boolean(recipientName)

  const handleNotify = async () => {
    const text = notifyMsg.trim()
    if (text.length < 3) {
      setNotifyError('Escribe un comentario sobre el recordatorio (mínimo 3 caracteres).')
      return
    }
    setNotifying(true)
    setNotifyError(null)
    try {
      await notifySalesAboutQuote(quoteId, text)
      setNotifyMsg('')
      onSaved()
    } catch (e) {
      setNotifyError(e instanceof Error ? e.message : 'No se pudo enviar el comentario.')
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
                <dd className="text-slate-900">{formatCurrency(displayTotal)}</dd>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <dt className="font-medium text-slate-700">Estado de cotización:</dt>
                <dd>
                  <QuoteStatusBadge status={data.status} />
                </dd>
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
              <div className="flex flex-wrap gap-x-2">
                <dt className="font-medium text-slate-700">Días sin avance:</dt>
                <dd className="text-slate-900">{eligibility?.daysIdle ?? 0}</dd>
              </div>
              {(data.createdByName || eligibility?.notifyRecipientName) && (
                <div className="flex flex-wrap gap-x-2">
                  <dt className="font-medium text-slate-700">
                    {comprasView ? 'Avisar a:' : 'Creada por:'}
                  </dt>
                  <dd className="text-slate-900">
                    {eligibility?.notifyRecipientName || data.createdByName || '—'}
                  </dd>
                </div>
              )}
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

            {comprasView ? (
              <p className="text-xs text-slate-500">
                Compras ve el seguimiento y envía comentarios; solo ventas marca Negociación /
                Ganada / Perdida.
              </p>
            ) : null}

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
              <div className="space-y-3 rounded-xl border border-indigo-200 bg-indigo-50/40 p-3">
                <div>
                  <h3 className="text-sm font-semibold text-slate-900">
                    Comentario sobre el recordatorio
                  </h3>
                  <p className="mt-0.5 text-xs text-slate-600">
                    Se envía a{' '}
                    <strong>{recipientName}</strong>
                    {pending ? ' (hay un aviso sin leer; se actualizará el mensaje).' : '.'}
                  </p>
                </div>
                {eligibility?.eligible ? (
                  <p className="text-xs text-slate-600">
                    Contexto:{' '}
                    <strong>
                      {eligibility.reasonCode === 'lista_terminada'
                        ? 'Lista / Terminada'
                        : eligibility.reasonCode === 'sin_avance'
                          ? 'Sin avance en elaboración'
                          : 'Recordatorio'}
                    </strong>
                  </p>
                ) : eligibility?.blockReason ? (
                  <p className="text-xs text-slate-500">
                    {eligibility.blockReason} Puedes dejar un comentario de todas formas.
                  </p>
                ) : null}
                <label className="block text-xs font-medium text-slate-700">
                  Tu comentario para {recipientName}
                  <textarea
                    className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm disabled:opacity-60"
                    rows={4}
                    disabled={!canSendComment || notifying}
                    value={notifyMsg}
                    onChange={(e) => setNotifyMsg(e.target.value)}
                    placeholder="Ej. Cliente ya confirmó stock; da seguimiento al recordatorio de esta cotización."
                  />
                </label>
                {notifyError ? (
                  <p className="text-xs text-amber-800">{notifyError}</p>
                ) : null}
                <Button
                  type="button"
                  size="sm"
                  disabled={!canSendComment || notifying || notifyMsg.trim().length < 3}
                  onClick={() => void handleNotify()}
                >
                  {notifying
                    ? 'Enviando…'
                    : `Enviar comentario a ${recipientName}`}
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
