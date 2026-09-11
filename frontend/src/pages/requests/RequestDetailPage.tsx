import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Link, useParams } from 'react-router-dom'
import { AlertCircle, ArrowLeft, FileText, RefreshCw, Save, X } from 'lucide-react'
import {
  RequestLinesEditor,
  type RequestLinesEditorHandle,
} from '@/components/requests/RequestLinesEditor'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Label } from '@/components/ui/Input'
import { InlineBusy, LoadingState } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { useAuth } from '@/hooks/useAuth'
import { useData } from '@/hooks/useData'
import { usePermission } from '@/hooks/usePermission'
import { useToast } from '@/context/ToastProvider'
import { getCommercialSettings } from '@/lib/pricing-api'
import { persistQuote } from '@/lib/quotes-api'
import { buildLinesFromRequest } from '@/lib/quote-builder'
import { newLocalId } from '@/lib/new-id'
import {
  getComparatorPreferences,
  updateComparatorPreferences,
} from '@/lib/comparator-settings-api'
import { DEFAULT_PREFERRED_WAREHOUSES } from '@/lib/preferred-warehouses'
import {
  PreferredWarehousesByWholesaler,
  groupsFromApiOrFallback,
  type WarehousesByWholesalerGroup,
} from '@/components/settings/PreferredWarehousesByWholesaler'
import { formatCurrency, formatDateTime } from '@/lib/format'
import { getRouterBasename } from '@/lib/app-paths'
import { ApiRequestError } from '@/lib/api-response'
import {
  formatSolicitudValidationErrors,
  getSolicitud,
  listCotizacionesBySolicitud,
  mapSolicitudApiToQuoteRequest,
  pollSolicitud,
  reprocesarSolicitud,
  requestLineToPayload,
  isBlankRequestDraftLine,
  updateSolicitudLineas,
  type SolicitudCotizacionApi,
} from '@/lib/solicitudes-api'
import {
  type Quote,
  type QuoteRequest,
  type RequestLine,
} from '@/types'
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard'

export function RequestDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { user } = useAuth()
  const { saveQuote } = useData()
  const { canConsultInventory } = usePermission()
  const { toast } = useToast()
  const canUseComparator = canConsultInventory()
  const [request, setRequest] = useState<QuoteRequest | null>(null)
  const [cotizaciones, setCotizaciones] = useState<SolicitudCotizacionApi[]>([])
  const [editLines, setEditLines] = useState<RequestLine[]>([])
  const [linesDirty, setLinesDirty] = useState(false)
  const [savingLines, setSavingLines] = useState(false)
  const [reprocessing, setReprocessing] = useState(false)
  const [creatingQuote, setCreatingQuote] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [validationErrors, setValidationErrors] = useState<string[]>([])
  const lastSaveErrorRef = useRef<string | null>(null)
  const linesEditorRef = useRef<RequestLinesEditorHandle>(null)
  const [preferredWarehouse, setPreferredWarehouse] = useState(
    () => DEFAULT_PREFERRED_WAREHOUSES.join(','),
  )
  const [preferredWarehouses, setPreferredWarehouses] = useState<string[]>(() => [
    ...DEFAULT_PREFERRED_WAREHOUSES,
  ])
  const [autoApplyBest, setAutoApplyBest] = useState(false)
  const [comparatorPrefsReady, setComparatorPrefsReady] = useState(false)
  const [warehouseGroups, setWarehouseGroups] = useState<WarehousesByWholesalerGroup[]>(() =>
    groupsFromApiOrFallback(),
  )
  const [sendConfirmOpen, setSendConfirmOpen] = useState(false)
  const [sendingToQuotes, setSendingToQuotes] = useState(false)

  const loadRequest = async (signal?: AbortSignal) => {
    if (!id) return
    setLoading(true)
    setError(null)
    try {
      let api = await getSolicitud(id)
      if (api.status === 'procesando') {
        api = await pollSolicitud(id, { signal })
      }
      if (signal?.aborted) return

      const mapped = mapSolicitudApiToQuoteRequest(api)
      setRequest(mapped)
      setEditLines(mapped.lines ?? [])
      setLinesDirty(false)

      if (mapped.status === 'procesada' || mapped.status === 'precios_listos') {
        const linked = await listCotizacionesBySolicitud(id)
        if (!signal?.aborted) setCotizaciones(linked)
      } else {
        setCotizaciones([])
      }
    } catch (err: unknown) {
      if (err instanceof DOMException && err.name === 'AbortError') return
      setError(err instanceof Error ? err.message : 'No se pudo cargar la solicitud.')
      setRequest(null)
    } finally {
      if (!signal?.aborted) setLoading(false)
    }
  }

  useEffect(() => {
    if (!id) return
    const controller = new AbortController()
    loadRequest(controller.signal)
    return () => controller.abort()
  }, [id])

  useEffect(() => {
    if (!canUseComparator || !user?.email) {
      setComparatorPrefsReady(true)
      return
    }
    setComparatorPrefsReady(false)
    getComparatorPreferences()
      .then((prefs) => {
        const list =
          prefs.preferredWarehouses && prefs.preferredWarehouses.length > 0
            ? prefs.preferredWarehouses.map((w) => w.toUpperCase())
            : [(prefs.preferredWarehouse || 'CDMX').toUpperCase()]
        setPreferredWarehouses(list)
        setPreferredWarehouse(list.join(','))
        setAutoApplyBest(prefs.autoApplyBest)
        setWarehouseGroups(
          groupsFromApiOrFallback(
            prefs.availableWarehousesByWholesaler,
            prefs.availableWarehouses,
          ),
        )
      })
      .catch(() => {})
      .finally(() => {
        setComparatorPrefsReady(true)
      })
  }, [user?.email, canUseComparator])

  useEffect(() => {
    if (!canUseComparator || !user?.email || !comparatorPrefsReady) return
    const timer = setTimeout(() => {
      void updateComparatorPreferences({
        preferredWarehouse: preferredWarehouses[0] || 'CDMX',
        preferredWarehouses,
        autoApplyBest: false,
      }).catch(() => {})
    }, 600)
    return () => clearTimeout(timer)
  }, [user?.email, preferredWarehouses, autoApplyBest, comparatorPrefsReady, canUseComparator])

  const handleLinesChange = (lines: RequestLine[]) => {
    setEditLines(lines)
    setLinesDirty(true)
  }

  const handleCancelLines = () => {
    setEditLines(request?.lines ?? [])
    setLinesDirty(false)
  }

  const handleSaveLines = async (): Promise<boolean> => {
    if (!id) return false
    const linesToSave = editLines.filter((line) => !isBlankRequestDraftLine(line))
    if (linesToSave.length === 0) {
      const message = 'Agrega al menos una partida con producto o número de parte.'
      lastSaveErrorRef.current = message
      setError(message)
      return false
    }
    const incomplete = linesToSave.findIndex((line) => line.product.trim() === '')
    if (incomplete >= 0) {
      const message = `La partida ${incomplete + 1} necesita un producto.`
      lastSaveErrorRef.current = message
      setError(message)
      return false
    }
    setSavingLines(true)
    setError(null)
    lastSaveErrorRef.current = null
    try {
      const api = await updateSolicitudLineas(id, linesToSave.map(requestLineToPayload))
      const mapped = mapSolicitudApiToQuoteRequest(api)
      toast('Líneas guardadas')

      setRequest(mapped)
      const nextLines = mapped.lines?.length
        ? mapped.lines
        : api.lineas.map((line, index) => ({
            id: line.id || `pl${index + 1}`,
            quantity: line.quantity,
            product: line.product,
            partNumber: line.partNumber,
            brand: line.brand,
            description: line.description,
            unit: line.unit,
            referenceCost: line.referenceCost ?? undefined,
            selectedWholesalerId: line.selectedWholesalerId ?? undefined,
            warehouse: line.warehouse ?? undefined,
          }))
      setEditLines(nextLines)
      setLinesDirty(false)

      if (api.quote_id) {
        try {
          const linked = await listCotizacionesBySolicitud(id)
          setCotizaciones(linked)
        } catch {
          setCotizaciones((prev) =>
            prev.some((c) => c.id === api.quote_id)
              ? prev
              : [
                  {
                    id: api.quote_id!,
                    folio: api.quote_folio ?? api.quote_id!,
                    status: 'solicitud_cotizaciones',
                    total: 0,
                    createdAt: new Date().toISOString(),
                    clientName: mapped.clientName ?? '',
                  },
                  ...prev,
                ],
          )
        }
      }

      return true
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'No se pudieron guardar las líneas.'
      lastSaveErrorRef.current = message
      setError(message)
      return false
    } finally {
      setSavingLines(false)
    }
  }

  const { dialog: unsavedDialog } = useUnsavedChangesGuard(linesDirty, {
    onSave: async () => {
      const ok = await handleSaveLines()
      if (!ok) {
        throw new Error(
          lastSaveErrorRef.current ||
            'No se pudieron guardar las líneas. Completa el producto o quita las filas vacías.',
        )
      }
      return true
    },
  })

  const handleReprocesar = async () => {
    if (!id) return
    setReprocessing(true)
    setError(null)
    setValidationErrors([])
    try {
      const api = await reprocesarSolicitud(id, {
        do_ocr: request?.source === 'pdf' ? true : undefined,
      })
      const mapped = mapSolicitudApiToQuoteRequest(api)
      setRequest(mapped)
      setEditLines(mapped.lines ?? [])
      setLinesDirty(false)
      if (mapped.status === 'procesada' || mapped.status === 'precios_listos') {
        const linked = await listCotizacionesBySolicitud(id)
        setCotizaciones(linked)
      }
    } catch (err: unknown) {
      if (err instanceof ApiRequestError) {
        setError(err.message)
        setValidationErrors(formatSolicitudValidationErrors(err.errors))
      } else {
        setError(err instanceof Error ? err.message : 'No se pudo reprocesar la solicitud.')
      }
    } finally {
      setReprocessing(false)
    }
  }

  const hasLinkedQuotes = cotizaciones.length > 0
  const canEditLines =
    request?.status === 'procesada' && (request.lines?.length ?? 0) > 0
  const linkedQuoteId = cotizaciones[0]?.id ?? request?.quoteId
  const linkedQuoteFolio = cotizaciones[0]?.folio ?? request?.quoteFolio
  const canSendToQuotes =
    Boolean(linkedQuoteId) &&
    (request?.status === 'procesada' || request?.status === 'precios_listos')
  const canCreateQuote =
    !hasLinkedQuotes &&
    (request?.status === 'procesada' || request?.status === 'precios_listos') &&
    (request.lines?.length ?? 0) > 0

  const handleSendToQuotes = async () => {
    if (!id || !linkedQuoteId) return
    setSendingToQuotes(true)
    setError(null)
    try {
      if (linesDirty) {
        const ok = await handleSaveLines()
        if (!ok) {
          setSendConfirmOpen(false)
          return
        }
      }
      setSendConfirmOpen(false)
      toast(
        linkedQuoteFolio
          ? `Cotización ${linkedQuoteFolio} abierta en Cotizaciones`
          : 'Abierta en Cotizaciones',
        'success',
      )
      const base = getRouterBasename().replace(/\/$/, '')
      window.location.assign(`${base}/cotizaciones/${encodeURIComponent(linkedQuoteId)}`)
    } finally {
      setSendingToQuotes(false)
    }
  }

  const handleCreateQuote = async () => {
    if (!request || !request.clientId) {
      toast('La solicitud no tiene cliente asignado.', 'warning')
      return
    }
    setCreatingQuote(true)
    setError(null)
    try {
      const settings = await getCommercialSettings()
      const lines = buildLinesFromRequest(request, settings.defaultMarginPercent)
      if (lines.length === 0) {
        toast('La solicitud no tiene partidas para cotizar.', 'warning')
        return
      }
      const draft: Quote = {
        id: newLocalId('q'),
        folio: '',
        clientId: request.clientId,
        clientName: request.clientName ?? '',
        requestId: request.id,
        status: 'solicitud_cotizaciones',
        globalMarginPercent: settings.defaultMarginPercent,
        taxPercent: settings.taxPercent,
        notes: '',
        createdAt: new Date().toISOString(),
        lines,
      }
      const saved = await persistQuote(draft)
      saveQuote(saved)
      try {
        const [updatedRequest, linkedQuotes] = await Promise.all([
          getSolicitud(request.id),
          listCotizacionesBySolicitud(request.id),
        ])
        setRequest(mapSolicitudApiToQuoteRequest(updatedRequest))
        setCotizaciones(linkedQuotes)
      } catch {
        // La cotización ya quedó creada; una recarga recuperará los datos vinculados.
      }
      setLinesDirty(false)
      toast('Cotización creada en Compras.')
    } catch (err: unknown) {
      setError(
        err instanceof Error ? err.message : 'No se pudo crear la cotización desde la solicitud.',
      )
    } finally {
      setCreatingQuote(false)
    }
  }

  if (loading) {
    return <LoadingState label="Cargando solicitud…" variant="page" />
  }

  if (error && !request) {
    return (
      <div className="text-center text-slate-500">
        {error}{' '}
        <Link to="/solicitudes" className="text-indigo-600">
          Volver
        </Link>
      </div>
    )
  }

  if (!request) {
    return (
      <div className="text-center text-slate-500">
        Solicitud no encontrada.{' '}
        <Link to="/solicitudes" className="text-indigo-600">
          Volver
        </Link>
      </div>
    )
  }

  return (
    <div>
      {unsavedDialog}
      <PageHeader
        title={`Solicitud ${request.folio ?? request.id.slice(0, 8).toUpperCase()}`}
        description={formatDateTime(request.createdAt)}
        actions={
          <>
            <Link to="/solicitudes">
              <Button variant="secondary" size="sm">
                <ArrowLeft className="h-4 w-4" />
                Volver
              </Button>
            </Link>
            {canEditLines && linesDirty && (
              <>
                <Button
                  size="sm"
                  onClick={() => linesEditorRef.current?.openSaveModal()}
                  disabled={savingLines}
                >
                  {savingLines ? <InlineBusy size="sm" /> : <Save className="h-4 w-4" />}
                  Guardar cambios
                </Button>
                {linesDirty && (
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={handleCancelLines}
                    disabled={savingLines}
                  >
                    <X className="h-4 w-4" />
                    Cancelar
                  </Button>
                )}
              </>
            )}
            {request.status === 'error' && (
              <Button size="sm" onClick={handleReprocesar} disabled={reprocessing}>
                {reprocessing ? (
                  <InlineBusy size="sm" />
                ) : (
                  <RefreshCw className="h-4 w-4" />
                )}
                Reintentar lectura
              </Button>
            )}
            {canSendToQuotes && (
              <Button
                size="sm"
                onClick={() => setSendConfirmOpen(true)}
                disabled={savingLines || sendingToQuotes}
                title="Enviar al apartado de Cotizaciones"
              >
                {sendingToQuotes ? <InlineBusy size="sm" /> : <FileText className="h-4 w-4" />}
                Enviar a Cotizaciones
              </Button>
            )}
            {canCreateQuote && (
              <Button
                size="sm"
                onClick={() => void handleCreateQuote()}
                disabled={creatingQuote}
              >
                {creatingQuote ? (
                  <InlineBusy size="sm" />
                ) : (
                  <FileText className="h-4 w-4" />
                )}
                {creatingQuote ? 'Creando cotización…' : 'Crear cotización'}
              </Button>
            )}
          </>
        }
      />

      {error && (
        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          {error}
          {validationErrors.length > 0 && (
            <ul className="mt-2 list-inside list-disc space-y-1">
              {validationErrors.map((item) => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          )}
        </div>
      )}

      <div className="mb-6 flex flex-wrap items-center gap-2">
        <Badge variant="brand">{request.source.toUpperCase()}</Badge>
        {request.clientName && <Badge variant="muted">{request.clientName}</Badge>}
        {request.createdByName && <Badge variant="muted">{request.createdByName}</Badge>}
        {hasLinkedQuotes && <Badge variant="success">Con cotización</Badge>}
      </div>

      <p className="mb-6 text-sm text-slate-600">
        {request.lecturaAt ? (
          <>Solicitud completada el {formatDateTime(request.lecturaAt)}</>
        ) : request.status === 'procesando' ? (
          <>En cola desde {formatDateTime(request.createdAt)}</>
        ) : (
          <>Lectura pendiente</>
        )}
      </p>

      {request.status === 'error' && request.errorMessage && (
        <Card className="mb-6 border-red-200 bg-red-50/50">
          <CardBody className="text-sm text-red-800">{request.errorMessage}</CardBody>
        </Card>
      )}

      {hasLinkedQuotes && (
        <Card className="mb-6 border-slate-200 bg-slate-50/70">
          <CardBody className="text-sm text-slate-700">
            Puedes editar las partidas aquí. Cuando estén listas, pulsa{' '}
            <strong>Enviar a Cotizaciones</strong> para continuar precios y márgenes allá.
          </CardBody>
        </Card>
      )}

      {request.status === 'procesada' && canEditLines && !linesDirty && (
        <Card className="mb-6 border-amber-200 bg-amber-50/50">
          <CardBody className="text-sm text-amber-900">
            Revisa las líneas detectadas. Haz clic en cualquier celda para corregir cantidades, SKU o
            descripción y luego pulsa <strong>Guardar cambios</strong>.
          </CardBody>
        </Card>
      )}

      {request.status === 'procesada' && canEditLines && linesDirty && (
        <Card className="mb-6 border-indigo-200 bg-indigo-50/40">
          <CardBody className="text-sm text-indigo-900">
            Tienes cambios sin guardar. Guárdalos antes de enviar a Cotizaciones.
          </CardBody>
        </Card>
      )}

      {cotizaciones.length > 0 && (
        <Card className="mb-6">
          <CardHeader
            title="Cotizaciones vinculadas"
            subtitle={`${cotizaciones.length} cotización${cotizaciones.length === 1 ? '' : 'es'} generada${cotizaciones.length === 1 ? '' : 's'} desde esta solicitud`}
          />
          <CardBody className="p-0">
            <div className="table-scroll-mobile">
            <table className="w-full text-left text-sm">
              <thead className="border-b bg-slate-50 text-slate-500">
                <tr>
                  <th className="px-5 py-3">Folio</th>
                  <th className="px-5 py-3">Cliente</th>
                  <th className="px-5 py-3">Estado</th>
                  <th className="px-5 py-3">Total</th>
                  <th className="px-5 py-3">Fecha</th>
                </tr>
              </thead>
              <tbody>
                {cotizaciones.map((c) => (
                  <tr key={c.id} className="border-b border-slate-50 hover:bg-slate-50">
                    <td className="px-5 py-3">
                      <Link
                        to={`/cotizaciones/${c.id}`}
                        className="font-medium text-indigo-600 hover:underline"
                      >
                        {c.folio}
                      </Link>
                    </td>
                    <td className="px-5 py-3">{c.clientName || '—'}</td>
                    <td className="px-5 py-3 capitalize">{c.status}</td>
                    <td className="px-5 py-3">{formatCurrency(c.total)}</td>
                    <td className="px-5 py-3 text-slate-500">{formatDateTime(c.createdAt)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            </div>
          </CardBody>
        </Card>
      )}

      {(canEditLines ? editLines : request.lines)?.length ? (
        <Card className="mb-6">
          <CardHeader
            title="Líneas detectadas"
            subtitle={
              canEditLines
                ? 'Haz clic en la tabla para editar al instante'
                : 'Requerimiento del cliente'
            }
          />
          {canUseComparator ? (
            <CardBody className="space-y-4 border-b border-slate-100 p-5">
              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <Label>Almacenes preferidos (por mayorista)</Label>
                  <PreferredWarehousesByWholesaler
                    groups={warehouseGroups}
                    preferredWarehouses={preferredWarehouses}
                    role={user?.role}
                    density="compact"
                    onChange={(next) => {
                      setPreferredWarehouses(next)
                      setPreferredWarehouse(next.join(','))
                    }}
                  />
                </div>
                <div className="flex items-end">
                  <p className="text-xs text-slate-600">
                    Selección manual: elige la oferta en el inventario. No se aplica sola.
                  </p>
                </div>
              </div>
            </CardBody>
          ) : null}
          {canEditLines ? (
            <CardBody className="p-0 px-5 pb-5">
              <RequestLinesEditor
                ref={linesEditorRef}
                lines={editLines}
                requestId={id}
                preferredWarehouse={preferredWarehouse}
                autoApplyBest={false}
                comparatorEnabled={canUseComparator && comparatorPrefsReady}
                showComparator={canUseComparator}
                dirty={linesDirty}
                saving={savingLines}
                onChange={handleLinesChange}
                onSave={handleSaveLines}
                onCancel={handleCancelLines}
              />
            </CardBody>
          ) : (
            <CardBody className="p-0">
              <table className="w-full text-left text-sm">
                <thead className="border-b bg-slate-50 text-slate-500">
                  <tr>
                    <th className="px-5 py-3">Cant.</th>
                    <th className="px-5 py-3">Producto</th>
                    <th className="px-5 py-3">No. parte</th>
                    <th className="px-5 py-3">Marca</th>
                  </tr>
                </thead>
                <tbody>
                  {request.lines!.map((l) => (
                    <tr key={l.id} className="border-b border-slate-50">
                      <td className="px-5 py-3 font-medium">{l.quantity}</td>
                      <td className="px-5 py-3">{l.product}</td>
                      <td className="px-5 py-3 font-mono text-xs">{l.partNumber}</td>
                      <td className="px-5 py-3">{l.brand}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardBody>
          )}
        </Card>
      ) : request.status === 'procesando' ? (
        <Card className="mb-6">
          <CardBody>
            <LoadingState
              label="Procesando documento… Las líneas aparecerán aquí al completar."
              variant="section"
            />
          </CardBody>
        </Card>
      ) : (
        <Card className="mb-6">
          <CardBody className="py-12 text-center text-slate-500">
            No se detectaron líneas en esta solicitud.
          </CardBody>
        </Card>
      )}

      {sendConfirmOpen &&
        typeof document !== 'undefined' &&
        createPortal(
          <div
            className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/50 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="send-to-quotes-title"
            onClick={() => !sendingToQuotes && setSendConfirmOpen(false)}
          >
            <div
              className="relative w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="flex items-start gap-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-indigo-700">
                  <AlertCircle className="h-5 w-5" />
                </span>
                <div className="min-w-0 flex-1">
                  <h3 id="send-to-quotes-title" className="text-lg font-bold text-slate-900">
                    ¿Enviar a Cotizaciones?
                  </h3>
                  <p className="mt-2 text-sm text-slate-600">
                    Se abrirá{' '}
                    <strong>{linkedQuoteFolio ?? 'la cotización vinculada'}</strong> en el apartado
                    Cotizaciones para continuar con precios y márgenes.
                    {linesDirty ? ' Primero se guardarán tus cambios en las partidas.' : null}
                  </p>
                  <div className="mt-5 flex flex-wrap justify-end gap-2">
                    <Button
                      type="button"
                      variant="secondary"
                      disabled={sendingToQuotes}
                      onClick={() => setSendConfirmOpen(false)}
                    >
                      Seguir editando
                    </Button>
                    <Button
                      type="button"
                      disabled={sendingToQuotes}
                      onClick={() => void handleSendToQuotes()}
                    >
                      {sendingToQuotes ? <InlineBusy size="sm" /> : null}
                      Sí, enviar
                    </Button>
                  </div>
                </div>
              </div>
            </div>
          </div>,
          document.body,
        )}
    </div>
  )
}
