import { useEffect, useState } from 'react'
import { Bell, Save } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { FollowUpBadge } from '@/components/ui/FollowUpBadge'
import { Input, Label, Textarea } from '@/components/ui/Input'
import { InlineBusy } from '@/components/ui/LoadingState'
import { formatDateTime } from '@/lib/format'
import {
  notifySalesAboutQuote,
  updateQuoteFollowUp,
} from '@/lib/notifications-api'
import { getQuoteById } from '@/lib/quotes-api'
import {
  FOLLOW_UP_STATUS_LABELS,
  type FollowUpStatus,
  type QuoteFollowUp,
  type QuoteFollowUpHistoryEntry,
  type QuoteNotifyEligibility,
} from '@/types'

function todayIsoDate(): string {
  const d = new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function toDateInput(iso: string | null | undefined): string {
  if (!iso) return todayIsoDate()
  return iso.slice(0, 10)
}

export function QuoteFollowUpPanel({
  quoteId,
  initialFollowUp,
  initialHistory,
  initialEligibility,
  canEdit,
  canNotifySales,
  onSaved,
  embedded = false,
  detailMode = false,
  showHistory = true,
  forceNegociacion = false,
}: {
  quoteId: string
  initialFollowUp: QuoteFollowUp | null
  initialHistory?: QuoteFollowUpHistoryEntry[]
  initialEligibility?: QuoteNotifyEligibility | null
  canEdit: boolean
  canNotifySales?: boolean
  onSaved?: () => void
  /** Sin título grande; para tarjetas de la página Recordatorios */
  embedded?: boolean
  /** Etiquetas y botón como el prototipo (detalle Recordatorios) */
  detailMode?: boolean
  /** Historial se muestra aparte en el panel de detalle */
  showHistory?: boolean
  /** Abrir formulario en Negociación (reagendar) */
  forceNegociacion?: boolean
}) {
  const [followUp, setFollowUp] = useState<QuoteFollowUp | null>(initialFollowUp)
  const [history, setHistory] = useState(initialHistory ?? [])
  const [eligibility, setEligibility] = useState(initialEligibility ?? null)
  const [status, setStatus] = useState<FollowUpStatus | ''>(
    initialFollowUp?.status ?? '',
  )
  const [remindDate, setRemindDate] = useState(
    toDateInput(initialFollowUp?.remindAt) || todayIsoDate(),
  )
  const [invoice, setInvoice] = useState(initialFollowUp?.invoice ?? '')
  const [comments, setComments] = useState(initialFollowUp?.comments ?? '')
  const [saving, setSaving] = useState(false)
  const [notifying, setNotifying] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [okMsg, setOkMsg] = useState<string | null>(null)

  useEffect(() => {
    setFollowUp(initialFollowUp)
    setHistory(initialHistory ?? [])
    setEligibility(initialEligibility ?? null)
    setStatus(initialFollowUp?.status ?? '')
    setRemindDate(toDateInput(initialFollowUp?.remindAt) || todayIsoDate())
    setInvoice(initialFollowUp?.invoice ?? '')
    setComments(initialFollowUp?.comments ?? '')
  }, [initialFollowUp, initialHistory, initialEligibility, quoteId])

  useEffect(() => {
    let cancelled = false
    if ((initialHistory?.length ?? 0) > 0) return
    void getQuoteById(quoteId)
      .then((quote) => {
        if (cancelled) return
        if (quote.followUpHistory?.length) {
          setHistory(quote.followUpHistory)
        }
        if (quote.followUp) {
          setFollowUp(quote.followUp)
        }
        if (quote.eligibility) {
          setEligibility(quote.eligibility)
        }
      })
      .catch(() => {
        /* historial opcional */
      })
    return () => {
      cancelled = true
    }
  }, [quoteId, initialHistory?.length])

  const closed = followUp?.status === 'ganada' || followUp?.status === 'perdida'
  const showNotify = Boolean(canNotifySales)

  useEffect(() => {
    if (forceNegociacion && canEdit && !closed) {
      setStatus('negociacion')
      setRemindDate(todayIsoDate())
    }
  }, [forceNegociacion, quoteId, canEdit, closed])

  const saveLabel =
    status === 'negociacion'
      ? 'Guardar Negociación'
      : status === 'ganada'
        ? 'Guardar Ganada'
        : status === 'perdida'
          ? 'Guardar Perdida'
          : 'Guardar recordatorio'

  const handleSave = async () => {
    if (!status) {
      setError('Selecciona un estatus de recordatorio.')
      return
    }
    setSaving(true)
    setError(null)
    setOkMsg(null)
    try {
      const result = await updateQuoteFollowUp(quoteId, {
        status,
        remindDate: status === 'negociacion' ? remindDate : null,
        invoice: status === 'ganada' ? invoice : null,
        comments: status === 'perdida' ? comments : null,
      })
      setFollowUp(result.followUp)
      setHistory(result.followUpHistory)
      setEligibility(result.eligibility)
      setOkMsg('Recordatorio guardado.')
      onSaved?.()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo guardar el recordatorio.')
    } finally {
      setSaving(false)
    }
  }

  const handleNotify = async () => {
    setNotifying(true)
    setError(null)
    setOkMsg(null)
    try {
      await notifySalesAboutQuote(quoteId)
      setOkMsg('Aviso enviado a ventas.')
      if (eligibility) {
        setEligibility({
          ...eligibility,
          eligible: false,
          pendingUnread: true,
          blockReason: 'Ya hay un aviso pendiente.',
        })
      }
      onSaved?.()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo avisar a ventas.')
    } finally {
      setNotifying(false)
    }
  }

  return (
    <div
      className={
        embedded
          ? 'space-y-3'
          : 'space-y-4 rounded-xl border border-violet-200/80 bg-violet-50/40 p-4'
      }
    >
      {!embedded && (
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <h3 className="text-sm font-semibold text-slate-900">Recordatorios</h3>
            <p className="mt-0.5 text-xs text-slate-600">
              Independiente del estado de cotización (workflow).
            </p>
          </div>
          {followUp?.status ? <FollowUpBadge status={followUp.status} /> : null}
        </div>
      )}

      {showNotify && eligibility?.eligible && (
        <Button
          type="button"
          variant="secondary"
          size="sm"
          disabled={notifying}
          onClick={() => void handleNotify()}
        >
          {notifying ? <InlineBusy size="sm" /> : <Bell className="h-4 w-4" />}
          Avisar a ventas
        </Button>
      )}
      {showNotify && eligibility && !eligibility.eligible && eligibility.blockReason && (
        <p className="text-xs text-slate-500">{eligibility.blockReason}</p>
      )}

      {canEdit && !closed && (
        <div className="grid gap-3 sm:grid-cols-2">
          {detailMode && (
            <p className="sm:col-span-2 text-sm text-slate-500">
              Marca el resultado comercial (no cambia el estado de cotización).
            </p>
          )}
          <div className="sm:col-span-2">
            <Label>{detailMode ? 'Estatus de seguimiento' : 'Estatus'}</Label>
            <select
              className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm"
              value={status}
              onChange={(e) => setStatus(e.target.value as FollowUpStatus | '')}
            >
              <option value="">Seleccionar estatus…</option>
              <option value="negociacion">{FOLLOW_UP_STATUS_LABELS.negociacion}</option>
              <option value="ganada">{FOLLOW_UP_STATUS_LABELS.ganada}</option>
              <option value="perdida">{FOLLOW_UP_STATUS_LABELS.perdida}</option>
            </select>
          </div>
          {status === 'negociacion' && (
            <div className="sm:col-span-2">
              <Label>{detailMode ? 'Fecha de seguimiento' : 'Recordar el'}</Label>
              <Input
                type="date"
                min={todayIsoDate()}
                value={remindDate}
                onChange={(e) => setRemindDate(e.target.value)}
              />
              {detailMode && (
                <p className="mt-1 text-xs text-slate-500">
                  Elige cuándo volver a contactar al cliente.
                </p>
              )}
            </div>
          )}
          {status === 'ganada' && (
            <div className="sm:col-span-2">
              <Label>{detailMode ? 'Número de factura o ticket' : 'Factura / ticket'}</Label>
              <Input
                value={invoice}
                onChange={(e) => setInvoice(e.target.value)}
                placeholder={detailMode ? 'FAC-12345 / TKT-889' : '2–60 caracteres'}
                maxLength={60}
              />
            </div>
          )}
          {status === 'perdida' && (
            <div className="sm:col-span-2">
              <Label>Comentarios</Label>
              <Textarea
                value={comments}
                onChange={(e) => setComments(e.target.value)}
                placeholder={
                  detailMode
                    ? 'Ej. Eligió otro proveedor / precio fuera de presupuesto'
                    : '3–500 caracteres'
                }
                rows={3}
                maxLength={500}
              />
            </div>
          )}
          {status && (
            <div className="sm:col-span-2">
              <Button
                type="button"
                size={detailMode ? 'md' : 'sm'}
                className={detailMode ? 'w-full' : undefined}
                disabled={saving}
                onClick={() => void handleSave()}
              >
                {saving ? (
                  <InlineBusy size="sm" label={detailMode ? 'Guardando…' : undefined} />
                ) : detailMode ? (
                  saveLabel
                ) : (
                  <>
                    <Save className="h-4 w-4" />
                    Guardar recordatorio
                  </>
                )}
              </Button>
            </div>
          )}
        </div>
      )}

      {closed && followUp && (
        <div className="rounded-lg border border-slate-200 bg-white/80 px-3 py-2 text-sm text-slate-700">
          <p>
            Cerrada como <strong>{FOLLOW_UP_STATUS_LABELS[followUp.status]}</strong>
            {followUp.byUser ? ` por ${followUp.byUser}` : ''}
            {followUp.at ? ` · ${formatDateTime(followUp.at)}` : ''}
          </p>
          {followUp.invoice ? (
            <p className="mt-1 text-xs text-slate-600">Factura/ticket: {followUp.invoice}</p>
          ) : null}
          {followUp.comments ? (
            <p className="mt-1 text-xs text-slate-600">{followUp.comments}</p>
          ) : null}
        </div>
      )}

      {error && (
        <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
          {error}
        </p>
      )}
      {okMsg && (
        <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
          {okMsg}
        </p>
      )}

      {showHistory && history.length > 0 && (
        <div>
          <Label>Historial de recordatorios</Label>
          <ul className="mt-2 max-h-44 space-y-2 overflow-y-auto rounded-lg border border-slate-100 bg-white/80 p-3 text-xs">
            {history.map((ev) => (
              <li key={ev.id} className="text-slate-700">
                <span className="font-medium">
                  {ev.fromStatus
                    ? `${FOLLOW_UP_STATUS_LABELS[ev.fromStatus]} → ${FOLLOW_UP_STATUS_LABELS[ev.toStatus]}`
                    : FOLLOW_UP_STATUS_LABELS[ev.toStatus]}
                </span>
                <span className="mt-0.5 block text-slate-500">
                  {formatDateTime(ev.createdAt)} · {ev.userName}
                  {ev.invoice ? ` · Fac: ${ev.invoice}` : ''}
                  {ev.comments ? ` · ${ev.comments}` : ''}
                  {ev.remindAt ? ` · Recordar: ${ev.remindAt.slice(0, 10)}` : ''}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}
