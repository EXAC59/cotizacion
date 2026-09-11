import { useRef, useState, useEffect } from 'react'
import { Link, Navigate, useSearchParams } from 'react-router-dom'
import { AlertCircle, ArrowLeft, FileUp, X } from 'lucide-react'
import { ClientSearchSelect } from '@/components/clients/ClientSearchSelect'
import { RequestTextLinesEditor } from '@/components/requests/RequestTextLinesEditor'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { InlineBusy } from '@/components/ui/LoadingState'
import { usePermission } from '@/hooks/usePermission'
import { useToast } from '@/context/ToastProvider'
import { ApiRequestError, ClientRequiredApiError } from '@/lib/api-response'
import { getRouterBasename } from '@/lib/app-paths'
import {
  createEmptyFreeTextLines,
  linesToMarkdownTable,
  parseRequestLinesFromText,
  usableFreeTextLines,
} from '@/lib/parse-request-lines'
import {
  REQUEST_TEXT_EXAMPLES,
  type RequestTextExampleKey,
} from '@/lib/request-text-examples'
import {
  createSolicitudFromText,
  formatSolicitudValidationErrors,
  pollSolicitud,
  uploadSolicitudLectura,
} from '@/lib/solicitudes-api'
import type { QuoteRequest, RequestLine } from '@/types'
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard'

function detectSourceFromFileName(fileName: string): QuoteRequest['source'] {
  const ext = fileName.split('.').pop()?.toLowerCase() ?? ''
  if (['xlsx', 'xls', 'csv'].includes(ext)) return 'excel'
  if (['doc', 'docx'].includes(ext)) return 'word'
  return 'pdf'
}

function detectedSourceLabel(file: File): string {
  const ext = file.name.split('.').pop()?.toLowerCase() ?? ''
  const source = detectSourceFromFileName(file.name)
  if (source === 'excel') {
    return ext === 'xls' ? 'Excel (.xls → se convierte a .xlsx)' : `Excel (.${ext || 'xlsx'})`
  }
  if (source === 'word') return `Word (.${ext || 'docx'})`
  return `PDF (.${ext || 'pdf'})`
}

export function RequestNewPage() {
  const [searchParams] = useSearchParams()
  const clientIdParam = searchParams.get('clientId')
  const { can } = usePermission()
  const { toast } = useToast()
  const canCreate = can('solicitudes', 'create')
  const [clientId, setClientId] = useState(() => clientIdParam ?? '')
  const [detectedSource, setDetectedSource] = useState<QuoteRequest['source'] | null>(null)
  const [textLines, setTextLines] = useState<RequestLine[]>(() => createEmptyFreeTextLines(1))
  const [file, setFile] = useState<File | null>(null)
  const [lecturaLoading, setLecturaLoading] = useState(false)
  const [lecturaError, setLecturaError] = useState<string | null>(null)
  const [validationErrors, setValidationErrors] = useState<string[]>([])
  const [doOcr, setDoOcr] = useState(true)
  const [clientRequiredModal, setClientRequiredModal] = useState(false)
  const [allowLeave, setAllowLeave] = useState(false)
  const abortRef = useRef<AbortController | null>(null)
  const doOcrRef = useRef(doOcr)

  useEffect(() => {
    doOcrRef.current = doOcr
  }, [doOcr])

  const isPdfFile = file?.name.toLowerCase().endsWith('.pdf') ?? false
  const ocrLocked = lecturaLoading
  const usableTextLines = usableFreeTextLines(textLines)
  const hasTextInput = usableTextLines.length > 0
  const hasInput = !!file || hasTextInput

  const isDirty = !lecturaLoading && (!!clientId || hasInput)
  const saveBeforeLeaveRef = useRef<() => Promise<boolean>>(async () => false)
  const { dialog: unsavedDialog } = useUnsavedChangesGuard(isDirty && !allowLeave, {
    onSave: () => saveBeforeLeaveRef.current(),
  })

  const requireClient = () => {
    setClientRequiredModal(true)
    return false
  }

  const goToSolicitudHard = (requestId: string, options?: { quoteFolio?: string | null }) => {
    if (options?.quoteFolio) {
      toast(`Solicitud guardada. Cotización ${options.quoteFolio} en Solicitud de cotizaciones.`, 'success')
    } else {
      toast('Solicitud guardada', 'success')
    }
    // Redirect duro: el blocker de “cambios sin guardar” puede cancelar navigate().
    const base = getRouterBasename().replace(/\/$/, '')
    window.location.assign(`${base}/solicitudes/${encodeURIComponent(requestId)}`)
  }

  const navigateAfterAnalyze = async (
    requestId: string,
    options: {
      modo?: string
      status?: string
      quoteId?: string | null
      quoteFolio?: string | null
      signal: AbortSignal
    },
  ) => {
    setAllowLeave(true)
    const { modo, status, quoteFolio, signal } = options

    // Tras guardar: siempre el detalle de la solicitud (la cotización queda en solicitud_cotizaciones).
    if (modo === 'n8n' && status === 'procesando') {
      try {
        const solicitud = await pollSolicitud(requestId, { signal })
        if (signal.aborted) return
        if (solicitud.status === 'error') {
          toast('La solicitud tuvo un error al procesarse', 'warning')
        }
        goToSolicitudHard(requestId, { quoteFolio: solicitud.quote_folio ?? quoteFolio })
        return
      } catch {
        if (signal.aborted) return
        goToSolicitudHard(requestId, { quoteFolio })
        return
      }
    }

    if (signal.aborted) return
    goToSolicitudHard(requestId, { quoteFolio })
  }

  const applyExample = (key: RequestTextExampleKey) => {
    const parsed = parseRequestLinesFromText(REQUEST_TEXT_EXAMPLES[key]).map((line) => ({
      ...line,
      id: `tl-${crypto.randomUUID()}`,
    }))
    setTextLines(parsed.length > 0 ? parsed : createEmptyFreeTextLines(1))
    setLecturaError(null)
    setValidationErrors([])
  }

  const analyzeFile = () => {
    if (!clientId) {
      requireClient()
      return
    }
    if (!file) return

    const detected = detectSourceFromFileName(file.name)

    abortRef.current?.abort()
    const controller = new AbortController()
    abortRef.current = controller

    setAllowLeave(true)
    setLecturaLoading(true)
    setLecturaError(null)
    setValidationErrors([])

    uploadSolicitudLectura(file, {
      via: 'n8n',
      client_id: clientId,
      do_ocr: detected === 'pdf' ? doOcrRef.current : undefined,
      signal: controller.signal,
      resilient: true,
    })
      .then(async (result) => {
        await navigateAfterAnalyze(result.request_id, {
          modo: result.modo,
          status: 'status' in result ? result.status : undefined,
          quoteId: 'quote_id' in result ? result.quote_id : undefined,
          quoteFolio: 'quote_folio' in result ? result.quote_folio : undefined,
          signal: controller.signal,
        })
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted) return
        if (err instanceof ClientRequiredApiError) {
          setClientRequiredModal(true)
          return
        }
        if (err instanceof ApiRequestError) {
          setLecturaError(err.message)
          setValidationErrors(formatSolicitudValidationErrors(err.errors))
        } else {
          const message =
            err instanceof Error ? err.message : 'No se pudo leer el archivo. Inténtalo de nuevo.'
          setLecturaError(message)
          setValidationErrors([])
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) {
          setLecturaLoading(false)
        }
      })
  }

  const analyzeText = () => {
    if (!clientId) {
      requireClient()
      return
    }
    if (usableTextLines.length === 0) return

    abortRef.current?.abort()
    const controller = new AbortController()
    abortRef.current = controller

    setAllowLeave(true)
    setLecturaLoading(true)
    setLecturaError(null)
    setValidationErrors([])

    const rawText = linesToMarkdownTable(usableTextLines)

    createSolicitudFromText({
      raw_text: rawText,
      client_id: clientId,
      lineas: usableTextLines.map((line) => ({
        quantity: line.quantity,
        product: line.product.trim(),
        partNumber: line.partNumber.trim(),
        brand: (line.brand || 'Genérico').trim(),
        description: (line.description || line.product).trim(),
        unit: line.unit || 'pza',
      })),
    })
      .then(async (result) => {
        if (controller.signal.aborted) return
        await navigateAfterAnalyze(result.request_id, {
          quoteId: result.quote_id,
          quoteFolio: result.quote_folio,
          signal: controller.signal,
        })
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted) return
        if (err instanceof ClientRequiredApiError) {
          setClientRequiredModal(true)
          return
        }
        if (err instanceof ApiRequestError) {
          setLecturaError(err.message)
          setValidationErrors(formatSolicitudValidationErrors(err.errors))
        } else {
          setLecturaError(
            err instanceof Error ? err.message : 'No se pudo guardar la solicitud de texto.',
          )
          setValidationErrors([])
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) {
          setLecturaLoading(false)
        }
      })
  }

  const handleSubmit = () => {
    if (!clientId) {
      requireClient()
      return
    }
    if (file) {
      analyzeFile()
      return
    }
    if (hasTextInput) {
      analyzeText()
      return
    }
  }

  saveBeforeLeaveRef.current = async () => {
    if (!clientId || (!file && !hasTextInput)) {
      return false
    }
    setAllowLeave(true)
    handleSubmit()
    // El flujo de análisis navega al terminar; no forzar salida inmediata.
    return false
  }

  const handleFileChange = (next: File | null) => {
    if (!clientId) {
      requireClient()
      return
    }
    setLecturaError(null)
    setValidationErrors([])
    setFile(next)
    if (!next) {
      setDetectedSource(null)
      return
    }
    const detected = detectSourceFromFileName(next.name)
    setDetectedSource(detected)
    if (detected === 'pdf') {
      setDoOcr(true)
    }
  }

  if (!canCreate) {
    return <Navigate to="/solicitudes" replace />
  }

  return (
    <div>
      {unsavedDialog}
      <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 className="text-3xl font-bold uppercase tracking-tight text-slate-900">
          Nueva solicitud
        </h1>
        <Link to="/solicitudes">
          <Button variant="secondary" size="sm">
            <ArrowLeft className="h-4 w-4" />
            Volver
          </Button>
        </Link>
      </div>

      <section className="mb-8">
        <h2 className="text-xl font-bold text-slate-900">Cliente</h2>
        <p className="mt-1 text-sm text-slate-500">
          Obligatorio: la solicitud debe quedar vinculada a un cliente.
        </p>
        <div className="mt-3 max-w-md">
          <ClientSearchSelect
            label="Seleccionar cliente"
            required
            value={clientId}
            onChange={(id) => {
              setClientId(id)
              if (id) setLecturaError(null)
            }}
            placeholder="Escribe para buscar cliente"
          />
          <p className="mt-2 text-sm text-slate-500">
            <Link
              to="/clientes/nuevo?returnTo=/solicitudes/nueva"
              className="font-medium text-indigo-600 underline"
            >
              Crear cliente
            </Link>{' '}
            si aún no está registrado.
          </p>
        </div>
      </section>

      <ol className="mb-8 flex flex-wrap gap-2 text-sm">
        {[
          { n: 1, label: 'Cliente', done: !!clientId, active: !clientId },
          { n: 2, label: 'Contenido', done: hasInput, active: !!clientId && !hasInput },
          {
            n: 3,
            label: 'Analizar',
            done: false,
            active: !!clientId && hasInput && !lecturaLoading,
          },
        ].map((step) => (
          <li
            key={step.n}
            className={`rounded-full border px-3 py-1 ${
              step.done
                ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                : step.active
                  ? 'border-indigo-300 bg-indigo-50 font-medium text-indigo-800'
                  : 'border-slate-200 bg-slate-50 text-slate-500'
            }`}
          >
            {step.n}. {step.label}
          </li>
        ))}
      </ol>

      {!clientId && (
        <div
          role="alert"
          className="mb-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
          Debes agregar un cliente antes de cargar un archivo o capturar texto libre.
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader
            title="Texto libre"
            subtitle="Captura Cantidad, Producto, No. parte y Marca en la tabla"
          />
          <CardBody className="relative space-y-4">
            {!clientId && (
              <button
                type="button"
                className="absolute inset-0 z-10 cursor-pointer rounded-xl bg-transparent"
                aria-label="Seleccionar cliente para habilitar texto libre"
                onClick={() => requireClient()}
              />
            )}
            <div className="flex flex-wrap gap-2">
              {(Object.keys(REQUEST_TEXT_EXAMPLES) as RequestTextExampleKey[]).map((key) => (
                <Button
                  key={key}
                  type="button"
                  variant="secondary"
                  size="sm"
                  disabled={!clientId || !!file || lecturaLoading}
                  onClick={() => {
                    if (!clientId) {
                      requireClient()
                      return
                    }
                    applyExample(key)
                  }}
                >
                  Usar ejemplo: {key}
                </Button>
              ))}
            </div>
            <RequestTextLinesEditor
              lines={textLines}
              disabled={!clientId || !!file || lecturaLoading}
              onChange={setTextLines}
            />
            {!clientId && (
              <p className="text-sm text-amber-800">
                Selecciona un cliente arriba para habilitar la captura de texto libre.
              </p>
            )}
            {file && clientId && (
              <p className="text-xs text-slate-500">
                La tabla de texto libre se deshabilita mientras hay un archivo cargado.
              </p>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title="Archivo"
            subtitle="PDF, Excel (.xlsx / .xls) o Word"
          />
          <CardBody className="space-y-4">
            {file && detectedSource && (
              <p className="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-sm font-medium text-indigo-800">
                Tipo detectado: {detectedSourceLabel(file)}
              </p>
            )}
            {isPdfFile && (
              <div className="space-y-1">
                <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                    checked={doOcr}
                    onChange={(e) => setDoOcr(e.target.checked)}
                    disabled={!clientId || ocrLocked}
                  />
                  OCR en PDF (recomendado para documentos escaneados)
                </label>
                {ocrLocked && (
                  <p className="text-xs text-slate-500">
                    La opción OCR no se puede cambiar mientras se procesa el archivo.
                  </p>
                )}
              </div>
            )}
            <label
              className={`flex flex-col items-center justify-center rounded-xl border-2 border-dashed py-12 transition-colors ${
                clientId
                  ? 'cursor-pointer border-slate-300 bg-slate-50 hover:border-indigo-400 hover:bg-indigo-50/30'
                  : 'cursor-not-allowed border-slate-200 bg-slate-100 opacity-70'
              }`}
              onClick={(e) => {
                if (!clientId) {
                  e.preventDefault()
                  requireClient()
                }
              }}
            >
              <FileUp className="h-10 w-10 text-slate-400" />
              <span className="mt-2 text-sm font-medium text-slate-700">
                {file
                  ? file.name
                  : clientId
                    ? 'Arrastra o haz clic para subir'
                    : 'Selecciona un cliente para habilitar la subida'}
              </span>
              <input
                type="file"
                className="hidden"
                accept=".pdf,.xlsx,.xls,.doc,.docx"
                disabled={!clientId || lecturaLoading}
                onChange={(e) => {
                  const next = e.target.files?.[0] ?? null
                  if (!clientId) {
                    e.target.value = ''
                    requireClient()
                    return
                  }
                  handleFileChange(next)
                }}
              />
            </label>
          </CardBody>
        </Card>
      </div>

      {(lecturaError || validationErrors.length > 0) && (
        <div className="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          {lecturaError && <p>{lecturaError}</p>}
          {validationErrors.length > 0 && (
            <ul className="mt-2 list-inside list-disc space-y-1">
              {validationErrors.map((item) => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          )}
        </div>
      )}

      <div className="mt-6 space-y-3">
        <Button
          onClick={handleSubmit}
          disabled={lecturaLoading || !hasInput || !clientId}
        >
          {lecturaLoading ? (
            <InlineBusy size="sm" label="Procesando…" />
          ) : file ? (
            <>Analizar documento</>
          ) : (
            <>Guardar solicitud</>
          )}
        </Button>
        <p className={`mt-2 text-sm ${!clientId && hasInput ? 'text-amber-800' : 'text-slate-500'}`}>
          {!clientId && hasInput
            ? 'Selecciona un cliente arriba para habilitar el análisis.'
            : file
              ? 'Al analizar se abrirá el detalle de la solicitud.'
              : 'Las partidas de la tabla se guardan directo.'}
        </p>
      </div>

      {clientRequiredModal && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
          role="dialog"
          aria-modal="true"
          aria-labelledby="client-required-title"
          onClick={() => setClientRequiredModal(false)}
        >
          <div
            className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-start justify-between gap-4">
              <div className="flex gap-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                  <AlertCircle className="h-5 w-5" />
                </span>
                <div>
                  <h3
                    id="client-required-title"
                    className="text-lg font-bold text-slate-900"
                  >
                    Cliente obligatorio
                  </h3>
                  <p className="mt-2 text-sm text-slate-600">
                    Debes asignar un cliente para poder guardar la solicitud. Selecciónalo en el
                    campo <strong>Seleccionar cliente</strong> antes de continuar.
                  </p>
                  <p className="mt-2 text-sm text-amber-800">
                    <Link
                      to="/clientes/nuevo"
                      className="font-medium underline"
                      onClick={() => setClientRequiredModal(false)}
                    >
                      Crear cliente
                    </Link>
                  </p>
                </div>
              </div>
              <button
                type="button"
                className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                aria-label="Cerrar"
                onClick={() => setClientRequiredModal(false)}
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            <div className="mt-6 flex justify-end">
              <Button onClick={() => setClientRequiredModal(false)}>Entendido</Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
