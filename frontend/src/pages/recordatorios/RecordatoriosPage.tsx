import { useCallback, useEffect, useMemo, useState } from 'react'
import { Bell, FileDown, RefreshCw } from 'lucide-react'
import { RecordatorioDetailPanel } from '@/components/recordatorios/RecordatorioDetailPanel'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { FollowUpBadge } from '@/components/ui/FollowUpBadge'
import { LoadingState } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { QuoteStatusBadge } from '@/components/ui/QuoteStatusBadge'
import { useAuth } from '@/hooks/useAuth'
import { usePermission } from '@/hooks/usePermission'
import { formatCurrency, formatDate } from '@/lib/format'
import { notifySalesAboutQuote } from '@/lib/notifications-api'
import { isPersistedQuoteId, listQuotes, openQuotePdf } from '@/lib/quotes-api'
import {
  FOLLOW_UP_STATUS_LABELS,
  type FollowUpStatus,
  type Quote,
} from '@/types'

type RecordatoriosMode = 'compras' | 'ventas'

function isComprasRole(role: string | undefined): boolean {
  return role === 'gerente_compras' || role === 'administrador'
}

function needsRemindAgain(quote: Quote): boolean {
  const fu = quote.followUp
  if (!fu || fu.status !== 'negociacion' || !fu.remindAt) return false
  return fu.remindAt.slice(0, 10) <= new Date().toISOString().slice(0, 10)
}

export function RecordatoriosPage() {
  const { user } = useAuth()
  const { can } = usePermission()
  const canCompras = isComprasRole(user?.role)
  const canNotify = canCompras && can('cotizaciones', 'edit')
  const canEditFollowUp = can('cotizaciones', 'edit')

  /** Recordatorios ventas: dashboard de seguimiento para todos los roles al entrar. */
  const [mode, setMode] = useState<RecordatoriosMode>('ventas')
  const [quotes, setQuotes] = useState<Quote[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [actionMsg, setActionMsg] = useState<string | null>(null)
  const [notifyingId, setNotifyingId] = useState<string | null>(null)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [forceNegociacion, setForceNegociacion] = useState(false)

  const showVentasDashboard = mode === 'ventas'
  const showModeToggle = canCompras

  const refresh = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const data = await listQuotes()
      setQuotes(data)
    } catch {
      setError('No se pudieron cargar los recordatorios.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const eligible = useMemo(
    () => quotes.filter((q) => q.eligibility?.eligible),
    [quotes],
  )
  const others = useMemo(
    () => quotes.filter((q) => !q.eligibility?.eligible),
    [quotes],
  )

  const stats = useMemo(() => {
    const counts: Record<FollowUpStatus, number> = {
      negociacion: 0,
      ganada: 0,
      perdida: 0,
    }
    for (const q of quotes) {
      const s = q.followUp?.status
      if (s && counts[s] !== undefined) counts[s] += 1
    }
    return counts
  }, [quotes])

  const selectedQuote = useMemo(
    () => quotes.find((q) => q.id === selectedId) ?? null,
    [quotes, selectedId],
  )

  const openDetail = (id: string, reagendar = false) => {
    setSelectedId(id)
    setForceNegociacion(reagendar)
  }

  const closeDetail = () => {
    setSelectedId(null)
    setForceNegociacion(false)
  }

  const handleNotify = async (quote: Quote) => {
    setNotifyingId(quote.id)
    setActionMsg(null)
    try {
      await notifySalesAboutQuote(quote.id)
      setActionMsg(`Aviso enviado: ${quote.folio}`)
      await refresh()
    } catch (e) {
      setActionMsg(e instanceof Error ? e.message : 'No se pudo avisar.')
    } finally {
      setNotifyingId(null)
    }
  }

  const handleSaved = () => {
    void refresh()
    setActionMsg('Recordatorio actualizado.')
    setForceNegociacion(false)
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
            ? 'Seguimiento comercial aparte del estado de cotización (enviada, etc.).'
            : 'Avisa a ventas si la cotización está Lista/Terminada o En elaboración sin avance.'
        }
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {showModeToggle && (
              <div
                className="inline-flex rounded-full border border-slate-200 bg-slate-50 p-0.5"
                role="tablist"
                aria-label="Vista recordatorios"
              >
                <button
                  type="button"
                  role="tab"
                  aria-selected={mode === 'compras'}
                  className={`rounded-full px-3 py-1.5 text-xs font-medium transition ${
                    mode === 'compras'
                      ? 'bg-indigo-600 text-white shadow-sm'
                      : 'text-slate-600 hover:text-slate-900'
                  }`}
                  onClick={() => {
                    setMode('compras')
                    closeDetail()
                  }}
                >
                  Compras
                </button>
                <button
                  type="button"
                  role="tab"
                  aria-selected={mode === 'ventas'}
                  className={`rounded-full px-3 py-1.5 text-xs font-medium transition ${
                    mode === 'ventas'
                      ? 'bg-indigo-600 text-white shadow-sm'
                      : 'text-slate-600 hover:text-slate-900'
                  }`}
                  onClick={() => {
                    setMode('ventas')
                    closeDetail()
                  }}
                >
                  Ventas
                </button>
              </div>
            )}
            <Button variant="secondary" size="sm" onClick={() => void refresh()} disabled={loading}>
              <RefreshCw className="h-4 w-4" />
              Actualizar
            </Button>
          </div>
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
                quotes={quotes}
                stats={stats}
                selectedId={selectedId}
                onOpenDetail={openDetail}
              />
            ) : (
              <ComprasView
                eligible={eligible}
                others={others}
                canNotify={canNotify}
                notifyingId={notifyingId}
                selectedId={selectedId}
                onSelect={(id) => openDetail(id)}
                onNotify={handleNotify}
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
  canNotify,
  notifyingId,
  selectedId,
  onSelect,
  onNotify,
}: {
  eligible: Quote[]
  others: Quote[]
  canNotify: boolean
  notifyingId: string | null
  selectedId: string | null
  onSelect: (id: string) => void
  onNotify: (q: Quote) => void
}) {
  return (
    <div className="space-y-8">
      <section>
        <h2 className="text-base font-semibold text-slate-900">Candidatas a avisar</h2>
        <p className="mt-1 text-sm text-slate-500">
          Lista / Terminada, o En elaboración con días sin avance según configuración.
        </p>
        <div className="mt-4 grid gap-3 md:grid-cols-2">
          {eligible.length === 0 && (
            <p className="text-sm text-slate-500">No hay candidatas ahora.</p>
          )}
          {eligible.map((q) => (
            <ComprasReminderCard
              key={q.id}
              quote={q}
              selected={selectedId === q.id}
              canNotify={canNotify}
              notifying={notifyingId === q.id}
              onSelect={() => onSelect(q.id)}
              onNotify={() => onNotify(q)}
            />
          ))}
        </div>
      </section>

      <section>
        <h2 className="text-base font-semibold text-slate-900">Otras cotizaciones</h2>
        <p className="mt-1 text-sm text-slate-500">
          Aún no cumplen la regla (o ya cerradas). No se notifica a ventas.
        </p>
        <div className="mt-4 grid gap-3 md:grid-cols-2">
          {others.length === 0 && (
            <p className="text-sm text-slate-500">Ninguna.</p>
          )}
          {others.map((q) => (
            <ComprasReminderCard
              key={q.id}
              quote={q}
              selected={selectedId === q.id}
              canNotify={false}
              notifying={false}
              onSelect={() => onSelect(q.id)}
              onNotify={() => undefined}
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
}: {
  quotes: Quote[]
  stats: Record<FollowUpStatus, number>
  selectedId: string | null
  onOpenDetail: (id: string, reagendar?: boolean) => void
}) {
  const statColors: Record<FollowUpStatus, string> = {
    negociacion: 'text-violet-700',
    ganada: 'text-emerald-700',
    perdida: 'text-rose-700',
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
          <p className="text-sm text-slate-500">No hay cotizaciones.</p>
        )}
        {quotes.map((q) => (
          <VentasReminderCard
            key={q.id}
            quote={q}
            selected={selectedId === q.id}
            onOpenDetail={onOpenDetail}
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
}: {
  quote: Quote
  selected: boolean
  onOpenDetail: (id: string, reagendar?: boolean) => void
}) {
  const fu = quote.followUp
  const due = needsRemindAgain(quote)

  return (
    <article
      className={`rounded-xl border bg-white p-4 shadow-sm transition ${
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
        <Button type="button" size="sm" onClick={() => onOpenDetail(quote.id)}>
          Actualizar seguimiento
        </Button>
        {due && (
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
    </article>
  )
}

function ComprasReminderCard({
  quote,
  selected,
  canNotify,
  notifying,
  onSelect,
  onNotify,
}: {
  quote: Quote
  selected: boolean
  canNotify: boolean
  notifying: boolean
  onSelect: () => void
  onNotify: () => void
}) {
  const pending = Boolean(quote.eligibility?.pendingUnread)
  const allowNotify = canNotify && quote.eligibility?.eligible && !pending

  return (
    <article
      className={`rounded-xl border bg-white p-4 shadow-sm ${
        selected ? 'border-indigo-400 ring-2 ring-indigo-200' : 'border-slate-200'
      }`}
    >
      <div className="flex items-start justify-between gap-2">
        <div>
          <h3 className="font-semibold text-slate-900">{quote.folio}</h3>
          <p className="text-sm text-slate-600">
            {quote.clientName || 'Sin cliente'} · {formatCurrency(quote.total ?? 0)}
          </p>
          <p className="mt-1 text-xs text-slate-500">
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

      {!quote.eligibility?.eligible && quote.eligibility?.blockReason && (
        <p className="mt-2 text-xs text-slate-500">{quote.eligibility.blockReason}</p>
      )}
      {pending && (
        <p className="mt-2 text-xs text-amber-700">Ya hay un aviso pendiente (sin leer).</p>
      )}

      <div className="mt-3 flex flex-wrap gap-2">
        <Button type="button" variant="secondary" size="sm" onClick={onSelect}>
          Ver detalle
        </Button>
        <Button
          type="button"
          variant="secondary"
          size="sm"
          disabled={!isPersistedQuoteId(quote.id)}
          onClick={() => void openQuotePdf(quote.id)}
        >
          <FileDown className="h-4 w-4" />
          PDF
        </Button>
        {canNotify && (
          <Button
            type="button"
            size="sm"
            disabled={!allowNotify || notifying}
            onClick={onNotify}
          >
            <Bell className="h-4 w-4" />
            {notifying ? 'Enviando…' : 'Avisar a ventas'}
          </Button>
        )}
      </div>
    </article>
  )
}
