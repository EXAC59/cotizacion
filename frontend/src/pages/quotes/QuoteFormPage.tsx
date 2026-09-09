import { useEffect, useRef, useState } from 'react'
import { Link, Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import {
  ArrowLeft,
  Download,
  Lock,
  Mail,
  MessageCircle,
  Save,
  X,
} from 'lucide-react'
import { AssignToSalesPanel } from '@/components/sales/AssignToSalesPanel'
import { ClientSearchSelect } from '@/components/clients/ClientSearchSelect'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Input, Label, Textarea } from '@/components/ui/Input'
import {
  beginModalBusy,
  InlineBusy,
  LoadingState,
  ModalBusyPanel,
  waitMinBusyMs,
  waitModalBusyPaint,
} from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { QuoteStatusBadge } from '@/components/ui/QuoteStatusBadge'
import { QuoteLinesEditor } from '@/components/quotes/QuoteLinesEditor'
import { QuoteTotalsPanel } from '@/components/quotes/QuoteTotals'
import { useToast } from '@/context/ToastProvider'
import { useData } from '@/hooks/useData'
import { useAuth } from '@/hooks/useAuth'
import { usePermission } from '@/hooks/usePermission'
import { useQuoteLock } from '@/hooks/useQuoteLock'
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
import { assignQuoteToCompras } from '@/lib/assign-to-sales-api'
import { getCommercialSettings } from '@/lib/pricing-api'
import { formatDateTime } from '@/lib/format'
import {
  fetchNextQuoteFolio,
  addQuoteInternalNote,
  getQuoteById,
  isPersistedQuoteId,
  openQuotePdf,
  persistQuote,
  sendQuoteByEmail,
} from '@/lib/quotes-api'
import {
  getSolicitud,
  mapSolicitudApiToQuoteRequest,
} from '@/lib/solicitudes-api'
import type {
  Client,
  Quote,
  QuoteLine,
  QuoteInternalNote,
  QuoteRequest,
  QuoteStatus,
  QuoteStatusHistoryEntry,
} from '@/types'
import { QUOTE_STATUS_LABELS } from '@/types'
import {
  DEFAULT_MARGIN,
  DEFAULT_TAX,
  syncGlobalMarginLines,
} from '@/lib/calculations'
import { buildLinesFromRequest } from '@/lib/quote-builder'
import {
  isPreSentStatus,
  QUOTE_WORKFLOW_ORDER,
  quoteWorkflowIndex,
} from '@/lib/quote-status'
import { newLocalId } from '@/lib/new-id'
import {
  useDirtyTracker,
  useUnsavedChangesGuard,
} from '@/hooks/useUnsavedChangesGuard'
import { useNotificationFocus } from '@/lib/notification-focus'

function QuoteFormEditor({
  quoteId,
  requestId,
  clientIdParam,
}: {
  quoteId: string | undefined
  requestId: string | null
  clientIdParam: string | null
}) {
  const isNew = !quoteId || quoteId === 'nueva'
  const navigate = useNavigate()
  const { getQuote, saveQuote } = useData()
  const { toast } = useToast()
  const { user } = useAuth()
  const { can, canCreateQuotes, canEditMargins, canApproveQuotes, canSendQuotes, canConsultInventory } =
    usePermission()
  const canCreate = canCreateQuotes()
  const canAssignTeam = user?.role === 'ventas' || user?.role === 'administrador'
  const [assignRecipientId, setAssignRecipientId] = useState<number | null>(null)
  const [assignTarget, setAssignTarget] = useState<'compras' | null>(null)
  const canEdit = can('cotizaciones', 'edit')
  const canApprove = canApproveQuotes()
  const canSendEmail = canSendQuotes()
  const canEditMarginFields = canEditMargins()
  const canUseComparator = canConsultInventory()
  const localExisting = !isNew && quoteId ? getQuote(quoteId) : undefined
  const [ownedByViewer, setOwnedByViewer] = useState<boolean | null>(
    () => localExisting?.ownedByViewer ?? null,
  )
  const [viewerCreatedByName, setViewerCreatedByName] = useState(
    () => localExisting?.createdByName ?? '',
  )
  const viewingOthers = ownedByViewer === false
  const showAssignPanel =
    canAssignTeam && !viewingOthers && (canCreate || canEdit)
  const needsEditLock =
    !isNew &&
    isPersistedQuoteId(quoteId ?? '') &&
    (canEdit || canCreate) &&
    ownedByViewer === true
  const [selectedClient, setSelectedClient] = useState<Client | null>(null)
  const [loadedRequest, setLoadedRequest] = useState<QuoteRequest | null>(null)
  const [loadingQuote, setLoadingQuote] = useState(!isNew)
  const [loadError, setLoadError] = useState<string | null>(null)
  const commercialSettingsLoaded = useRef(false)
  const skipMarginSync = useRef(true)
  const globalMarginRef = useRef(DEFAULT_MARGIN)
  const requestLinesApplied = useRef(false)
  const tempLocalIdRef = useRef(newLocalId('q'))

  const [folio, setFolio] = useState(() => localExisting?.folio ?? '')
  const [folioLoading, setFolioLoading] = useState(isNew && !localExisting?.folio)
  const [clientId, setClientId] = useState(() => localExisting?.clientId ?? clientIdParam ?? '')
  const [status, setStatus] = useState<QuoteStatus>(() => {
    if (localExisting?.status) return localExisting.status
    // Desde solicitud (incl. “ir directo a cotización”) → En elaboración.
    return 'en_elaboracion'
  })
  const [savedStatus, setSavedStatus] = useState<QuoteStatus>(
    () => localExisting?.status ?? 'en_elaboracion',
  )
  const [globalMargin, setGlobalMargin] = useState(
    () => localExisting?.globalMarginPercent ?? DEFAULT_MARGIN,
  )
  const [taxPercent, setTaxPercent] = useState(() => localExisting?.taxPercent ?? DEFAULT_TAX)
  const [customerObservations, setCustomerObservations] = useState(
    () => localExisting?.customerObservations ?? '',
  )
  const [internalNotes, setInternalNotes] = useState<QuoteInternalNote[]>(
    () => localExisting?.internalNotes ?? [],
  )
  const [internalNoteDraft, setInternalNoteDraft] = useState('')
  const [addingInternalNote, setAddingInternalNote] = useState(false)
  const [internalNoteError, setInternalNoteError] = useState<string | null>(null)
  const [lines, setLines] = useState<QuoteLine[]>(() => localExisting?.lines ?? [])
  const [serverQuoteId, setServerQuoteId] = useState<string | undefined>(localExisting?.id)
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
  const [saving, setSaving] = useState(false)
  const [actionLoading, setActionLoading] = useState<'pdf' | 'email' | null>(null)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [saveModalOpen, setSaveModalOpen] = useState(false)
  const [emailModalOpen, setEmailModalOpen] = useState(false)
  const [emailTo, setEmailTo] = useState('')
  const [emailSubject, setEmailSubject] = useState('')
  const [emailMessage, setEmailMessage] = useState('')
  const [sendingEmail, setSendingEmail] = useState(false)
  const [emailFeedback, setEmailFeedback] = useState<string | null>(null)
  const [sentAt, setSentAt] = useState<string | undefined>(localExisting?.sentAt)
  const [invoiceNumber, setInvoiceNumber] = useState(() => localExisting?.invoiceNumber ?? '')
  const [statusHistory, setStatusHistory] = useState<QuoteStatusHistoryEntry[]>(
    () => localExisting?.statusHistory ?? [],
  )
  const [allowLeave, setAllowLeave] = useState(false)

  const handleLockAcquired = (result: {
    status?: QuoteStatus
    statusChanged?: boolean
    statusHistory?: QuoteStatusHistoryEntry[]
  }) => {
    if (result.status) {
      setStatus(result.status)
      if (result.statusChanged) {
        setSavedStatus(result.status)
      }
    }
    if (result.statusHistory) {
      setStatusHistory(result.statusHistory)
    }
  }
  const { lockState, lockedBy, retryAcquire } = useQuoteLock(
    quoteId,
    needsEditLock,
    handleLockAcquired,
  )

  // Nueva cotización desde solicitud: asegurar En elaboración (no modificación).
  useEffect(() => {
    if (!isNew || !requestId) return
    if (status === 'modificacion' || status === 'solicitud_cotizaciones') {
      setStatus('en_elaboracion')
    }
  }, [isNew, requestId, status])

  useEffect(() => {
    if (!isNew || localExisting?.folio) return

    let cancelled = false
    setFolioLoading(true)
    fetchNextQuoteFolio()
      .then((nextFolio) => {
        if (!cancelled) setFolio(nextFolio)
      })
      .catch(() => {
        if (!cancelled) setFolio('—')
      })
      .finally(() => {
        if (!cancelled) setFolioLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [isNew, localExisting?.folio])

  useEffect(() => {
    if (!isNew || !clientIdParam || clientId) return
    setClientId(clientIdParam)
  }, [isNew, clientIdParam, clientId])

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

  useEffect(() => {
    if (!isNew || commercialSettingsLoaded.current) return

    getCommercialSettings()
      .then((s) => {
        if (commercialSettingsLoaded.current) return
        commercialSettingsLoaded.current = true
        setGlobalMargin(s.defaultMarginPercent)
        setTaxPercent(s.taxPercent)
      })
      .catch(() => {})
  }, [isNew])

  useEffect(() => {
    globalMarginRef.current = globalMargin
  }, [globalMargin])

  useEffect(() => {
    if (!requestId || requestLinesApplied.current) return

    getSolicitud(requestId)
      .then((api) => {
        const mapped = mapSolicitudApiToQuoteRequest(api)
        setLoadedRequest(mapped)
        setClientId((prev) => prev || mapped.clientId || '')
        setLines((prev) => {
          if (prev.length > 0 || !(mapped.lines?.length ?? 0)) return prev
          requestLinesApplied.current = true
          return buildLinesFromRequest(mapped, globalMarginRef.current)
        })
      })
      .catch(() => setLoadError('No se pudo cargar la solicitud vinculada.'))
  }, [requestId])

  useEffect(() => {
    if (isNew || !quoteId) return

    setLoadingQuote(true)
    getQuoteById(quoteId)
      .then((quote) => {
        setServerQuoteId(quote.id)
        setFolio(quote.folio)
        setClientId(quote.clientId)
        setStatus(quote.status)
        setSavedStatus(quote.status)
        setGlobalMargin(quote.globalMarginPercent)
        setTaxPercent(quote.taxPercent)
        setCustomerObservations(quote.customerObservations ?? '')
        setInternalNotes(quote.internalNotes ?? [])
        setLines(quote.lines)
        setSentAt(quote.sentAt)
        setInvoiceNumber(quote.invoiceNumber ?? '')
        setStatusHistory(quote.statusHistory ?? [])
        setOwnedByViewer(quote.ownedByViewer ?? true)
        setViewerCreatedByName(quote.createdByName ?? '')
        saveQuote(quote)
      })
      .catch(() => {
        if (localExisting) {
          setServerQuoteId(localExisting.id)
          setLines(localExisting.lines)
        } else {
          setLoadError('No se pudo cargar la cotización.')
        }
      })
      .finally(() => setLoadingQuote(false))
  }, [quoteId, isNew])

  useEffect(() => {
    if (skipMarginSync.current) {
      skipMarginSync.current = false
      return
    }
    setLines((prev) => syncGlobalMarginLines(prev, globalMargin))
  }, [globalMargin])

  const client = selectedClient
  const request = loadedRequest ?? undefined

  const quoteFieldsReadOnly = viewingOthers || (!canEdit && !canCreate)
  const canSaveQuote =
    !viewingOthers && ((isNew && canCreate) || (!isNew && (canEdit || canApprove)))

  const newQuoteDirty =
    isNew &&
    (lines.length > 0 ||
      Boolean(clientId) ||
      customerObservations.trim() !== '' ||
      invoiceNumber.trim() !== '' ||
      (status !== 'en_elaboracion' && status !== 'solicitud_cotizaciones'))

  const { dirty: editDirty, markClean } = useDirtyTracker(
    !isNew && !loadingQuote && !quoteFieldsReadOnly && !loadError,
    [
      clientId,
      status,
      globalMargin,
      taxPercent,
      customerObservations,
      lines,
      invoiceNumber,
      preferredWarehouse,
      autoApplyBest,
    ],
  )

  const saveBeforeLeaveRef = useRef<() => Promise<boolean>>(async () => false)
  const { dialog: unsavedDialog } = useUnsavedChangesGuard(
    Boolean(canCreate || canEdit) &&
      !allowLeave &&
      !quoteFieldsReadOnly &&
      (isNew ? newQuoteDirty : editDirty),
    {
      onSave: () => saveBeforeLeaveRef.current(),
    },
  )

  useNotificationFocus(!loadingQuote)

  if (isNew && !canCreate) {
    return <Navigate to="/cotizaciones" replace />
  }

  const rawWorkflowStep = quoteWorkflowIndex(status)
  // aceptada/facturada quedan fuera del stepper (1–5): se muestran ya completas.
  const workflowStep = rawWorkflowStep === -1 ? QUOTE_WORKFLOW_ORDER.length : rawWorkflowStep
  const canUseQuoteActions = lines.length > 0 && Boolean(clientId)
  const canEmail = canSendEmail && canUseQuoteActions && !viewingOthers
  const isBusy = saving || actionLoading !== null

  const buildDraftQuote = (): Quote | null => {
    if (!clientId) {
      alert('Selecciona un cliente')
      return null
    }
    if (lines.length === 0) {
      alert('Agrega al menos una partida')
      return null
    }
    if (status === 'facturada' && invoiceNumber.trim() === '') {
      alert('Indica el número de factura o ticket para marcar como facturada.')
      return null
    }

    return {
      id: serverQuoteId ?? localExisting?.id ?? tempLocalIdRef.current,
      folio,
      clientId,
      clientName: client?.company ?? '',
      requestId: requestId ?? localExisting?.requestId,
      status,
      globalMarginPercent: globalMargin,
      taxPercent,
      notes: '',
      customerObservations,
      createdAt: localExisting?.createdAt ?? new Date().toISOString(),
      sentAt,
      invoiceNumber: invoiceNumber.trim() || undefined,
      lines,
    }
  }

  const saveDraft = async (draft: Quote, navigateAfter = false): Promise<string | null> => {
    beginModalBusy(setSaving)
    await waitModalBusyPaint()
    const busyStarted = performance.now()
    setSaveError(null)
    try {
      const saved = await persistQuote(draft)
      saveQuote(saved)
      markClean()
      setAllowLeave(true)
      setServerQuoteId(saved.id)
      setFolio(saved.folio)
      setStatus(saved.status)
      setSavedStatus(saved.status)
      setSentAt(saved.sentAt)
      setInvoiceNumber(saved.invoiceNumber ?? '')
      setStatusHistory(saved.statusHistory ?? [])
      setCustomerObservations(saved.customerObservations ?? '')
      setInternalNotes(saved.internalNotes ?? internalNotes)
      await waitMinBusyMs(busyStarted)
      if (navigateAfter) {
        navigate(`/cotizaciones/${saved.id}`)
      } else if (isNew) {
        navigate(`/cotizaciones/${saved.id}`, { replace: true })
      } else {
        setAllowLeave(false)
      }
      return saved.id
    } catch (err: unknown) {
      setSaveError(
        err instanceof Error ? err.message : 'No se pudo guardar la cotización en el servidor.',
      )
      await waitMinBusyMs(busyStarted)
      return null
    } finally {
      setSaving(false)
    }
  }

  const ensureQuoteSaved = async (navigateAfter = false): Promise<string | null> => {
    const draft = buildDraftQuote()
    if (!draft) return null

    if (serverQuoteId && isPersistedQuoteId(serverQuoteId)) {
      return saveDraft({ ...draft, id: serverQuoteId }, navigateAfter)
    }

    return saveDraft(draft, navigateAfter)
  }

  const appendInternalNote = async () => {
    const body = internalNoteDraft.trim()
    if (body === '') return
    const id = serverQuoteId ?? localExisting?.id
    if (!id || !isPersistedQuoteId(id)) {
      setInternalNoteError('Guarda primero la cotización para iniciar la bitácora.')
      return
    }
    setAddingInternalNote(true)
    setInternalNoteError(null)
    try {
      const note = await addQuoteInternalNote(id, body)
      setInternalNotes((prev) => [note, ...prev])
      setInternalNoteDraft('')
    } catch (err) {
      setInternalNoteError(err instanceof Error ? err.message : 'No se pudo agregar la nota interna.')
    } finally {
      setAddingInternalNote(false)
    }
  }

  saveBeforeLeaveRef.current = async () => {
    const id = await ensureQuoteSaved(false)
    return id != null
  }

  const assignAfterSaveIfNeeded = async (savedId: string): Promise<boolean> => {
    if (!canAssignTeam || assignRecipientId == null || assignTarget == null) return false
    if (!isPersistedQuoteId(savedId)) return false
    if (assignTarget !== 'compras') return false

    try {
      const result = await assignQuoteToCompras(savedId, assignRecipientId)
      toast(
        result.previousFolio && result.folio
          ? `Guardada y asignada a compras. Folio ${result.previousFolio} → ${result.folio}`
          : 'Guardada y asignada a compras.',
      )
      setAllowLeave(true)
      navigate(`/cotizaciones/${result.id}`, { replace: true })
      const refreshed = await getQuoteById(result.id)
      setFolio(refreshed.folio)
      setStatus(refreshed.status)
      setSavedStatus(refreshed.status)
      setViewerCreatedByName(refreshed.createdByName ?? '')
      setOwnedByViewer(refreshed.ownedByViewer ?? true)
      saveQuote(refreshed)
      setAssignRecipientId(null)
      setAssignTarget(null)
      setAllowLeave(false)
      return true
    } catch (err: unknown) {
      setSaveError(
        err instanceof Error
          ? err.message
          : 'Se guardó, pero no se pudo asignar a compras.',
      )
      toast('Se guardó, pero falló la asignación a compras.')
      return false
    }
  }

  const confirmSaveWithStatus = async (targetStatus: QuoteStatus) => {
    const draft = buildDraftQuote()
    if (!draft) return

    // Mantener el modal abierto para mostrar la animación de guardado.
    setStatus(targetStatus)
    const payload = {
      ...draft,
      status: targetStatus,
      ...(serverQuoteId && isPersistedQuoteId(serverQuoteId) ? { id: serverQuoteId } : {}),
    }
    // No navegar aún: si hay vendedor, asignar en el mismo flujo Guardar.
    const savedId = await saveDraft(payload, false)
    if (!savedId) return

    const assigned = await assignAfterSaveIfNeeded(savedId)
    if (!assigned) {
      setAllowLeave(true)
      navigate(`/cotizaciones/${savedId}`, { replace: isNew })
      setAllowLeave(false)
    }
    setSaveModalOpen(false)
  }

  const openSaveModal = () => {
    if (!buildDraftQuote()) return

    // Post-envío: guardar cambios = modificación (no ofrecer Terminada/Elaboración).
    if (
      savedStatus === 'enviada' ||
      savedStatus === 'aceptada' ||
      savedStatus === 'facturada' ||
      savedStatus === 'modificacion'
    ) {
      void confirmSaveWithStatus('modificacion')
      return
    }

    if (isNew || savedStatus === 'en_elaboracion' || savedStatus === 'solicitud_cotizaciones') {
      setSaveModalOpen(true)
      return
    }

    void confirmSaveWithStatus(isPreSentStatus(status) ? status : savedStatus)
  }

  const openEmailModal = () => {
    setEmailTo(client?.email?.trim() ?? '')
    setEmailSubject('')
    setEmailMessage('')
    setEmailFeedback(null)
    setEmailModalOpen(true)
  }

  const refreshQuoteAfterSend = async (id?: string) => {
    const targetId = id ?? serverQuoteId
    if (!targetId) return
    try {
      const updated = await getQuoteById(targetId)
      setStatus(updated.status)
      setSavedStatus(updated.status)
      setSentAt(updated.sentAt)
      setInvoiceNumber(updated.invoiceNumber ?? '')
      saveQuote(updated)
    } catch {
      // El envío ya fue encolado; el usuario puede recargar manualmente.
    }
  }

  const handleSendEmail = async () => {
    const to = emailTo.trim()
    if (!to) {
      setEmailFeedback('Indica un correo destinatario.')
      return
    }

    beginModalBusy(setSendingEmail)
    await waitModalBusyPaint()
    const busyStarted = performance.now()
    setEmailFeedback(null)
    setActionLoading('email')
    try {
      const quoteIdForSend = await ensureQuoteSaved()
      if (!quoteIdForSend) {
        await waitMinBusyMs(busyStarted)
        return
      }

      const result = await sendQuoteByEmail(quoteIdForSend, {
        to,
        subject: emailSubject.trim() || undefined,
        message: emailMessage.trim() || undefined,
      })
      await waitMinBusyMs(busyStarted)
      setEmailModalOpen(false)
      setEmailFeedback(
        result.message ||
          'Correo encolado. Se enviará en unos segundos si el worker de cola está activo.',
      )
      window.setTimeout(() => void refreshQuoteAfterSend(quoteIdForSend), 2500)
    } catch (err: unknown) {
      setEmailFeedback(
        err instanceof Error ? err.message : 'No se pudo encolar el envío por correo.',
      )
      await waitMinBusyMs(busyStarted)
    } finally {
      setSendingEmail(false)
      setActionLoading(null)
    }
  }

  const handleOpenPdf = async () => {
    setActionLoading('pdf')
    try {
      if (viewingOthers && quoteId && isPersistedQuoteId(quoteId)) {
        await openQuotePdf(quoteId)
        return
      }
      const draft = buildDraftQuote()
      if (!draft) return

      // Ver PDF no marca «enviada»: la cotización desde solicitudes sigue en elaboración
      // hasta enviar por correo al cliente.
      const quoteIdForPdf = await saveDraft(draft)
      if (quoteIdForPdf) {
        await openQuotePdf(quoteIdForPdf)
      }
    } finally {
      setActionLoading(null)
    }
  }

  const fromPricing = Boolean(request?.pricingLines?.length)

  if (needsEditLock && lockState === 'pending') {
    return (
      <div className="py-16 text-center text-slate-500">
        Reservando cotización para edición…
      </div>
    )
  }

  if (needsEditLock && lockState === 'blocked') {
    return (
      <div className="mx-auto max-w-lg py-16">
        <Card>
          <CardBody className="space-y-4 text-center">
            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 text-amber-700">
              <Lock className="h-6 w-6" />
            </div>
            <h2 className="text-lg font-semibold text-slate-900">
              Cotización en seguimiento
            </h2>
            <p className="text-sm text-slate-600">
              {lockedBy
                ? `${lockedBy.userName} (${lockedBy.userEmail}) está trabajando esta cotización. No puedes entrar hasta que termine o cierre la sesión.`
                : 'Otro usuario está trabajando esta cotización.'}
            </p>
            <div className="flex flex-wrap justify-center gap-2 pt-2">
              <Link to="/cotizaciones">
                <Button variant="secondary" size="sm">
                  <ArrowLeft className="h-4 w-4" />
                  Volver al listado
                </Button>
              </Link>
              <Button size="sm" onClick={() => void retryAcquire()}>
                Reintentar
              </Button>
            </div>
          </CardBody>
        </Card>
      </div>
    )
  }

  if (loadingQuote) {
    return <LoadingState label="Cargando cotización…" variant="page" />
  }

  return (
    <div id="quote-detail-panel" className="relative">
      {unsavedDialog}
      <PageHeader
        title={isNew ? 'Nueva cotización' : folio}
        description={
          fromPricing
            ? `${client?.company ?? ''} — precios de compras precargados (editable)`
            : client?.company
        }
        actions={
          <>
            <Link to="/cotizaciones">
              <Button variant="secondary" size="sm">
                <ArrowLeft className="h-4 w-4" />
                Volver
              </Button>
            </Link>
            {!isNew && <QuoteStatusBadge status={status} />}
            <Button
              variant="secondary"
              size="sm"
              disabled={!canUseQuoteActions || isBusy}
              title={
                !clientId
                  ? 'Selecciona un cliente'
                  : lines.length === 0
                    ? 'Agrega partidas para generar PDF'
                    : actionLoading === 'pdf'
                      ? 'Guardando y generando PDF…'
                      : 'Ver PDF'
              }
              onClick={() => void handleOpenPdf()}
            >
              {actionLoading === 'pdf' ? <InlineBusy size="sm" /> : <Download className="h-4 w-4" />}
              {actionLoading === 'pdf' ? 'PDF…' : 'PDF'}
            </Button>
            <Button
              variant="secondary"
              size="sm"
              disabled={!canEmail || isBusy}
              title={
                !canSendEmail
                  ? 'No tienes permiso para enviar cotizaciones'
                  : !clientId
                    ? 'Selecciona un cliente'
                    : lines.length === 0
                      ? 'Agrega partidas para enviar'
                      : 'Enviar cotización por correo'
              }
              onClick={openEmailModal}
            >
              <Mail className="h-4 w-4" />
              Email
            </Button>
            <Button variant="secondary" size="sm" disabled title="Próximamente">
              <MessageCircle className="h-4 w-4" />
              WhatsApp
            </Button>
            <Button size="sm" onClick={openSaveModal} disabled={!canSaveQuote || isBusy}>
              <Save className="h-4 w-4" />
              {saving && !actionLoading ? <InlineBusy size="sm" label="Guardando…" /> : 'Guardar'}
            </Button>
          </>
        }
      />

      {viewingOthers && (
        <p className="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
          Vista de otra persona ({viewerCreatedByName.trim() || 'equipo'}). Puedes consultarla; no
          se guarda ni se envía desde ventas.
        </p>
      )}

      {showAssignPanel && (
        <div className="mb-4">
          <AssignToSalesPanel
            entityLabel="cotización"
            disabled={saving || isBusy}
            saveWithParent
            onRecipientChange={(id, target) => {
              setAssignRecipientId(id)
              setAssignTarget(target)
            }}
          />
        </div>
      )}

      {canAssignTeam && viewingOthers && (
        <p className="mb-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
          Vista de otra persona ({viewerCreatedByName.trim() || 'equipo'}). Solo el responsable
          actual puede enviarla a compras.
        </p>
      )}

      {(saveError || loadError) && (
        <p className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          {saveError ?? loadError}
        </p>
      )}

      {isNew && !folioLoading && folio && folio !== '—' && (
        <div className="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3">
          <p className="text-xs font-medium uppercase tracking-wide text-indigo-600">Folio asignado</p>
          <p className="mt-1 font-mono text-2xl font-bold text-indigo-950">{folio}</p>
          <p className="mt-1 text-sm text-indigo-800">
            Se reserva al guardar. Prefijo por usuario: {user?.folioCode ?? 'automático'}.
          </p>
        </div>
      )}

      {emailFeedback && !emailModalOpen && (
        <p className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          {emailFeedback}
          {sentAt && (
            <span className="mt-1 block text-emerald-700">
              Enviada: {new Date(sentAt).toLocaleString('es-MX')}
            </span>
          )}
        </p>
      )}

      {saveModalOpen && (
        <div
          className="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/50 px-4 pb-4 pt-[12vh]"
          role="dialog"
          aria-modal="true"
          aria-labelledby="save-quote-title"
          onClick={() => !saving && setSaveModalOpen(false)}
        >
          <div
            className="relative w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-xl"
            onClick={(e) => e.stopPropagation()}
          >
            {saving ? (
              <ModalBusyPanel label="Guardando cotización…" />
            ) : (
              <>
                <div className="mb-5">
                  <h3 id="save-quote-title" className="text-lg font-bold text-slate-900">
                    ¿Cómo deseas guardar la cotización?
                  </h3>
                  <p className="mt-2 text-sm text-slate-600">
                    En elaboración no se marca como lista para ventas. Terminada sí queda lista
                    para el siguiente paso.
                    {showAssignPanel && assignRecipientId != null && assignTarget
                      ? ` Al confirmar, también se enviará a ${assignTarget}.`
                      : ''}
                  </p>
                </div>

                <div className="flex flex-col gap-3">
                  <Button
                    size="sm"
                    className="w-full justify-center"
                    onClick={() => void confirmSaveWithStatus('pendiente_envio')}
                  >
                    Guardar en Terminada
                  </Button>
                  <p className="text-xs text-slate-500">
                    Queda en <strong>Lista / Terminada</strong> (lista para ventas / envío).
                  </p>

                  <Button
                    variant="secondary"
                    size="sm"
                    className="mt-2 w-full justify-center"
                    onClick={() => void confirmSaveWithStatus('en_elaboracion')}
                  >
                    Guardar en Elaboración
                  </Button>
                  <p className="text-xs text-slate-500">
                    Permanece en <strong>En elaboración</strong>; no se envía a ventas como lista.
                  </p>
                </div>

                <button
                  type="button"
                  className="mt-5 text-sm text-slate-500 hover:text-slate-700"
                  onClick={() => setSaveModalOpen(false)}
                >
                  Cancelar
                </button>
              </>
            )}
          </div>
        </div>
      )}

      {emailModalOpen && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
          role="dialog"
          aria-modal="true"
          aria-labelledby="email-quote-title"
          onClick={() => !sendingEmail && setEmailModalOpen(false)}
        >
          <div
            className="relative w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-xl"
            onClick={(e) => e.stopPropagation()}
          >
            {sendingEmail ? (
              <ModalBusyPanel label="Enviando cotización…" />
            ) : (
              <>
                <div className="mb-4 flex items-start justify-between gap-4">
                  <div>
                    <h3 id="email-quote-title" className="text-lg font-bold text-slate-900">
                      Enviar cotización por correo
                    </h3>
                    <p className="mt-1 text-sm text-slate-500">
                      Se adjuntará el PDF y el envío se procesará en cola (SMTP).
                    </p>
                  </div>
                  <button
                    type="button"
                    className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    onClick={() => setEmailModalOpen(false)}
                    aria-label="Cerrar"
                  >
                    <X className="h-5 w-5" />
                  </button>
                </div>

                <div className="space-y-4">
                  <div>
                    <Label>Destinatario</Label>
                    <Input
                      type="email"
                      value={emailTo}
                      onChange={(e) => setEmailTo(e.target.value)}
                      placeholder="cliente@empresa.com"
                    />
                  </div>
                  <div>
                    <Label>Asunto (opcional)</Label>
                    <Input
                      value={emailSubject}
                      onChange={(e) => setEmailSubject(e.target.value)}
                      placeholder={`Cotización ${folio}`}
                    />
                  </div>
                  <div>
                    <Label>Mensaje (opcional)</Label>
                    <Textarea
                      value={emailMessage}
                      onChange={(e) => setEmailMessage(e.target.value)}
                      rows={4}
                      placeholder="Mensaje personalizado para el cliente…"
                    />
                  </div>
                  {emailFeedback && (
                    <p className="text-sm text-red-600">{emailFeedback}</p>
                  )}
                </div>

                <div className="mt-6 flex justify-end gap-2">
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => setEmailModalOpen(false)}
                  >
                    Cancelar
                  </Button>
                  <Button size="sm" onClick={() => void handleSendEmail()}>
                    Enviar correo
                  </Button>
                </div>
              </>
            )}
          </div>
        </div>
      )}

      <div className="grid gap-6 xl:grid-cols-[minmax(300px,0.85fr)_minmax(0,2.15fr)]">
        <div className="order-2 min-w-0 space-y-6 xl:col-start-2 xl:row-start-1">
          <Card>
            <CardHeader
              title="Partidas"
              subtitle={
                canUseComparator
                  ? 'Escribe un SKU para comparar precios automáticamente'
                  : 'Agrega productos y captura cantidad, descripción y SKU'
              }
            />
            <CardBody className="overflow-x-auto p-0">
              <QuoteLinesEditor
                lines={lines}
                globalMargin={globalMargin}
                preferredWarehouse={preferredWarehouse}
                quoteId={serverQuoteId}
                autoApplyBest={false}
                comparatorEnabled={canUseComparator && comparatorPrefsReady}
                showComparator={canUseComparator}
                readOnly={quoteFieldsReadOnly}
                showProfit={canEditMarginFields}
                onChange={setLines}
              />
            </CardBody>
          </Card>
        </div>

        <div className="order-1 min-w-0 space-y-6 xl:col-start-1 xl:row-start-1">
          <Card>
            <CardHeader title="Datos generales" />
            <CardBody className="space-y-4">
              <div>
                <Label>Folio</Label>
                <Input
                  value={folioLoading ? 'Generando folio…' : folio}
                  disabled
                  readOnly
                  className="bg-slate-50"
                  title="Folio único asignado automáticamente por el sistema"
                />
                {isNew && !folioLoading && (
                  <p className="mt-1 text-xs text-slate-500">
                    Se confirma al guardar la cotización (formato{' '}
                    {`COT-${user?.folioCode ?? 'CODIGO'}-0001`}).
                  </p>
                )}
              </div>
              <div>
                <ClientSearchSelect
                  label="Cliente"
                  value={clientId}
                  disabled={quoteFieldsReadOnly}
                  onChange={(id, c) => {
                    setClientId(id)
                    setSelectedClient(c ?? null)
                  }}
                  placeholder="Escribe para buscar cliente"
                />
                {client && (
                  <p className="mt-1 text-xs text-slate-500">
                    Correo:{' '}
                    {client.email?.trim() ? (
                      <span className="font-medium text-slate-700">{client.email}</span>
                    ) : (
                      <span className="text-amber-700">Sin correo — indícalo al enviar</span>
                    )}
                  </p>
                )}
              </div>
              <div>
                <Label>Estado (automático)</Label>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                  <QuoteStatusBadge status={status} />
                </div>
                <ol className="mt-3 grid gap-1.5 sm:grid-cols-2">
                  {QUOTE_WORKFLOW_ORDER.map((stepStatus, index) => {
                    const active = workflowStep === index
                    const done = workflowStep > index
                    return (
                      <li
                        key={stepStatus}
                        className={`rounded-lg border px-2.5 py-1.5 text-xs ${
                          active
                            ? 'border-indigo-400 bg-indigo-50 font-semibold text-indigo-900'
                            : done
                              ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                              : 'border-slate-200 bg-slate-50 text-slate-500'
                        }`}
                      >
                        <span className="mr-1 font-mono">{index + 1}.</span>
                        {QUOTE_STATUS_LABELS[stepStatus]}
                      </li>
                    )
                  })}
                </ol>
                <p className="mt-2 text-xs text-slate-500">
                  El estatus cambia con el flujo (solicitud → elaboración; guardar;
                  correo al cliente → enviada; guardar cambios tras enviada → modificación).
                  Ver PDF no cambia el estatus.
                </p>
              </div>
              {!isNew && statusHistory.length > 0 && (
                <div>
                  <Label>Historial de estatus</Label>
                  <ul className="mt-2 max-h-40 space-y-2 overflow-y-auto rounded-lg border border-slate-100 bg-slate-50/80 p-3 text-xs">
                    {statusHistory.map((event, index) => (
                      <li key={`${event.createdAt}-${index}`} className="text-slate-700">
                        <span className="font-medium">
                          {event.fromStatus
                            ? `${QUOTE_STATUS_LABELS[event.fromStatus]} → ${QUOTE_STATUS_LABELS[event.toStatus]}`
                            : QUOTE_STATUS_LABELS[event.toStatus]}
                        </span>
                        <span className="mt-0.5 block text-slate-500">
                          {formatDateTime(event.createdAt)}
                          {event.userName ? ` · ${event.userName}` : ''}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              {status === 'facturada' && (
                <div>
                  <Label>Número de factura o ticket</Label>
                  <Input
                    value={invoiceNumber}
                    disabled={!(canEdit || canCreate)}
                    onChange={(e) => setInvoiceNumber(e.target.value)}
                    placeholder="Ej. FAC-2026-00123"
                  />
                </div>
              )}
              {canUseComparator ? (
                <>
                  <div>
                    <Label>Almacenes preferidos (por mayorista)</Label>
                    <PreferredWarehousesByWholesaler
                      groups={warehouseGroups}
                      preferredWarehouses={preferredWarehouses}
                      role={user?.role}
                      density="compact"
                      disabled={quoteFieldsReadOnly}
                      onChange={(next) => {
                        setPreferredWarehouses(next)
                        setPreferredWarehouse(next.join(','))
                      }}
                    />
                  </div>
                  <p className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                    Selección manual: después de comparar, elige la oferta en{' '}
                    <strong className="font-medium text-slate-700">Inventario de los mayoristas</strong>.
                    No se aplica sola la mejor opción.
                  </p>
                </>
              ) : null}
              <div>
                <Label>Observaciones al cliente</Label>
                <Textarea
                  value={customerObservations}
                  disabled={quoteFieldsReadOnly}
                  onChange={(e) => setCustomerObservations(e.target.value)}
                  rows={3}
                  maxLength={4000}
                  placeholder="Ej. Tiempo de entrega, disponibilidad, garantía o condiciones especiales…"
                />
                <p className="mt-1 text-xs text-slate-500">Se mostrará en el PDF enviado al cliente.</p>
              </div>
              <div className="border-t border-slate-100 pt-4">
                <Label>Bitácora interna</Label>
                <Textarea
                  value={internalNoteDraft}
                  disabled={quoteFieldsReadOnly || addingInternalNote}
                  onChange={(e) => setInternalNoteDraft(e.target.value)}
                  rows={2}
                  maxLength={4000}
                  placeholder="Seguimiento interno; nunca se muestra al cliente…"
                />
                <div className="mt-2 flex items-center justify-between gap-2">
                  <p className="text-xs text-slate-500">Cada entrada conserva autor y fecha.</p>
                  <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    disabled={quoteFieldsReadOnly || addingInternalNote || internalNoteDraft.trim() === ''}
                    onClick={() => void appendInternalNote()}
                  >
                    {addingInternalNote ? <InlineBusy size="sm" /> : null}
                    Agregar a bitácora
                  </Button>
                </div>
                {internalNoteError ? <p className="mt-2 text-xs text-red-600">{internalNoteError}</p> : null}
                {internalNotes.length > 0 ? (
                  <ul className="mt-3 max-h-52 space-y-2 overflow-y-auto">
                    {internalNotes.map((note) => (
                      <li key={note.id} className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs">
                        <p className="whitespace-pre-wrap text-slate-700">{note.body}</p>
                        <p className="mt-1 text-[11px] text-slate-500">
                          {note.userName || 'Usuario'} · {formatDateTime(note.createdAt)}
                        </p>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="mt-3 text-xs text-slate-400">Sin entradas internas.</p>
                )}
              </div>
            </CardBody>
          </Card>

          <Card>
            <CardHeader title="Totales" />
            <CardBody>
              <QuoteTotalsPanel
                lines={lines}
                taxPercent={taxPercent}
                showProfit={canEditMarginFields}
              />
            </CardBody>
          </Card>
        </div>
      </div>
    </div>
  )
}

export function QuoteFormPage() {
  const { id } = useParams()
  const [search] = useSearchParams()
  const requestId = search.get('request')
  const clientIdParam = search.get('clientId')

  return (
    <QuoteFormEditor
      key={`${id ?? 'nueva'}-${requestId ?? ''}-${clientIdParam ?? ''}`}
      quoteId={id}
      requestId={requestId}
      clientIdParam={clientIdParam}
    />
  )
}
