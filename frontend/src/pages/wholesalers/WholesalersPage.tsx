import { useEffect, useMemo, useState } from 'react'
import { Badge } from '@/components/ui/Badge'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { LoadingState, TableSkeleton } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { usePermission } from '@/hooks/usePermission'
import { listWholesalers, updateWholesalerActive } from '@/lib/wholesalers-api'
import type { Wholesaler } from '@/types'

const integrationLabels: Record<Wholesaler['integration'], string> = {
  api: 'API',
  xml: 'XML',
  csv: 'CSV',
  ftp: 'FTP',
  scraping: 'Web Scraping',
}

export function WholesalersPage() {
  const { can } = usePermission()
  const canEditWholesalers = can('mayoristas', 'edit')
  const [wholesalers, setWholesalers] = useState<Wholesaler[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [savingId, setSavingId] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false

    listWholesalers()
      .then((data) => {
        if (!cancelled) {
          setWholesalers(data)
          setError(null)
        }
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'No se pudieron cargar los mayoristas.')
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [])

  const activeWholesalers = useMemo(
    () => wholesalers.filter((w) => w.active),
    [wholesalers],
  )

  async function handleToggle(id: string, active: boolean) {
    setSavingId(id)
    try {
      const updated = await updateWholesalerActive(id, active)
      setWholesalers((prev) => prev.map((w) => (w.id === id ? updated : w)))
      setError(null)
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'No se pudo actualizar el mayorista.')
    } finally {
      setSavingId(null)
    }
  }

  if (loading) {
    return (
      <div>
        <PageHeader title="Mayoristas" description="Cargando catálogo…" />
        <Card>
          <CardBody className="p-0">
            <LoadingState label="Cargando mayoristas…" variant="inline" className="py-8" />
            <TableSkeleton rows={5} cols={5} />
          </CardBody>
        </Card>
      </div>
    )
  }

  return (
    <div>
      <PageHeader
        title="Mayoristas"
        description="Activa o desactiva con cuáles trabajar al cotizar y consultar inventario"
      />

      {error && (
        <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </p>
      )}

      <Card>
        <CardHeader
          title="Catálogo de mayoristas"
          subtitle={`${activeWholesalers.length} de ${wholesalers.length} activos para precio y stock`}
        />
        <CardBody className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b bg-slate-50 text-slate-500">
                <tr>
                  <th className="px-4 py-2.5 font-medium">Mayorista</th>
                  <th className="px-4 py-2.5 font-medium">Código</th>
                  <th className="px-4 py-2.5 font-medium">Integración</th>
                  <th className="px-4 py-2.5 font-medium">Estado</th>
                  <th className="px-4 py-2.5 text-center font-medium">Activo</th>
                </tr>
              </thead>
              <tbody>
                {wholesalers.map((w) => (
                  <tr
                    key={w.id}
                    className={`border-b border-slate-50 ${w.active ? 'bg-white' : 'bg-slate-50/60 text-slate-500'}`}
                  >
                    <td className="px-4 py-2.5 font-medium text-slate-900">{w.name}</td>
                    <td className="px-4 py-2.5 font-mono text-xs text-slate-600">{w.code}</td>
                    <td className="px-4 py-2.5 text-slate-600">
                      {integrationLabels[w.integration]}
                    </td>
                    <td className="px-4 py-2.5">
                      <div className="flex flex-wrap gap-1.5">
                        <Badge variant={w.active ? 'success' : 'muted'}>
                          {w.active ? 'En uso' : 'Desactivado'}
                        </Badge>
                        <Badge variant={w.configured ? 'brand' : 'warning'}>
                          {w.configured ? 'Configurado' : 'Pendiente credenciales'}
                        </Badge>
                      </div>
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      <label
                        className={`inline-flex items-center justify-center ${canEditWholesalers ? 'cursor-pointer' : 'cursor-default'}`}
                        title={
                          w.active
                            ? 'Incluido en comparativos'
                            : 'No se consulta ni aparece en comparativos'
                        }
                      >
                        <span className="sr-only">
                          {w.active ? 'Desactivar' : 'Activar'} {w.name}
                        </span>
                        <input
                          type="checkbox"
                          className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
                          checked={w.active}
                          disabled={!canEditWholesalers || savingId === w.id}
                          onChange={(e) => handleToggle(w.id, e.target.checked)}
                        />
                      </label>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </CardBody>
      </Card>
    </div>
  )
}
