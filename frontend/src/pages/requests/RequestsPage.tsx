import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Plus, Search } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { Input, Label, Select } from '@/components/ui/Input'
import { LoadingState, TableSkeleton } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { usePermission } from '@/hooks/usePermission'
import { formatDateTime } from '@/lib/format'
import { listSolicitudes, mapSolicitudApiToQuoteRequest } from '@/lib/solicitudes-api'
import {
  REQUEST_WORKFLOW_LABELS,
  type QuoteRequest,
  type RequestWorkflowStatus,
} from '@/types'

const workflowVariant: Record<
  RequestWorkflowStatus,
  'warning' | 'brand' | 'success' | 'danger'
> = {
  en_elaboracion: 'brand',
  pendiente_envio: 'warning',
  enviada: 'success',
}

const ALL_STATUSES = '' as const

export function RequestsPage() {
  const { can } = usePermission()
  const canCreateClient = can('clientes', 'create')
  const [requests, setRequests] = useState<QuoteRequest[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [statusFilter, setStatusFilter] = useState<RequestWorkflowStatus | typeof ALL_STATUSES>(
    ALL_STATUSES,
  )
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    let cancelled = false
    setLoading(true)

    listSolicitudes({
      workflowStatus: statusFilter || undefined,
      q: debouncedSearch || undefined,
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
  }, [statusFilter, debouncedSearch])

  return (
    <div>
      <PageHeader
        title="Solicitudes"
        description="Recepción y lectura automática de requerimientos"
        actions={
          <Link to="/solicitudes/nueva">
            <Button size="sm">
              <Plus className="h-4 w-4" />
              Nueva solicitud
            </Button>
          </Link>
        }
      />

      <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:max-w-2xl">
        <div>
          <Label>Estado</Label>
          <Select
            value={statusFilter}
            onChange={(e) =>
              setStatusFilter(e.target.value as RequestWorkflowStatus | typeof ALL_STATUSES)
            }
          >
            <option value={ALL_STATUSES}>Todos</option>
            {(Object.keys(REQUEST_WORKFLOW_LABELS) as RequestWorkflowStatus[]).map((status) => (
              <option key={status} value={status}>
                {REQUEST_WORKFLOW_LABELS[status]}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label>Buscar</Label>
          <div className="flex gap-2">
            <div className="relative min-w-0 flex-1">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <Input
                className="pl-9"
                placeholder="Cliente o ID de solicitud"
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
              <TableSkeleton rows={5} cols={6} />
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
                  <th className="px-5 py-3 font-medium">Archivo / texto</th>
                  <th className="px-5 py-3 font-medium">Estado</th>
                  <th className="px-5 py-3 font-medium">Revisión</th>
                  <th className="px-5 py-3 font-medium">Fecha lectura</th>
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
                    <td className="max-w-xs truncate px-5 py-3 text-slate-600">
                      {r.fileName ?? r.rawText?.slice(0, 50) ?? '—'}
                    </td>
                    <td className="px-5 py-3">
                      <Badge variant={workflowVariant[r.workflowStatus ?? 'en_elaboracion']}>
                        {REQUEST_WORKFLOW_LABELS[r.workflowStatus ?? 'en_elaboracion']}
                      </Badge>
                    </td>
                    <td className="px-5 py-3">
                      {r.reviewedByName ? (
                        <Badge variant="success">Revisada · {r.reviewedByName}</Badge>
                      ) : (
                        <Badge variant="warning">Pendiente</Badge>
                      )}
                    </td>
                    <td className="px-5 py-3 text-slate-500">
                      {r.lecturaAt
                        ? formatDateTime(r.lecturaAt)
                        : r.status === 'procesando'
                          ? 'En proceso'
                          : '—'}
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
