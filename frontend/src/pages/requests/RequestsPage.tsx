import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Plus, Search } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { Input, Label } from '@/components/ui/Input'
import { LoadingState, TableSkeleton } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { ViewerListScopeTabs } from '@/components/ui/ViewerListScopeTabs'
import { useAuth } from '@/hooks/useAuth'
import { usePermission } from '@/hooks/usePermission'
import { formatDateTime } from '@/lib/format'
import { listSolicitudes, mapSolicitudApiToQuoteRequest } from '@/lib/solicitudes-api'
import { parseViewerListScope, type ViewerListScope } from '@/lib/viewer-list-scope'
import type { QuoteRequest } from '@/types'

export function RequestsPage() {
  const { user } = useAuth()
  const { can } = usePermission()
  const canCreateClient = can('clientes', 'create')
  const [requests, setRequests] = useState<QuoteRequest[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [listScope, setListScope] = useState<ViewerListScope>(() =>
    parseViewerListScope(null, user?.role),
  )

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    let cancelled = false
    setLoading(true)

    listSolicitudes({
      q: debouncedSearch || undefined,
      scope: listScope,
      from: dateFrom || undefined,
      to: dateTo || undefined,
    })
      .then((data) => {
        if (cancelled) return
        setRequests(data.map((api) => mapSolicitudApiToQuoteRequest(api)))
        setError(null)
      })
      .catch((err: unknown) => {
        if (cancelled) return
        setError(err instanceof Error ? err.message : 'No se pudieron cargar las solicitudes.')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [debouncedSearch, listScope, dateFrom, dateTo])

  return (
    <div>
      <PageHeader
        title="Solicitudes"
        description="Recepción y lectura automática. Mías son las tuyas; Todas incluye las del equipo."
        actions={
          <Link to="/solicitudes/nueva">
            <Button size="sm">
              <Plus className="h-4 w-4" />
              Nueva solicitud
            </Button>
          </Link>
        }
      />

      <ViewerListScopeTabs value={listScope} onChange={setListScope} />

      <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:max-w-5xl">
        <div className="min-w-0 sm:col-span-2">
          <Label htmlFor="solicitud-search">Buscar</Label>
          <div className="flex items-center gap-2">
            <div className="relative min-w-0 flex-1">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <Input
                id="solicitud-search"
                type="search"
                className="h-10 pl-9"
                placeholder="Cliente o ID de solicitud"
                title="Cliente o ID de solicitud"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            {canCreateClient && (
              <Link
                to="/clientes/nuevo?returnTo=/solicitudes/nueva"
                title="Agregar cliente"
                className="shrink-0"
              >
                <Button type="button" variant="secondary" size="sm" className="h-10 w-10 px-0">
                  <Plus className="h-4 w-4" />
                  <span className="sr-only">Agregar cliente</span>
                </Button>
              </Link>
            )}
          </div>
        </div>
        <div>
          <Label htmlFor="solicitud-date-from">Desde</Label>
          <Input
            id="solicitud-date-from"
            type="date"
            className="h-10"
            value={dateFrom}
            max={dateTo || undefined}
            onChange={(e) => setDateFrom(e.target.value)}
          />
        </div>
        <div>
          <Label htmlFor="solicitud-date-to">Hasta</Label>
          <Input
            id="solicitud-date-to"
            type="date"
            className="h-10"
            value={dateTo}
            min={dateFrom || undefined}
            onChange={(e) => setDateTo(e.target.value)}
          />
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          {error}
        </p>
      )}

      <Card>
        <CardBody className="p-0">
          {loading ? (
            <div>
              <LoadingState label="Cargando solicitudes…" variant="inline" className="py-8" />
              <TableSkeleton rows={5} cols={5} />
            </div>
          ) : requests.length === 0 ? (
            <p className="px-5 py-8 text-center text-sm text-slate-500">
              No hay solicitudes con los filtros actuales.
            </p>
          ) : (
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-100 bg-slate-50 text-slate-500">
                <tr>
                  <th className="px-5 py-3 font-medium">Folio</th>
                  <th className="px-5 py-3 font-medium">Cliente</th>
                  <th className="px-5 py-3 font-medium">Usuario</th>
                  <th className="px-5 py-3 font-medium">Involucrado</th>
                  <th className="px-5 py-3 font-medium">Fecha creada</th>
                </tr>
              </thead>
              <tbody>
                {requests.map((r) => (
                  <tr key={r.id} className="border-b border-slate-50 hover:bg-slate-50/80">
                    <td className="px-5 py-3">
                      <Link
                        to={`/solicitudes/${r.id}`}
                        className="font-medium text-indigo-600 hover:underline"
                      >
                        {r.folio ?? r.id.slice(0, 8).toUpperCase()}
                      </Link>
                    </td>
                    <td className="px-5 py-3">{r.clientName ?? '—'}</td>
                    <td className="px-5 py-3 text-slate-600">{r.createdByName ?? '—'}</td>
                    <td className="px-5 py-3 text-slate-600">{r.involucrado ?? '—'}</td>
                    <td className="px-5 py-3 text-slate-500">
                      {formatDateTime(r.createdAt)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </CardBody>
      </Card>
    </div>
  )
}
