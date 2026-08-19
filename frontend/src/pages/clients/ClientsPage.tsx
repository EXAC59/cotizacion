import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Download, Pencil, Plus, Search, Trash2, Upload, X } from 'lucide-react'
import { DeleteClientDialog } from '@/components/clients/DeleteClientDialog'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { Input, Label } from '@/components/ui/Input'
import { LoadingState, ModalBusyPanel, TableSkeleton, beginModalBusy, waitMinBusyMs, waitModalBusyPaint } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { usePermission } from '@/hooks/usePermission'
import {
  ClientDeleteError,
  deleteClient,
  downloadClientsTemplate,
  importClients,
  listClients,
} from '@/lib/clients-api'
import { formatDate } from '@/lib/format'
import type { Client, ClientImportResult } from '@/types'

export function ClientsPage() {
  const { can } = usePermission()
  const canCreate = can('clientes', 'create')
  const canEdit = can('clientes', 'edit')
  const canDelete = can('clientes', 'delete')
  const canImport = can('clientes', 'import')

  const [clients, setClients] = useState<Client[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [importOpen, setImportOpen] = useState(false)
  const [importFile, setImportFile] = useState<File | null>(null)
  const [importing, setImporting] = useState(false)
  const [importResult, setImportResult] = useState<ClientImportResult | null>(null)
  const [importError, setImportError] = useState<string | null>(null)

  const [deleteTarget, setDeleteTarget] = useState<Client | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [search])

  const loadClients = useCallback(async (query?: string) => {
    setLoading(true)
    try {
      const data = await listClients(query ? { q: query } : undefined)
      setClients(data)
      setError(null)
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'No se pudieron cargar los clientes.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void loadClients(debouncedSearch || undefined)
  }, [debouncedSearch, loadClients])

  const openImportModal = () => {
    setImportFile(null)
    setImportResult(null)
    setImportError(null)
    setImportOpen(true)
  }

  const handleImport = async () => {
    if (!importFile) {
      setImportError('Selecciona un archivo Excel.')
      return
    }

    beginModalBusy(setImporting)
    await waitModalBusyPaint()
    const busyStarted = performance.now()
    setImportError(null)
    try {
      const result = await importClients(importFile)
      setImportResult(result)
      await loadClients(debouncedSearch || undefined)
    } catch (err: unknown) {
      setImportError(err instanceof Error ? err.message : 'No se pudo importar el archivo.')
    } finally {
      await waitMinBusyMs(busyStarted)
      setImporting(false)
    }
  }

  const openDelete = (client: Client) => {
    setDeleteError(null)
    setDeleteTarget(client)
  }

  const handleConfirmDelete = async () => {
    if (!deleteTarget) return
    beginModalBusy(setDeleting)
    await waitModalBusyPaint()
    const busyStarted = performance.now()
    setDeleteError(null)
    try {
      await deleteClient(deleteTarget.id)
      await waitMinBusyMs(busyStarted)
      setDeleteTarget(null)
      await loadClients(debouncedSearch || undefined)
    } catch (err: unknown) {
      if (err instanceof ClientDeleteError && (err.quotesCount ?? 0) > 0) {
        setDeleteTarget({
          ...deleteTarget,
          quotesCount: err.quotesCount,
        })
      }
      setDeleteError(err instanceof Error ? err.message : 'No se pudo eliminar el cliente.')
      await waitMinBusyMs(busyStarted)
    } finally {
      setDeleting(false)
    }
  }

  const showActions = canEdit || canDelete

  return (
    <div>
      <PageHeader
        title="Clientes"
        description="CRM — empresas, datos fiscales e historial de cotizaciones"
        actions={
          <>
            {canCreate && (
              <Link to="/clientes/nuevo">
                <Button size="sm">
                  <Plus className="h-4 w-4" />
                  Nuevo cliente
                </Button>
              </Link>
            )}
            {canImport && (
              <Button variant="secondary" size="sm" onClick={openImportModal}>
                <Upload className="h-4 w-4" />
                Importar Excel
              </Button>
            )}
          </>
        }
      />

      <div className="mb-4 max-w-md">
        <Label htmlFor="client-search">Buscar clientes</Label>
        <div className="relative mt-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <Input
            id="client-search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar por empresa, RFC, correo o contacto…"
            className="pl-9"
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
              <LoadingState label="Cargando clientes…" variant="inline" className="py-8" />
              <TableSkeleton rows={6} cols={5} />
            </div>
          ) : clients.length === 0 ? (
            <p className="px-5 py-8 text-sm text-slate-500">
              {debouncedSearch
                ? 'No hay clientes que coincidan con la búsqueda.'
                : 'No hay clientes registrados.'}
            </p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-slate-100 bg-slate-50 text-slate-500">
                  <tr>
                    <th className="px-5 py-3 font-medium">Empresa</th>
                    <th className="px-5 py-3 font-medium">RFC</th>
                    <th className="px-5 py-3 font-medium">Contacto</th>
                    <th className="px-5 py-3 font-medium">Correo</th>
                    <th className="px-5 py-3 font-medium">Pago</th>
                    <th className="px-5 py-3 font-medium">Alta</th>
                    {showActions && (
                      <th className="px-5 py-3 font-medium text-right">Acciones</th>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {clients.map((c) => (
                    <tr key={c.id} className="border-b border-slate-50 hover:bg-slate-50/80">
                      <td className="px-5 py-3">
                        <Link
                          to={`/clientes/${c.id}`}
                          className="font-medium text-indigo-600 hover:underline"
                        >
                          {c.company}
                        </Link>
                      </td>
                      <td className="px-5 py-3 text-slate-600">{c.rfc || '—'}</td>
                      <td className="px-5 py-3">{c.contact || '—'}</td>
                      <td className="px-5 py-3 text-slate-600">{c.email || '—'}</td>
                      <td className="px-5 py-3">{c.paymentTerms || '—'}</td>
                      <td className="px-5 py-3 text-slate-500">{formatDate(c.createdAt)}</td>
                      {showActions && (
                        <td className="px-5 py-3">
                          <div className="flex justify-end gap-1">
                            {canEdit && (
                              <Link
                                to={`/clientes/${c.id}/editar`}
                                className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-50"
                                title="Editar cliente"
                              >
                                <Pencil className="h-3.5 w-3.5" />
                                Editar
                              </Link>
                            )}
                            {canDelete && (
                              <button
                                type="button"
                                className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-red-700 hover:bg-red-50"
                                title="Eliminar cliente"
                                onClick={() => openDelete(c)}
                              >
                                <Trash2 className="h-3.5 w-3.5" />
                                Eliminar
                              </button>
                            )}
                          </div>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      <DeleteClientDialog
        open={Boolean(deleteTarget)}
        company={deleteTarget?.company ?? ''}
        quotesCount={deleteTarget?.quotesCount ?? deleteTarget?.stats?.quotesCount ?? 0}
        deleting={deleting}
        error={deleteError}
        onClose={() => {
          if (!deleting) {
            setDeleteTarget(null)
            setDeleteError(null)
          }
        }}
        onConfirm={() => void handleConfirmDelete()}
      />

      {importOpen && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
          role="dialog"
          aria-modal="true"
          onClick={() => !importing && setImportOpen(false)}
        >
          <div
            className="relative w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-xl"
            onClick={(e) => e.stopPropagation()}
          >
            {importing ? (
              <ModalBusyPanel label="Importando clientes…" />
            ) : (
              <>
                <div className="mb-4 flex items-start justify-between gap-4">
                  <div>
                    <h3 className="text-lg font-bold text-slate-900">Importar clientes desde Excel</h3>
                    <p className="mt-1 text-sm text-slate-500">
                      Una fila por cliente. Empresa es obligatoria; si el RFC ya existe, se actualiza.
                    </p>
                  </div>
                  <button
                    type="button"
                    className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"
                    onClick={() => setImportOpen(false)}
                    aria-label="Cerrar"
                  >
                    <X className="h-5 w-5" />
                  </button>
                </div>

                <div className="space-y-4">
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() =>
                      void downloadClientsTemplate().catch(() =>
                        setImportError('No se pudo descargar la plantilla.'),
                      )
                    }
                  >
                    <Download className="h-4 w-4" />
                    Descargar plantilla
                  </Button>

                  <div>
                    <Label>Archivo Excel (.xlsx / .xls)</Label>
                    <Input
                      type="file"
                      accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
                      onChange={(e) => setImportFile(e.target.files?.[0] ?? null)}
                    />
                  </div>

                  {importError && <p className="text-sm text-red-600">{importError}</p>}

                  {importResult && (
                    <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                      <p>
                        <strong>{importResult.created}</strong> creados,{' '}
                        <strong>{importResult.updated}</strong> actualizados,{' '}
                        <strong>{importResult.skipped}</strong> omitidos.
                      </p>
                      {importResult.errors.length > 0 && (
                        <ul className="mt-2 max-h-32 overflow-y-auto text-red-700">
                          {importResult.errors.map((err) => (
                            <li key={`${err.row}-${err.message}`}>
                              Fila {err.row}: {err.message}
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  )}
                </div>

                <div className="mt-6 flex justify-end gap-2">
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => setImportOpen(false)}
                  >
                    Cerrar
                  </Button>
                  <Button
                    size="sm"
                    disabled={!importFile}
                    onClick={() => void handleImport()}
                  >
                    Importar
                  </Button>
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
