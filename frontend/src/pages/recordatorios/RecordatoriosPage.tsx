import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
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
import { isPersistedQuoteId, listQuotes, openQuotePdf } from '@/lib/quotes-api'
import {
  FOLLOW_UP_STATUS_LABELS,
  type FollowUpStatus,
  type Quote,
} from '@/types'

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
  const [searchParams, setSearchParams] = useSearchParams()
  const isCompras = isComprasRole(user?.role)
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

  useEffect(() => {
    if (quoteParam) setSelectedId(quoteParam)
  }, [quoteParam])

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
            : 'Envía comentarios del recordatorio al vendedor de cada cotización.'
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
  canNotify,
  selectedId,
  onSelect,
}: {
  eligible: Quote[]
  others: Quote[]
  canNotify: boolean
  selectedId: string | null
  onSelect: (id: string) => void
}) {
  return (
    <div className="space-y-8">
      <section>
        <h2 className="text-base font-semibold text-slate-900">Cotizaciones sin avance</h2>
        <p className="mt-1 text-sm text-slate-500">
          Lista / Terminada, o En elaboración sin avance. Abre el detalle y escribe el comentario
          para ventas.
        </p>
        <div className="mt-4 grid gap-3 md:grid-cols-2">
          {eligible.length === 0 && (
            <p className="text-sm text-slate-500">No hay cotizaciones sin avance ahora.</p>
          )}
          {eligible.map((q) => (
            <ComprasReminderCard
              key={q.id}
              quote={q}
              selected={selectedId === q.id}
              canNotify={canNotify}
              onSelect={() => onSelect(q.id)}
            />
          ))}
        </div>
      </section>

      <section>
        <h2 className="text-base font-semibold text-slate-900">Cotizaciones en seguimiento</h2>
        <p className="mt-1 text-sm text-slate-500">
          Siguen en elaboración dentro del plazo normal. Puedes abrirlas y dejar un comentario si
          hace falta.
        </p>
        <div className="mt-4 grid gap-3 md:grid-cols-2">
          {others.length === 0 && (
            <p className="text-sm text-slate-500">No hay cotizaciones en seguimiento.</p>
          )}
          {others.map((q) => (
            <ComprasReminderCard
              key={q.id}
              quote={q}
              selected={selectedId === q.id}
              canNotify={false}
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
  onSelect,
}: {
  quote: Quote
  selected: boolean
  canNotify: boolean
  onSelect: () => void
}) {
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

      {(quote.eligibility?.notifyRecipientName || quote.createdByName) && (
        <p className="mt-2 text-xs text-slate-600">
          Ventas:{' '}
          <span className="font-medium text-slate-800">
            {quote.eligibility?.notifyRecipientName || quote.createdByName}
          </span>
        </p>
      )}

      {quote.eligibility?.pendingUnread && (
        <p className="mt-2 text-xs text-amber-700">
          Hay un comentario/aviso pendiente (sin leer).
        </p>
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
          <Button type="button" size="sm" onClick={onSelect}>
            <Bell className="h-4 w-4" />
            Escribir comentario
          </Button>
        )}
      </div>
    </article>
  )
}
