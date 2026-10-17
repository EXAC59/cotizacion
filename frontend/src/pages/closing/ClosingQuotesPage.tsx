import { useEffect, useState } from 'react'
import { ArrowDown, ArrowUp, ArrowUpDown, BookOpenText, Pencil, Search } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input, Label, Select } from '@/components/ui/Input'
import { PageHeader } from '@/components/ui/PageHeader'
import { usePermission } from '@/hooks/usePermission'
import { formatCurrency, formatDate } from '@/lib/format'
import { listQuotes } from '@/lib/quotes-api'
import { ClosingQuoteDrawer } from './ClosingQuoteDrawer'
import { ClosingQuotePreview } from './ClosingQuotePreview'
import { CLOSING_LABELS as STATUS_LABELS, CLOSING_STYLES as STATUS_STYLES, type ClosingStatus } from '@/lib/quote-closing'
import { quoteMatchesSearch } from '@/lib/quote-search'
import type { Quote } from '@/types'

const STATUS_ORDER = Object.keys(STATUS_LABELS) as ClosingStatus[]

function elaborationDate(quote: Quote) {
  return quote.elaborationDate ? `${quote.elaborationDate}T12:00:00` : quote.createdAt
}

export function ClosingQuotesPage() {
  const { can, user } = usePermission()
  const [quotes, setQuotes] = useState<Quote[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<ClosingStatus | ''>('')
  const [newestFirst, setNewestFirst] = useState(true)
  const [statusSort, setStatusSort] = useState<'ascending' | 'descending' | null>(null)
  const [retry, setRetry] = useState(0)
  const [editing, setEditing] = useState<string | null>(null)
  const [previewing, setPreviewing] = useState<string | null>(null)
  const [savedMessage, setSavedMessage] = useState('')
  const canRegisterClosing = can('cotizaciones', 'edit') && ['ventas', 'gerente_compras', 'administrador'].includes(user?.role ?? '')
  const openClosing = (quoteId: string) => { setSavedMessage(''); setEditing(quoteId) }

  useEffect(() => {
    let cancelled = false
    listQuotes({ scope: 'all', sort: 'newest' })
      .then((data) => {
        if (!cancelled) setQuotes(data.filter((quote) => Boolean(quote.sentAt)))
      })
      .catch(() => { if (!cancelled) setError('No se pudieron cargar las cotizaciones.') })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [retry])

  const rows = quotes.filter((quote) => quoteMatchesSearch(quote, search)
    && (!status || (quote.closingStatus ?? 'enviada') === status))
    .sort((a, b) => {
      if (statusSort) {
        const difference = STATUS_ORDER.indexOf(a.closingStatus ?? 'enviada')
          - STATUS_ORDER.indexOf(b.closingStatus ?? 'enviada')
        if (difference) return statusSort === 'ascending' ? difference : -difference
      }
      const difference = new Date(elaborationDate(b)).getTime() - new Date(elaborationDate(a)).getTime()
      return (newestFirst ? difference : -difference) || a.folio.localeCompare(b.folio)
    })

  return (
    <div className="min-w-0">
      <PageHeader title="Cierre de cotizaciones" />
      {savedMessage && <p role="status" className="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{savedMessage}</p>}
      <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-label="Cotizaciones para cierre">
        <div className="grid gap-4 border-b border-slate-200 p-5 sm:grid-cols-[minmax(0,1fr)_240px] sm:p-6">
          <div>
            <Label htmlFor="closing-search">Buscar</Label>
            <div className="relative">
              <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" aria-hidden />
              <Input id="closing-search" type="search" placeholder="Folio o cliente" value={search} onChange={(event) => setSearch(event.target.value)} className="min-h-11 pl-10" />
            </div>
          </div>
          <div>
            <Label htmlFor="closing-status">Estatus</Label>
            <Select id="closing-status" value={status} onChange={(event) => setStatus(event.target.value as ClosingStatus | '')} className="min-h-11">
              <option value="">Todos los estatus</option>
              {Object.entries(STATUS_LABELS).map(([value, label]) => <option key={value} value={value} className={STATUS_STYLES[value as ClosingStatus]}>{label}</option>)}
            </Select>
          </div>
        </div>
        <div className="overflow-x-auto focus-visible:outline-2 focus-visible:outline-indigo-600" role="region" aria-label="Tabla de cierre de cotizaciones" tabIndex={0}>
          <table className="w-full min-w-[1000px] text-left text-sm" aria-busy={loading}>
            <caption className="sr-only">Cierre de cotizaciones</caption>
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-600">
              <tr className="[&>th]:px-5 [&>th]:py-4 [&>th]:font-semibold">
                <th scope="col">Folio</th>
                <th scope="col">Cliente</th>
                <th scope="col" className="text-right">Monto total</th>
                <th scope="col">Hecha por</th>
                <th scope="col" aria-sort={statusSort ? 'none' : newestFirst ? 'descending' : 'ascending'}>
                  <span className="block whitespace-nowrap">Fecha de elaboración</span>
                  <button type="button" onClick={() => { setStatusSort(null); setNewestFirst(!newestFirst) }} className="mt-1 inline-flex min-h-11 items-center gap-2 rounded-lg px-2 text-xs font-medium text-indigo-700 transition-colors hover:bg-indigo-100 focus-visible:outline-2 focus-visible:outline-indigo-600" title={newestFirst ? 'Ordenar de más vieja a más nueva' : 'Ordenar de más nueva a más vieja'}>
                    {newestFirst ? <ArrowDown className="h-4 w-4" aria-hidden /> : <ArrowUp className="h-4 w-4" aria-hidden />}
                    {newestFirst ? 'Más nueva' : 'Más vieja'}
                  </button>
                </th>
                <th scope="col" aria-sort={statusSort ?? 'none'}>
                  <button type="button" onClick={() => setStatusSort((previous) => previous === 'ascending' ? 'descending' : 'ascending')} className={`inline-flex min-h-11 items-center gap-2 rounded-lg px-2 font-semibold transition-colors hover:bg-indigo-100 focus-visible:outline-2 focus-visible:outline-indigo-600 ${statusSort ? 'text-indigo-700' : ''}`} title={statusSort === 'ascending' ? 'Invertir orden de estatus' : 'Ordenar: Enviada al cliente → Perdida → Negociación → Ganada parcialmente → Ganada'}>
                    Estatus
                    {statusSort === 'ascending' ? <ArrowDown className="h-4 w-4" aria-hidden /> : statusSort === 'descending' ? <ArrowUp className="h-4 w-4" aria-hidden /> : <ArrowUpDown className="h-4 w-4" aria-hidden />}
                  </button>
                </th>
                <th scope="col" className="text-right">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {loading || error || rows.length === 0 ? (
                <tr><td colSpan={7} className="px-6 py-16 text-center text-slate-600">
                  <p role={error ? 'alert' : 'status'}>{loading ? 'Cargando cotizaciones…' : error || (search || status ? 'No hay cotizaciones que coincidan con los filtros.' : 'Aún no hay cotizaciones para cierre.')}</p>
                  {error && <Button variant="secondary" className="mt-4 min-h-11" onClick={() => { setLoading(true); setError(''); setRetry(retry + 1) }}>Reintentar</Button>}
                </td></tr>
              ) : rows.map((quote) => {
                const closingStatus = quote.closingStatus ?? 'enviada'
                return (
                  <tr key={quote.id} tabIndex={canRegisterClosing ? 0 : undefined} onClick={(event) => {
                    if (canRegisterClosing && !(event.target as HTMLElement).closest('button')) openClosing(quote.id)
                  }} onKeyDown={(event) => {
                    if (canRegisterClosing && event.target === event.currentTarget && ['Enter', ' '].includes(event.key)) {
                      event.preventDefault(); openClosing(quote.id)
                    }
                  }} className={`transition-colors hover:bg-slate-50/80 focus-visible:outline-2 focus-visible:outline-indigo-600 [&>td]:px-5 [&>td]:py-4 ${canRegisterClosing ? 'cursor-pointer' : ''}`}>
                    <td className="whitespace-nowrap font-semibold text-indigo-700"><button type="button" onClick={() => setPreviewing(quote.id)} aria-label={`Ver cotización ${quote.folio}`} className="min-h-11 rounded-lg text-left hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-600">{quote.folio}</button></td>
                    <td className="min-w-44 font-medium text-slate-900">{quote.clientName || 'Sin cliente'}</td>
                    <td className="whitespace-nowrap text-right font-semibold tabular-nums text-slate-900">{quote.total == null ? '—' : formatCurrency(quote.total)}</td>
                    <td className="min-w-36 text-slate-600">{quote.createdByName || 'Sin asignar'}</td>
                    <td className="whitespace-nowrap text-slate-600">{formatDate(elaborationDate(quote))}</td>
                    <td><span className={`inline-flex whitespace-nowrap rounded-full border px-3 py-1 text-xs font-semibold ${STATUS_STYLES[closingStatus]}`}>{STATUS_LABELS[closingStatus]}</span></td>
                    <td>
                      <div className="flex justify-end gap-2">
                        {canRegisterClosing ? <Button variant="secondary" className="h-11 w-11 p-0 text-indigo-700" aria-label={`Registrar cierre de ${quote.folio}`} title="Registrar cierre" onClick={() => openClosing(quote.id)}><Pencil className="h-4 w-4" aria-hidden /></Button>
                          : <Button variant="secondary" className="h-11 w-11 p-0" disabled aria-label={`Sin permiso para editar ${quote.folio}`} title="Sin permiso para editar"><Pencil className="h-4 w-4" aria-hidden /></Button>}
                        <Button variant="secondary" className="h-11 w-11 p-0" aria-label={`Ver cotización ${quote.folio}`} title="Ver cotización" onClick={() => setPreviewing(quote.id)}><BookOpenText className="h-4 w-4" aria-hidden /></Button>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        {!loading && !error && <p className="border-t border-slate-200 px-5 py-4 text-sm text-slate-600" role="status">{rows.length} {rows.length === 1 ? 'cotización' : 'cotizaciones'}</p>}
      </section>
      {editing && <ClosingQuoteDrawer key={editing} quoteId={editing} onClose={() => setEditing(null)} onSaved={(data) => {
        setQuotes((previous) => previous.map((quote) => quote.id === data.id ? { ...quote, closingStatus: data.status === 'enviada' ? null : data.status } : quote))
        setSavedMessage('Cierre guardado. El aviso quedó registrado en Mensajería.')
      }} />}
      {previewing && <ClosingQuotePreview key={previewing} quoteId={previewing} onClose={() => setPreviewing(null)} />}
    </div>
  )
}
