import { useCallback, useEffect, useMemo, useState } from 'react'
import { Navigate, useSearchParams } from 'react-router-dom'
import { RefreshCw } from 'lucide-react'
import { RecordatorioDetailPanel } from '@/components/recordatorios/RecordatorioDetailPanel'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { FollowUpBadge } from '@/components/ui/FollowUpBadge'
import { LoadingState } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { QuoteStatusBadge } from '@/components/ui/QuoteStatusBadge'
import { useAuth } from '@/hooks/useAuth'
import { usePermission } from '@/hooks/usePermission'
import { useNotificationFocus } from '@/lib/notification-focus'
import { formatCurrency, formatDate } from '@/lib/format'
import {
  claimQuoteFollowUp,
  isPersistedQuoteId,
  listQuotes,
  openQuotePdf,
} from '@/lib/quotes-api'
import {
  FOLLOW_UP_STATUS_LABELS,
  type FollowUpStatus,
  type Quote,
} from '@/types'
import { isUnsentForClientQuote } from '@/lib/quote-status'

function isComprasRole(role: string | undefined): boolean {
  return role === 'gerente_compras' || role === 'administrador'
}

/** A partir de 3 días (≥ 3). Espejo del default AppSetting unanswered_quote_days. */
const ELABORACION_IDLE_DAYS = 3

function isElaboracionIdleRecordatorio(quote: Quote): boolean {
  if (quote.status !== 'en_elaboracion') return false
  const daysIdle = Number(quote.eligibility?.daysIdle ?? 0)
  if (daysIdle < ELABORACION_IDLE_DAYS) return false
  return (
    quote.eligibility?.reasonCode === 'sin_avance' ||
    quote.eligibility?.eligible === true
  )
}

/** Compras: Lista/Terminada no enviada de ventas, o elaboración a partir de 3 días. */
function isComprasRecordatorioQuote(quote: Quote): boolean {
  if (quote.assignedToSales !== true) return false

  if (quote.status === 'pendiente_envio') {
    return isUnsentForClientQuote(quote)
  }

  return isElaboracionIdleRecordatorio(quote)
}

/** Ventas: bandeja compartida sin responsable, más las asignadas al visor. */
function isVentasRecordatorioQuote(quote: Quote): boolean {
  if (quote.assignedToSales !== true) return false
  if (quote.followUpAssigneeId && !quote.followUpAssignedToViewer) return false

  if (quote.status === 'pendiente_envio') {
    return isUnsentForClientQuote(quote)
  }

  return isElaboracionIdleRecordatorio(quote)
}

function needsRemindAgain(quote: Quote): boolean {
  const fu = quote.followUp
  if (!fu || fu.status !== 'negociacion' || !fu.remindAt) return false
  return fu.remindAt.slice(0, 10) <= new Date().toISOString().slice(0, 10)
}

export function RecordatoriosPage() {
  const { user } = useAuth()
  const { can } = usePermission()
  const [searchParams, setSearchParams] = useSearchParams()
  const role = user?.role
  const isCompras = isComprasRole(role)
  const canNotify = isCompras && can('cotizaciones', 'edit')
  const canEditFollowUp = can('cotizaciones', 'edit')
  const showVentasDashboard = !isCompras

  const quoteParam = searchParams.get('quote')

  const [quotes, setQuotes] = useState<Quote[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [actionMsg, setActionMsg] = useState<string | null>(null)
  const [selectedId, setSelectedId] = useState<string | null>(quoteParam)
  const [forceNegociacion, setForceNegociacion] = useState(false)
  const [claimingId, setClaimingId] = useState<string | null>(null)
  const [claimDraftId, setClaimDraftId] = useState<string | null>(null)
  const [claimDeclaration, setClaimDeclaration] = useState('')

  useEffect(() => {
    if (quoteParam) setSelectedId(quoteParam)
  }, [quoteParam])

  const refresh = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const isVentasUser = role === 'ventas'
      if (isVentasUser) {
        // Compartidas: sin responsable o ya asignadas al vendedor autenticado.
        const [lista, elaboracion] = await Promise.all([
          listQuotes({ remindersPool: true, status: 'pendiente_envio' }),
          listQuotes({ remindersPool: true, status: 'en_elaboracion' }),
        ])
        setQuotes(
          [...lista, ...elaboracion].filter((q) => isVentasRecordatorioQuote(q)),
        )
      } else {
        // Compras/admin: Lista/Terminada no enviadas + elaboración idle ≥ umbral (p. ej. 3 días).
        const [lista, elaboracion] = await Promise.all([
          listQuotes({ scope: 'all', status: 'pendiente_envio' }),
          listQuotes({ scope: 'all', status: 'en_elaboracion' }),
        ])
        setQuotes(
          [...lista, ...elaboracion].filter((q) => isComprasRecordatorioQuote(q)),
        )
      }
    } catch {
      setError('No se pudieron cargar los recordatorios.')
    } finally {
      setLoading(false)
    }
  }, [role])

  useEffect(() => {
    void refresh()
  }, [refresh])

  useNotificationFocus(!loading)

  const reminderQuotes = useMemo(() => {
    if (!isCompras) {
      return quotes.filter((q) => isVentasRecordatorioQuote(q))
    }
    return quotes.filter((q) => isComprasRecordatorioQuote(q))
  }, [quotes, isCompras])

  const eligible = useMemo(
    () => reminderQuotes.filter((q) => q.eligibility?.eligible),
    [reminderQuotes],
  )
  const others = useMemo(
    () => reminderQuotes.filter((q) => !q.eligibility?.eligible),
    [reminderQuotes],
  )

  const stats = useMemo(() => {
    const counts: Record<FollowUpStatus, number> = {
      negociacion: 0,
      ganada: 0,
      perdida: 0,
    }
    for (const q of reminderQuotes) {
      const s = q.followUp?.status
      if (s && counts[s] !== undefined) counts[s] += 1
    }
    return counts
  }, [reminderQuotes])

  const selectedQuote = useMemo(
    () => reminderQuotes.find((q) => q.id === selectedId) ?? null,
    [reminderQuotes, selectedId],
  )

  // Recordatorios: ventas y compras (admin ve vista compras).
  if (user?.role !== 'ventas' && user?.role !== 'gerente_compras' && user?.role !== 'administrador') {
    return <Navigate to="/dashboard" replace />
  }

  const openDetail = (id: string, reagendar = false) => {
    setSelectedId(id)
    setForceNegociacion(reagendar)
    const nextParams = new URLSearchParams(searchParams)
    nextParams.delete('vista')
    nextParams.set('quote', id)
    setSearchParams(nextParams, { replace: true })
  }

  const closeDetail = () => {
    setSelectedId(null)
    setForceNegociacion(false)
    const nextParams = new URLSearchParams(searchParams)
    nextParams.delete('quote')
    nextParams.delete('vista')
    setSearchParams(nextParams, { replace: true })
  }

  const handleSaved = () => {
    void refresh()
    setActionMsg('Recordatorio actualizado.')
    setForceNegociacion(false)
  }

  const handleClaim = async (quote: Quote, declaration: string) => {
    setClaimingId(quote.id)
    setError(null)
    try {
      const assignment = await claimQuoteFollowUp(quote.id, declaration)
      await refresh()
      setActionMsg(`Seguimiento asignado a ${assignment.assigneeName}.`)
      setClaimDraftId(null)
      setClaimDeclaration('')
      openDetail(quote.id)
    } catch (claimError) {
      setError(
        claimError instanceof Error
          ? claimError.message
          : 'No se pudo tomar el seguimiento.',
      )
      await refresh()
    } finally {
      setClaimingId(null)
    }
  }

  const layoutClass =
    selectedId && selectedQuote
      ? 'grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(320px,420px)]'
      : ''

  return (
    <div>
      <PageHeader
        title={showVentasDashboard ? 'Dashboard — Recordatorios' : 'Recordatorios'}
        description={
          showVentasDashboard
            ? 'Cotizaciones de Ventas sin responsable, y seguimientos que tienes asignados.'
            : 'Lista / Terminada de ventas aún no enviadas, y en elaboración a partir de 3 días sin avance.'
        }
        actions={
          <Button variant="secondary" size="sm" onClick={() => void refresh()} disabled={loading}>
            <RefreshCw className="h-4 w-4" />
            Actualizar
          </Button>
        }
      />

      {actionMsg && (
        <p className="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
          {actionMsg}
        </p>
      )}
      {error && (
        <p className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          {error}
        </p>
      )}

      {loading ? (
        <LoadingState label="Cargando recordatorios…" variant="page" />
      ) : (
        <div className={layoutClass}>
          <div className="min-w-0">
            {showVentasDashboard ? (
              <VentasDashboard
                quotes={reminderQuotes}
                stats={stats}
                selectedId={selectedId}
                onOpenDetail={openDetail}
                claimingId={claimingId}
                claimDraftId={claimDraftId}
                claimDeclaration={claimDeclaration}
                onStartClaim={(quote) => {
                  setClaimDraftId(quote.id)
                  setClaimDeclaration('')
                }}
                onDeclarationChange={setClaimDeclaration}
                onCancelClaim={() => {
                  setClaimDraftId(null)
                  setClaimDeclaration('')
                }}
                onClaim={(quote, declaration) => void handleClaim(quote, declaration)}
              />
            ) : (
              <ComprasView
                eligible={eligible}
                others={others}
                selectedId={selectedId}
                onSelect={(id) => openDetail(id)}
              />
            )}
          </div>

          {selectedId && selectedQuote && (
            <RecordatorioDetailPanel
              quoteId={selectedId}
              summary={selectedQuote}
              comprasView={!showVentasDashboard}
              canEdit={canEditFollowUp}
              canNotify={canNotify}
              forceNegociacion={forceNegociacion}
              onClose={closeDetail}
              onSaved={handleSaved}
            />
          )}
        </div>
      )}
    </div>
  )
}

function ComprasView({
  eligible,
  others,
  selectedId,
  onSelect,
}: {
  eligible: Quote[]
  others: Quote[]
  selectedId: string | null
  onSelect: (id: string) => void
}) {
  return (
    <div className="space-y-8">
      <section>
        <h2 className="text-base font-semibold text-slate-900">Cotizaciones sin avance</h2>
        <p className="mt-1 text-sm text-slate-500">
          Lista / Terminada aún no enviadas, o en elaboración a partir de 3 días sin avance. Haz
          clic en una cotización para ver el detalle y comentar a ventas.
        </p>
        <div className="mt-4 grid items-start gap-3 md:grid-cols-2">
          {eligible.length === 0 && (
            <p className="text-sm text-slate-500">No hay cotizaciones sin avance ahora.</p>
          )}
          {eligible.map((q) => (
            <ComprasReminderCard
              key={q.id}
              quote={q}
              selected={selectedId === q.id}
              onSelect={() => onSelect(q.id)}
            />
          ))}
        </div>
      </section>

      <section>
        <h2 className="text-base font-semibold text-slate-900">Cotizaciones en seguimiento</h2>
        <p className="mt-1 text-sm text-slate-500">
          Lista / Terminada aún no enviadas, dentro del plazo normal. Puedes abrirlas y dejar un
          comentario si hace falta.
        </p>
        <div className="mt-4 grid items-start gap-3 md:grid-cols-2">
          {others.length === 0 && (
            <p className="text-sm text-slate-500">No hay cotizaciones en seguimiento.</p>
          )}
          {others.map((q) => (
            <ComprasReminderCard
              key={q.id}
              quote={q}
              selected={selectedId === q.id}
              onSelect={() => onSelect(q.id)}
            />
          ))}
        </div>
      </section>
    </div>
  )
}

function VentasDashboard({
  quotes,
  stats,
  selectedId,
  onOpenDetail,
  claimingId,
  claimDraftId,
  claimDeclaration,
  onStartClaim,
  onDeclarationChange,
  onCancelClaim,
  onClaim,
}: {
  quotes: Quote[]
  stats: Record<FollowUpStatus, number>
  selectedId: string | null
  onOpenDetail: (id: string, reagendar?: boolean) => void
  claimingId: string | null
  claimDraftId: string | null
  claimDeclaration: string
  onStartClaim: (quote: Quote) => void
  onDeclarationChange: (value: string) => void
  onCancelClaim: () => void
  onClaim: (quote: Quote, declaration: string) => void
}) {
  const statColors: Record<FollowUpStatus, string> = {
    negociacion: 'text-indigo-700',
    ganada: 'text-emerald-700',
    perdida: 'text-red-700',
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2">
        <FollowUpBadge status="negociacion" />
        <FollowUpBadge status="ganada" />
        <FollowUpBadge status="perdida" />
        <span className="text-sm text-slate-500">Negociación: agenda una fecha de seguimiento</span>
      </div>

      <div className="grid grid-cols-3 gap-3">
        {(Object.keys(stats) as FollowUpStatus[]).map((key) => (
          <div
            key={key}
            className="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm"
          >
            <p className={`text-2xl font-bold ${statColors[key]}`}>{stats[key]}</p>
            <p className="text-sm text-slate-500">{FOLLOW_UP_STATUS_LABELS[key]}</p>
          </div>
        ))}
      </div>

      <div className="grid gap-3">
        {quotes.length === 0 && (
          <p className="text-sm text-slate-500">
            No hay cotizaciones en Lista / Terminada pendientes ni elaboraciones a partir de 3 días.
          </p>
        )}
        {quotes.map((q) => (
          <VentasReminderCard
            key={q.id}
            quote={q}
            selected={selectedId === q.id}
            onOpenDetail={onOpenDetail}
            claiming={claimingId === q.id}
            showClaimDeclaration={claimDraftId === q.id}
            claimDeclaration={claimDraftId === q.id ? claimDeclaration : ''}
            onStartClaim={() => onStartClaim(q)}
            onDeclarationChange={onDeclarationChange}
            onCancelClaim={onCancelClaim}
            onClaim={() => onClaim(q, claimDeclaration)}
          />
        ))}
      </div>
    </div>
  )
}

function VentasReminderCard({
  quote,
  selected,
  onOpenDetail,
  claiming,
  showClaimDeclaration,
  claimDeclaration,
  onStartClaim,
  onDeclarationChange,
  onCancelClaim,
  onClaim,
}: {
  quote: Quote
  selected: boolean
  onOpenDetail: (id: string, reagendar?: boolean) => void
  claiming: boolean
  showClaimDeclaration: boolean
  claimDeclaration: string
  onStartClaim: () => void
  onDeclarationChange: (value: string) => void
  onCancelClaim: () => void
  onClaim: () => void
}) {
  const fu = quote.followUp
  const due = needsRemindAgain(quote)

  return (
    <article
      id={`recordatorio-quote-${quote.id}`}
      className={`relative rounded-xl border bg-white p-4 shadow-sm transition ${
        selected ? 'border-indigo-400 ring-2 ring-indigo-200' : 'border-slate-200'
      }`}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 className="font-semibold text-slate-900">{quote.folio}</h3>
          <p className="text-sm text-slate-600">
            {quote.clientName || 'Sin cliente'} · {formatCurrency(quote.total ?? 0)}
          </p>
          {fu?.status === 'ganada' && fu.invoice && (
            <p className="mt-1 text-sm text-slate-600">Factura/ticket: {fu.invoice}</p>
          )}
          {fu?.status === 'perdida' && fu.comments && (
            <p className="mt-1 text-sm text-slate-600">Comentarios: {fu.comments}</p>
          )}
          {fu?.status === 'negociacion' && fu.remindAt && (
            <p className="mt-1 text-sm text-slate-600">
              Fecha agendada: {formatDate(fu.remindAt)}
            </p>
          )}
          {fu?.byUser && (
            <p className="mt-1 text-sm text-slate-600">Seguimiento: {fu.byUser}</p>
          )}
          <p className="mt-1 text-xs text-slate-500">
            Creada por: {quote.createdByName || 'Ventas'} · Responsable:{' '}
            {quote.followUpAssigneeName || 'Sin asignar'}
          </p>
        </div>
        <div className="flex shrink-0 flex-wrap justify-end gap-1">
          {fu?.status ? (
            <FollowUpBadge status={fu.status} />
          ) : (
            <span className="text-xs text-slate-400">Sin seguimiento</span>
          )}
          {due ? <Badge variant="warning">Recordar de nuevo</Badge> : null}
        </div>
      </div>

      <div className="mt-3 flex flex-wrap gap-2">
        <Button
          type="button"
          variant="secondary"
          size="sm"
          disabled={!isPersistedQuoteId(quote.id)}
          onClick={() => void openQuotePdf(quote.id)}
        >
          Ver PDF
        </Button>
        {quote.followUpAssignedToViewer ? (
          <Button type="button" size="sm" onClick={() => onOpenDetail(quote.id)}>
            Actualizar seguimiento
          </Button>
        ) : (
          !showClaimDeclaration && (
            <Button type="button" size="sm" disabled={claiming} onClick={onStartClaim}>
              Tomar seguimiento
            </Button>
          )
        )}
        {due && quote.followUpAssignedToViewer && (
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={() => onOpenDetail(quote.id, true)}
          >
            Reagendar fecha
          </Button>
        )}
      </div>
      {showClaimDeclaration && !quote.followUpAssignedToViewer && (
        <div className="mt-3 rounded-xl border border-indigo-200 bg-indigo-50/40 p-3">
          <label className="text-sm font-medium text-slate-800" htmlFor={`claim-${quote.id}`}>
            Declaración antes de tomar el seguimiento
          </label>
          <p className="mt-1 text-xs text-slate-600">
            Explica qué acción realizarás. Esto se guardará en la bitácora; no cambia el estatus.
          </p>
          <textarea
            id={`claim-${quote.id}`}
            className="mt-2 min-h-24 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
            maxLength={1000}
            value={claimDeclaration}
            onChange={(event) => onDeclarationChange(event.target.value)}
            placeholder="Ej. Me comunicaré con el cliente para confirmar disponibilidad y continuar la cotización."
          />
          <div className="mt-2 flex flex-wrap justify-end gap-2">
            <Button type="button" variant="secondary" size="sm" disabled={claiming} onClick={onCancelClaim}>
              Cancelar
            </Button>
            <Button
              type="button"
              size="sm"
              disabled={claiming || claimDeclaration.trim().length < 10}
              onClick={onClaim}
            >
              {claiming ? 'Asignando…' : 'Guardar declaración y tomar'}
            </Button>
          </div>
        </div>
      )}
    </article>
  )
}

function ComprasReminderCard({
  quote,
  selected,
  onSelect,
}: {
  quote: Quote
  selected: boolean
  onSelect: () => void
}) {
  return (
    <article
      id={`recordatorio-quote-${quote.id}`}
      role="button"
      tabIndex={0}
      aria-label={`Abrir recordatorio de ${quote.folio}`}
      onClick={onSelect}
      onKeyDown={(event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault()
          onSelect()
        }
      }}
      className={`relative cursor-pointer rounded-xl border bg-white p-3 shadow-sm transition hover:border-indigo-300 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 ${
        selected ? 'border-indigo-400 ring-2 ring-indigo-200' : 'border-slate-200'
      }`}
    >
      <div className="flex items-start justify-between gap-2">
        <div>
          <h3 className="font-semibold text-slate-900">{quote.folio}</h3>
          <p className="mt-0.5 text-sm text-slate-600">
            {quote.clientName || 'Sin cliente'} · {formatCurrency(quote.total ?? 0)} ·{' '}
            {quote.eligibility?.daysIdle ?? 0} días sin avance
          </p>
        </div>
        <div className="flex flex-wrap gap-1">
          <QuoteStatusBadge status={quote.status} />
          {quote.followUp?.status ? (
            <FollowUpBadge status={quote.followUp.status} />
          ) : null}
        </div>
      </div>

      {(quote.eligibility?.notifyRecipientName || quote.createdByName) && (
        <p className="mt-1 text-xs text-slate-600">
          Ventas:{' '}
          <span className="font-medium text-slate-800">
            {quote.eligibility?.notifyRecipientName || quote.createdByName}
          </span>
          {quote.eligibility?.pendingUnread ? (
            <span className="text-amber-700"> · Aviso pendiente sin leer</span>
          ) : null}
        </p>
      )}

      {quote.eligibility?.pendingUnread &&
      !(quote.eligibility?.notifyRecipientName || quote.createdByName) ? (
        <p className="mt-1 text-xs text-amber-700">
          Hay un comentario/aviso pendiente (sin leer).
        </p>
      ) : null}
    </article>
  )
}
