import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, FileText, Pencil, Plus, Trash2 } from 'lucide-react'
import { DeleteClientDialog } from '@/components/clients/DeleteClientDialog'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { InlineBusy, LoadingState } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { QuoteStatusBadge } from '@/components/ui/QuoteStatusBadge'
import { usePermission } from '@/hooks/usePermission'
import {
  ClientDeleteError,
  deleteClient,
  getClientDetail,
  getClientQuotes,
} from '@/lib/clients-api'
import { formatCurrency, formatDate, formatDateTime } from '@/lib/format'
import type { Client, ClientQuoteSummary } from '@/types'

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-1 text-sm text-slate-900">{value || '—'}</dd>
    </div>
  )
}

export function ClientDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { can } = usePermission()
  const canEdit = can('clientes', 'edit')
  const canDelete = can('clientes', 'delete')
  const canCreateQuote = can('cotizaciones', 'create')

  const [client, setClient] = useState<Client | null>(null)
  const [quotes, setQuotes] = useState<ClientQuoteSummary[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)
  const [deleteQuotesCount, setDeleteQuotesCount] = useState(0)

  useEffect(() => {
    if (!id) return

    let cancelled = false
    setLoading(true)

    Promise.all([getClientDetail(id), getClientQuotes(id)])
      .then(([clientData, quoteData]) => {
        if (!cancelled) {
          setClient(clientData)
          setQuotes(quoteData)
          setError(null)
        }
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'No se pudo cargar el cliente.')
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [id])

  const openDelete = () => {
    setDeleteError(null)
    setDeleteQuotesCount(client?.stats?.quotesCount ?? quotes.length)
    setDeleteOpen(true)
  }

  const handleConfirmDelete = async () => {
    if (!id || !client) return

    setDeleting(true)
    setDeleteError(null)
    try {
      await deleteClient(id)
      navigate('/clientes')
    } catch (err: unknown) {
      if (err instanceof ClientDeleteError && (err.quotesCount ?? 0) > 0) {
        setDeleteQuotesCount(err.quotesCount ?? 0)
      }
      setDeleteError(err instanceof Error ? err.message : 'No se pudo eliminar el cliente.')
      setDeleting(false)
    }
  }

  if (loading) {
    return <LoadingState label="Cargando ficha del cliente…" variant="page" />
  }

  if (!client) {
    return (
      <div>
        <p className="mb-4 text-sm text-red-600">{error ?? 'Cliente no encontrado.'}</p>
        <Link to="/clientes">
          <Button variant="secondary" size="sm">
            Volver al listado
          </Button>
        </Link>
      </div>
    )
  }

  const stats = client.stats

  return (
    <div>
      <PageHeader
        title={client.company}
        description="Ficha CRM — datos fiscales e historial de cotizaciones"
        actions={
          <>
            <Link to="/clientes">
              <Button variant="secondary" size="sm">
                <ArrowLeft className="h-4 w-4" />
                Volver
              </Button>
            </Link>
            {canCreateQuote && (
              <Link to={`/cotizaciones/nueva?clientId=${client.id}`}>
                <Button variant="secondary" size="sm">
                  <Plus className="h-4 w-4" />
                  Nueva cotización
                </Button>
              </Link>
            )}
            {canEdit && (
              <Link to={`/clientes/${client.id}/editar`}>
                <Button variant="secondary" size="sm">
                  <Pencil className="h-4 w-4" />
                  Editar
                </Button>
              </Link>
            )}
            {canDelete && (
              <Button variant="danger" size="sm" disabled={deleting} onClick={openDelete}>
                {deleting ? <InlineBusy size="sm" /> : <Trash2 className="h-4 w-4" />}
                Eliminar
              </Button>
            )}
          </>
        }
      />

      <DeleteClientDialog
        open={deleteOpen}
        company={client.company}
        quotesCount={deleteQuotesCount}
        deleting={deleting}
        error={deleteError}
        onClose={() => {
          if (!deleting) {
            setDeleteOpen(false)
            setDeleteError(null)
          }
        }}
        onConfirm={() => void handleConfirmDelete()}
      />

      {error && (
        <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          {error}
        </p>
      )}

      {stats && (
        <div className="mb-6 grid gap-4 sm:grid-cols-3">
          <Card>
            <CardBody>
              <p className="text-xs font-medium uppercase text-slate-500">Cotizaciones</p>
              <p className="mt-1 text-2xl font-bold text-slate-900">{stats.quotesCount}</p>
            </CardBody>
          </Card>
          <Card>
            <CardBody>
              <p className="text-xs font-medium uppercase text-slate-500">Monto acumulado</p>
              <p className="mt-1 text-2xl font-bold text-slate-900">
                {formatCurrency(stats.quotesTotal)}
              </p>
            </CardBody>
          </Card>
          <Card>
            <CardBody>
              <p className="text-xs font-medium uppercase text-slate-500">Última cotización</p>
              <p className="mt-1 text-lg font-semibold text-slate-900">
                {stats.lastQuoteAt ? formatDateTime(stats.lastQuoteAt) : '—'}
              </p>
            </CardBody>
          </Card>
        </div>
      )}

      <div className="grid gap-6 xl:grid-cols-2">
        <Card>
          <CardHeader title="Datos fiscales" subtitle="Información para cotizaciones y facturación" />
          <CardBody>
            <dl className="grid gap-4 sm:grid-cols-2">
              <Field label="Empresa / razón social" value={client.company} />
              <Field label="RFC" value={client.rfc} />
              <div className="sm:col-span-2">
                <Field label="Domicilio fiscal" value={client.address} />
              </div>
              <Field label="Condiciones de pago" value={client.paymentTerms} />
            </dl>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Contacto" />
          <CardBody>
            <dl className="grid gap-4 sm:grid-cols-2">
              <Field label="Persona de contacto" value={client.contact} />
              <Field label="Correo" value={client.email} />
              <Field label="WhatsApp" value={client.whatsapp} />
              <Field label="Alta en sistema" value={formatDate(client.createdAt)} />
            </dl>
          </CardBody>
        </Card>
      </div>

      <Card className="mt-6">
        <CardHeader
          title="Historial de cotizaciones"
          subtitle={`${quotes.length} cotización(es) · quién las hizo y en qué estado`}
        />
        <CardBody className="p-0">
          {quotes.length === 0 ? (
            <p className="px-5 py-8 text-sm text-slate-500">Este cliente aún no tiene cotizaciones.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-slate-100 bg-slate-50 text-slate-500">
                  <tr>
                    <th className="px-5 py-3 font-medium">Folio</th>
                    <th className="px-5 py-3 font-medium">Hecha por</th>
                    <th className="px-5 py-3 font-medium">Estado</th>
                    <th className="px-5 py-3 font-medium">Total</th>
                    <th className="px-5 py-3 font-medium">Partidas</th>
                    <th className="px-5 py-3 font-medium">Fecha</th>
                    <th className="px-5 py-3 font-medium">Enviada</th>
                  </tr>
                </thead>
                <tbody>
                  {quotes.map((q) => (
                    <tr key={q.id} className="border-b border-slate-50 hover:bg-slate-50/80">
                      <td className="px-5 py-3">
                        <Link
                          to={`/cotizaciones/${q.id}`}
                          className="inline-flex items-center gap-1 font-medium text-indigo-600 hover:underline"
                        >
                          <FileText className="h-4 w-4" />
                          {q.folio}
                        </Link>
                      </td>
                      <td className="px-5 py-3 font-medium text-slate-800">
                        {q.createdByName?.trim() || 'Sin asignar'}
                      </td>
                      <td className="px-5 py-3">
                        <QuoteStatusBadge status={q.status} />
                      </td>
                      <td className="px-5 py-3">{formatCurrency(q.total)}</td>
                      <td className="px-5 py-3">{q.linesCount}</td>
                      <td className="px-5 py-3 text-slate-600">
                        {q.createdAt ? formatDate(q.createdAt) : '—'}
                      </td>
                      <td className="px-5 py-3 text-slate-600">
                        {q.sentAt ? formatDateTime(q.sentAt) : '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  )
}
