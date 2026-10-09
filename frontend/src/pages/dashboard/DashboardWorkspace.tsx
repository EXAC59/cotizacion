import { useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Search, X } from 'lucide-react'
import { QuoteListTable } from '@/components/quotes/QuoteListTable'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Input, Label, Select } from '@/components/ui/Input'
import { LoadingOverlay, TableSkeleton } from '@/components/ui/LoadingState'
import { QUOTE_STATUS_ORDER } from '@/lib/quote-status'
import { QUOTE_STATUS_LABELS } from '@/types'
import { QuoteLoadError, QuotePagination } from './DashboardQuoteSections'
import { useDashboardQuotes } from './useDashboardQuotes'

export function DashboardWorkspace({ reminderQuoteIds }: { reminderQuoteIds: ReadonlySet<string> }) {
  const [params, setParams] = useSearchParams()
  const search = params.get('q') ?? ''
  const [searchState, setSearchState] = useState({ query: search, value: search })
  const contentRef = useRef<HTMLDivElement>(null)
  const [contentMinHeight, setContentMinHeight] = useState(0)
  if (searchState.query !== search) setSearchState({ query: search, value: search })
  const from = params.get('from') ?? ''
  const to = params.get('to') ?? ''
  const sort = params.get('sort') === 'status' ? 'status' : 'date'
  const query = new URLSearchParams({ scope: 'all', focus: 'all', sort })
  for (const key of ['status', 'from', 'to', 'page']) {
    if (params.get(key)) query.set(key, params.get(key)!)
  }
  if (search) query.set('search', search)
  const { data, loading, error, retry } = useDashboardQuotes(query.toString())

  const updateFilter = (key: string, value: string) => {
    if (key === 'page') {
      setContentMinHeight(contentRef.current?.getBoundingClientRect().height ?? 0)
    } else {
      setContentMinHeight(0)
    }
    setParams((prev) => {
      const next = new URLSearchParams(prev)
      if (value) next.set(key, value)
      else next.delete(key)
      if (key !== 'page') next.delete('page')
      return next
    }, { replace: true, preventScrollReset: key === 'page' })
  }

  useEffect(() => {
    const timer = window.setTimeout(() => {
      if (searchState.value.trim() === search) return
      setContentMinHeight(0)
      setParams((prev) => {
        const next = new URLSearchParams(prev)
        if (searchState.value.trim()) next.set('q', searchState.value.trim())
        else next.delete('q')
        next.delete('page')
        return next
      }, { replace: true })
    }, 300)
    return () => window.clearTimeout(timer)
  }, [searchState.value, search, setParams])

  const filtered = Boolean(search || from || to || params.get('status'))
  return <Card id="operativa" className="min-w-0 scroll-mt-6">
    <CardHeader title="Cotizaciones generales" subtitle="Consulta todas las cotizaciones." />
    <CardBody className="p-0!">
      <div className="border-b border-slate-200 px-5 py-3">
        <Label htmlFor="dashboard-search">Buscar cotización</Label>
        <div className="relative mt-1"><Search className="pointer-events-none absolute top-3 left-3 h-4 w-4 text-slate-400" aria-hidden /><Input id="dashboard-search" type="search" maxLength={120} className="min-h-11 pl-9" placeholder="Escribe el folio o el nombre del cliente" value={searchState.value} onChange={(e) => setSearchState({ query: search, value: e.target.value })} /></div>
        <details open={Boolean(from || to || params.get('status')) || undefined} className="mt-2 text-sm text-slate-700">
          <summary className="min-h-11 cursor-pointer py-3 font-medium focus-visible:outline-2 focus-visible:outline-indigo-600">Filtrar por estatus y fechas</summary>
          <div className="grid gap-3 pb-3 sm:grid-cols-2 xl:grid-cols-3">
            <div><Label htmlFor="dashboard-status">Estatus</Label><Select id="dashboard-status" className="mt-1 min-h-11" value={params.get('status') ?? ''} onChange={(e) => updateFilter('status', e.target.value)}><option value="">Todos los estatus</option>{QUOTE_STATUS_ORDER.map((status) => <option key={status} value={status}>{QUOTE_STATUS_LABELS[status]}</option>)}</Select></div>
            <div><Label htmlFor="dashboard-from">Desde</Label><Input id="dashboard-from" type="date" className="mt-1 min-h-11" value={from} max={to || undefined} onChange={(e) => updateFilter('from', e.target.value)} /></div>
            <div><Label htmlFor="dashboard-to">Hasta</Label><Input id="dashboard-to" type="date" className="mt-1 min-h-11" value={to} min={from || undefined} onChange={(e) => updateFilter('to', e.target.value)} /></div>
          </div>
          <p className="mb-3 text-xs text-slate-600">Las fechas corresponden al día de creación.</p>
        </details>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p role="status" className="text-sm text-slate-600">{data && !error ? `${data.meta.total} cotizaciones` : 'Cargando cotizaciones…'}</p>
          {filtered && <Button variant="ghost" size="sm" className="min-h-11" onClick={() => { setContentMinHeight(0); setParams({}, { replace: true }) }}><X className="h-4 w-4" aria-hidden />Limpiar filtros</Button>}
        </div>
      </div>
      <div aria-busy={loading}>
        <LoadingOverlay show={loading && !!data} label="Cargando cotizaciones…">
          <div ref={contentRef} className="flex flex-col" style={{ minHeight: contentMinHeight }}>
            {loading && !data ? <TableSkeleton rows={5} cols={8} /> : error ? <QuoteLoadError message={error} retry={retry} /> : data?.data.length ? <QuoteListTable reminderQuoteIds={reminderQuoteIds} rows={data.data} sortingByStatus={sort === 'status'} onToggleSort={() => updateFilter('sort', sort === 'status' ? 'date' : 'status')} /> : <p className="px-5 py-8 text-center text-sm text-slate-600">{filtered ? 'No hay cotizaciones con estos filtros. Prueba otro estatus o fecha.' : 'Todavía no hay cotizaciones.'}</p>}
            {data && !error && <div className="mt-auto">
              <QuotePagination data={data} onPage={(page) => updateFilter('page', String(page))} loading={loading} label="Páginas de cotizaciones generales" />
              <p className="border-t border-slate-100 px-5 py-3 text-xs text-slate-600">{data.counts.sent} enviadas · {data.counts.unsent} no enviadas</p>
            </div>}
          </div>
        </LoadingOverlay>
      </div>
    </CardBody>
  </Card>
}
