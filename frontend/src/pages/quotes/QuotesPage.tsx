import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ArrowDownUp, Plus, Search } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { Input, Label, Select } from '@/components/ui/Input'
import { LoadingState, TableSkeleton } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { QuoteListTable } from '@/components/quotes/QuoteListTable'
import { ViewerListScopeTabs } from '@/components/ui/ViewerListScopeTabs'
import { useAuth } from '@/hooks/useAuth'
import { usePermission } from '@/hooks/usePermission'
import { useData } from '@/hooks/useData'
import { matchesQuoteListFilters } from '@/lib/quote-list-filters'
import { buildQuoteSearchParams } from '@/lib/quote-search-params'
import { QUOTE_WORKFLOW_ORDER, quoteWorkflowIndex } from '@/lib/quote-status'
import { listQuotes } from '@/lib/quotes-api'
import { parseViewerListScope, type ViewerListScope } from '@/lib/viewer-list-scope'
import { QUOTE_STATUS_LABELS, type Quote, type QuoteStatus } from '@/types'

type QuoteListSort = 'date' | 'status'

export function QuotesPage() {
  const { quotes: localQuotes, saveQuote } = useData()
  const { user } = useAuth()
  const { canCreateQuotes } = usePermission()
  const canCreate = canCreateQuotes()
  const [searchParams, setSearchParams] = useSearchParams()

  const statusFilter = (searchParams.get('status') ?? '') as QuoteStatus | ''
  const urlSearch = searchParams.get('q') ?? ''
  const dateFrom = searchParams.get('from') ?? ''
  const dateTo = searchParams.get('to') ?? ''
  // Cotizaciones abre mostrando al equipo completo; "Mías" queda como filtro opcional.
  const listScope = parseViewerListScope(searchParams.get('scope') ?? 'all', user?.role)

  const [searchInput, setSearchInput] = useState(urlSearch)
  const [apiQuotes, setApiQuotes] = useState<Quote[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [listSort, setListSort] = useState<QuoteListSort>('date')
  const [dateSort, setDateSort] = useState<'oldest' | 'newest'>('oldest')

  useEffect(() => {
    setSearchInput(urlSearch)
  }, [urlSearch])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      const trimmed = searchInput.trim()
      setSearchParams(
        (prev) => {
          const current = prev.get('q') ?? ''
          if (trimmed === current) {
            return prev
          }
          return buildQuoteSearchParams(prev, trimmed)
        },
        { replace: true },
      )
    }, 300)

    return () => window.clearTimeout(timer)
  }, [searchInput, setSearchParams])

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)

    listQuotes({
      search: urlSearch.trim() || undefined,
      status: statusFilter || undefined,
      scope: listScope,
      sort: dateSort,
      from: dateFrom || undefined,
      to: dateTo || undefined,
    })
      .then((data) => {
        if (cancelled) return
        setApiQuotes(data)
        data.forEach((q) => saveQuote(q))
      })
      .catch(() => {
        if (!cancelled) setError('No se pudieron cargar cotizaciones del servidor.')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [urlSearch, statusFilter, listScope, dateFrom, dateTo, dateSort, saveQuote])

  const quotes = useMemo(() => {
    const source = error ? localQuotes : apiQuotes
    const filtered = error
      ? source.filter((q) =>
          matchesQuoteListFilters(q, urlSearch, statusFilter, dateFrom || undefined, dateTo || undefined),
        )
      : source

    const statusRank = (status: Quote['status']) => {
      const index = quoteWorkflowIndex(status)
      return index === -1 ? QUOTE_WORKFLOW_ORDER.length : index
    }

    return [...filtered].sort((a, b) => {
      if (listSort === 'status') {
        const byStatus = statusRank(a.status) - statusRank(b.status)
        if (byStatus !== 0) return byStatus
      }
      const byDate = new Date(a.createdAt).getTime() - new Date(b.createdAt).getTime()
      return (dateSort === 'oldest' ? byDate : -byDate) || a.id.localeCompare(b.id)
    })
  }, [apiQuotes, localQuotes, error, urlSearch, statusFilter, dateFrom, dateTo, listSort, dateSort])

  const setListScope = (value: ViewerListScope) => {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        const fallback = parseViewerListScope(null, user?.role)
        if (value === fallback) {
          next.delete('scope')
        } else {
          next.set('scope', value)
        }
        return next
      },
      { replace: true },
    )
  }

  const setStatusFilter = (value: QuoteStatus | '') => {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (value) {
          next.set('status', value)
        } else {
          next.delete('status')
        }
        return next
      },
      { replace: true },
    )
  }

  const setDateFilter = (key: 'from' | 'to', value: string) => {
    setSearchParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        if (value) {
          next.set(key, value)
        } else {
          next.delete(key)
        }
        return next
      },
      { replace: true },
    )
  }

  const hasActiveFilters = Boolean(urlSearch.trim() || statusFilter || dateFrom || dateTo)
  const sortingByStatus = listSort === 'status'

  return (
    <div>
      <PageHeader
        title="COTIZACIONES"
        actions={
          canCreate ? (
            <Link to="/cotizaciones/nueva">
              <Button size="sm">
                <Plus className="h-4 w-4" />
                Nueva cotización
              </Button>
            </Link>
          ) : undefined
        }
      />

      <ViewerListScopeTabs value={listScope} onChange={setListScope} />

      {urlSearch.trim() && (
        <p className="mb-4 flex items-center gap-2 rounded-lg border border-indigo-100 bg-indigo-50/60 px-4 py-2 text-sm text-indigo-900">
          <Search className="h-4 w-4 shrink-0" />
          Buscando: <strong>{urlSearch.trim()}</strong>
        </p>
      )}

      <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:max-w-5xl">
        <div>
          <Label htmlFor="quote-search">Buscar por folio o cliente</Label>
          <div className="relative mt-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input
              id="quote-search"
              type="search"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Buscar folio o cliente (ej. demo-001)…"
              className="pl-9"
            />
          </div>
        </div>
        <div>
          <Label htmlFor="quote-status-filter">Filtrar por estatus</Label>
          <Select
            id="quote-status-filter"
            className="mt-1"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as QuoteStatus | '')}
          >
            <option value="">Todos los estatus</option>
            {QUOTE_WORKFLOW_ORDER.map((status) => (
              <option key={status} value={status}>
                {QUOTE_STATUS_LABELS[status]}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label htmlFor="quote-date-from">Desde</Label>
          <Input
            id="quote-date-from"
            type="date"
            className="mt-1"
            value={dateFrom}
            max={dateTo || undefined}
            onChange={(e) => setDateFilter('from', e.target.value)}
          />
        </div>
        <div>
          <Label htmlFor="quote-date-to">Hasta</Label>
          <Input
            id="quote-date-to"
            type="date"
            className="mt-1"
            value={dateTo}
            min={dateFrom || undefined}
            onChange={(e) => setDateFilter('to', e.target.value)}
          />
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          {error} Mostrando cotizaciones locales filtradas.
        </p>
      )}

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Button variant="secondary" size="sm" disabled={loading} onClick={() => {
          setDateSort((previous) => previous === 'oldest' ? 'newest' : 'oldest')
          setListSort('date')
        }}>
          <ArrowDownUp className="h-4 w-4" />
          {dateSort === 'oldest' ? 'Ver más nuevas' : 'Ver más antiguas'}
        </Button>
      </div>

      <Card>
        <CardBody className="p-0">
          {loading ? (
            <div>
              <LoadingState label="Cargando cotizaciones…" variant="inline" className="py-8" />
              <TableSkeleton rows={6} cols={6} />
            </div>
          ) : quotes.length === 0 ? (
            <p className="py-12 text-center text-slate-500">
              {hasActiveFilters
                ? 'No hay cotizaciones que coincidan con la búsqueda o el estatus seleccionado.'
                : 'No hay cotizaciones aún.'}
            </p>
          ) : (
            <QuoteListTable
              rows={quotes}
              sortingByStatus={sortingByStatus}
              onToggleSort={() => setListSort((prev) => prev === 'status' ? 'date' : 'status')}
            />
          )}
        </CardBody>
      </Card>
    </div>
  )
}
