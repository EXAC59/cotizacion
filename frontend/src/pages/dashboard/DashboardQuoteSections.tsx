import { QuoteReminderLink } from '@/components/quotes/QuoteReminderLink'
import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowDown, ArrowUp } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Label, Select } from '@/components/ui/Input'
import { LoadingOverlay, TableSkeleton } from '@/components/ui/LoadingState'
import { QuoteStatusBadge } from '@/components/ui/QuoteStatusBadge'
import { formatCurrency, formatDateTime } from '@/lib/format'
import type { DashboardWorkspaceData } from '@/lib/dashboard-workspace-api'
import { useDashboardQuotes } from './useDashboardQuotes'

const STEPS = {
  solicitud_cotizaciones: { label: 'No revisada', action: 'Revisar', variant: 'warning' },
  en_elaboracion: { label: 'En elaboración', action: 'Continuar', variant: 'brand' },
  pendiente_envio: { label: 'Terminada sin enviar', action: 'Revisar para enviar', variant: 'success' },
} as const

export function QuotePagination({ data, onPage, label, loading = false }: {
  data: DashboardWorkspaceData
  onPage: (page: number) => void
  label: string
  loading?: boolean
}) {
  return <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3">
    <p className="text-sm text-slate-600">Mostrando {data.meta.from ?? 0}–{data.meta.to ?? 0} de {data.meta.total}</p>
    {data.meta.lastPage > 1 && <nav aria-label={label} className="flex items-center gap-2">
      <Button variant="secondary" size="sm" className="min-h-11" disabled={loading || data.meta.page <= 1} onClick={() => onPage(data.meta.page - 1)}>Anterior</Button>
      <span className="text-xs text-slate-600">{data.meta.page} / {data.meta.lastPage}</span>
      <Button variant="secondary" size="sm" className="min-h-11" disabled={loading || data.meta.page >= data.meta.lastPage} onClick={() => onPage(data.meta.page + 1)}>Siguiente</Button>
    </nav>}
  </div>
}

export function QuoteLoadError({ message, retry }: { message: string; retry: () => void }) {
  return <div role="alert" className="m-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p>{message}</p><Button variant="secondary" className="mt-3 min-h-11" onClick={retry}>Reintentar</Button></div>
}

export function DashboardPendingQuotes({ reminderQuoteIds }: { reminderQuoteIds: ReadonlySet<string> }) {
  const [filter, setFilter] = useState('')
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState<'date' | 'oldest'>('date')
  const contentRef = useRef<HTMLDivElement>(null)
  const [contentMinHeight, setContentMinHeight] = useState(0)
  const query = new URLSearchParams({ scope: 'all', focus: 'pending', sort, per_page: '5', page: String(page) })
  if (filter) query.set('status', filter)
  const { data, loading, error, retry } = useDashboardQuotes(query.toString())
  const changePage = (nextPage: number) => {
    // Keep the table and footer footprint when the last page has fewer rows.
    setContentMinHeight(contentRef.current?.getBoundingClientRect().height ?? 0)
    setPage(nextPage)
  }
  const sortButton = <Button variant="ghost" size="sm" className="min-h-11 text-left" disabled={loading}
    title={sort === 'date' ? 'Ordenar más antiguas primero' : 'Ordenar más nuevas primero'}
    onClick={() => { setSort((current) => current === 'date' ? 'oldest' : 'date'); setPage(1); setContentMinHeight(0) }}>
    <span><span className="block">Fecha de creación</span><span className="block text-xs font-normal">{sort === 'date' ? 'Más nuevas primero' : 'Más antiguas primero'}</span></span>
    {sort === 'date' ? <ArrowDown className="h-4 w-4" aria-hidden /> : <ArrowUp className="h-4 w-4" aria-hidden />}
  </Button>
  return <Card id="pendientes" className="relative min-w-0 scroll-mt-6">
    <span id="cotizaciones-pendientes" className="absolute top-0" />
    <span id="cotizaciones-listas-ventas" className="absolute top-0" />
    <CardHeader title="COTIZACIONES PENDIENTES" subtitle="Ordena por fecha de creación con el botón de la tabla. Abre una cotización para atenderla." action={<Badge variant="warning"><span className="text-base">{(loading && !data) || error ? '…' : `${data?.meta.total ?? 0} pendientes`}</span></Badge>} />
    <CardBody className="p-0!">
      <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
          <Label htmlFor="pending-step">Estatus</Label>
          <Select id="pending-step" className="min-h-11 sm:max-w-xs" value={filter} onChange={(e) => { setFilter(e.target.value); setPage(1); setContentMinHeight(0) }}>
            <option value="">Todos los pendientes</option>
            {Object.entries(STEPS).map(([status, step]) => <option key={status} value={status}>{step.label}</option>)}
          </Select>
          <div className="md:hidden">{sortButton}</div>
      </div>
      <div aria-busy={loading}>
      <LoadingOverlay show={loading && !!data} label="Cargando cotizaciones…">
      <div ref={contentRef} className="flex flex-col" style={{ minHeight: contentMinHeight }}>
      {loading && !data ? <TableSkeleton rows={5} cols={6} /> : error ? <QuoteLoadError message={error} retry={retry} /> : data?.data.length ? <><div className="hidden overflow-x-auto md:block"><table className="w-full text-left text-sm" aria-label="Cotizaciones pendientes">
        <thead className="border-b border-slate-200 bg-slate-50 text-slate-600"><tr>{['Cotización', 'Cliente', 'Estatus', 'Fecha de creación', 'Hecha por', 'Acción'].map((label) => <th key={label} className="px-5 py-3 font-medium" aria-sort={label === 'Fecha de creación' ? sort === 'date' ? 'descending' : 'ascending' : undefined}>{label === 'Fecha de creación' ? sortButton : label}</th>)}</tr></thead>
        <tbody>{data.data.map((quote) => {
          const step = STEPS[quote.status as keyof typeof STEPS]
          return <tr key={quote.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
            <td className="whitespace-nowrap px-5 py-3"><Link className="font-medium text-indigo-700 hover:underline" to={`/cotizaciones/${quote.id}`}>{quote.folio}</Link>{reminderQuoteIds.has(quote.id) && <div className="mt-2"><QuoteReminderLink id={quote.id} folio={quote.folio} /></div>}</td>
            <td className="px-5 py-3 text-slate-700">{quote.clientName || '—'}</td>
            <td className="whitespace-nowrap px-5 py-3"><QuoteStatusBadge status={quote.status} /></td>
            <td className="whitespace-nowrap px-5 py-3 text-slate-600">{formatDateTime(quote.createdAt)}</td>
            <td className="px-5 py-3 text-slate-600">{quote.createdByName || 'Sin asignar'}</td>
            <td className="px-5 py-3"><Link to={`/cotizaciones/${quote.id}`} aria-label={`${step.action} ${quote.folio}`} className="inline-flex min-h-11 items-center whitespace-nowrap rounded-lg border border-indigo-200 bg-indigo-50 px-3 font-medium text-indigo-700 hover:bg-indigo-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{step.action}</Link></td>
          </tr>
        })}</tbody>
      </table></div>
        <ul aria-label="Cotizaciones pendientes" className="divide-y divide-slate-100 md:hidden">
          {data.data.map((quote) => {
            const step = STEPS[quote.status as keyof typeof STEPS]
            return <li key={quote.id} className="space-y-3 px-5 py-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <Link to={`/cotizaciones/${quote.id}`} className="font-semibold text-indigo-700 hover:underline">{quote.folio}</Link>
                <QuoteStatusBadge status={quote.status} />
              </div>
              {reminderQuoteIds.has(quote.id) && <QuoteReminderLink id={quote.id} folio={quote.folio} />}
              <p className="text-sm text-slate-800">{quote.clientName || '—'}</p>
              <p className="text-xs text-slate-600">Fecha de creación: {formatDateTime(quote.createdAt)}</p>
              <p className="text-xs text-slate-600">Hecha por {quote.createdByName || 'Sin asignar'}</p>
              <Link to={`/cotizaciones/${quote.id}`} aria-label={`${step.action} ${quote.folio}`} className="inline-flex min-h-11 items-center justify-center rounded-lg border border-indigo-200 bg-indigo-50 px-4 text-sm font-medium text-indigo-700 hover:bg-indigo-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{step.action}</Link>
            </li>
          })}
        </ul>
      </> : <p className="px-5 py-8 text-center text-sm text-slate-600">{filter ? 'No hay pendientes en este paso.' : 'No hay cotizaciones pendientes.'}</p>}
      {data && !error && <div className="mt-auto"><QuotePagination data={data} onPage={changePage} loading={loading} label="Páginas de cotizaciones pendientes" /></div>}
      </div>
      </LoadingOverlay>
      </div>
    </CardBody>
  </Card>
}

export function DashboardRecentQuotes({ reminderQuoteIds }: { reminderQuoteIds: ReadonlySet<string> }) {
  const { data, loading, error, retry } = useDashboardQuotes('scope=all&focus=all&recent=1')
  return <Card id="recientes" className="min-w-0">
    <CardHeader title="COTIZACIONES RECIENTES" subtitle="Las 10 cotizaciones más recientes, creadas en los últimos 7 días." action={<Link to="/cotizaciones" className="inline-flex min-h-11 items-center text-sm font-medium text-indigo-700 hover:underline">Ver todas</Link>} />
    <CardBody className="p-0!">
      {loading ? <TableSkeleton rows={5} cols={5} /> : error ? <QuoteLoadError message={error} retry={retry} /> : data?.data.length ? <div className="overflow-x-auto"><table className="w-full text-left text-sm" aria-label="Cotizaciones recientes">
        <thead className="border-b border-slate-200 bg-slate-50 text-slate-600"><tr>{['Cotización', 'Cliente', 'Estatus', 'Fecha de creación', 'Total'].map((label) => <th key={label} className="px-5 py-3 font-medium">{label}</th>)}</tr></thead>
        <tbody>{data.data.map((quote) => <tr key={quote.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
          <td className="whitespace-nowrap px-5 py-3"><Link to={`/cotizaciones/${quote.id}`} className="font-medium text-indigo-700 hover:underline">{quote.folio}</Link>{reminderQuoteIds.has(quote.id) && <div className="mt-2"><QuoteReminderLink id={quote.id} folio={quote.folio} /></div>}</td>
          <td className="px-5 py-3">{quote.clientName || '—'}</td>
          <td className="whitespace-nowrap px-5 py-3"><QuoteStatusBadge status={quote.status} /></td>
          <td className="whitespace-nowrap px-5 py-3 text-slate-600">{formatDateTime(quote.createdAt)}</td>
          <td className="whitespace-nowrap px-5 py-3 font-medium">{formatCurrency(quote.total ?? 0)}</td>
        </tr>)}</tbody>
      </table></div> : <p className="px-5 py-8 text-center text-sm text-slate-600">No se crearon cotizaciones en los últimos 7 días.</p>}
      {data && !loading && !error && <p className="border-t border-slate-100 px-5 py-3 text-xs text-slate-600">Mostrando {data.data.length} de {data.meta.total} cotizaciones de los últimos 7 días. Se actualiza automáticamente.</p>}
    </CardBody>
  </Card>
}
