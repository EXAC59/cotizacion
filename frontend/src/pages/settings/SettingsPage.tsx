import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Shield } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Input, Label, Textarea } from '@/components/ui/Input'
import { InlineBusy } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { useAuth } from '@/hooks/useAuth'
import { useData } from '@/hooks/useData'
import { usePermission } from '@/hooks/usePermission'
import { useBranding } from '@/hooks/useBranding'
import {
  brandingLogoSrc,
  DEFAULT_APP_BRANDING,
} from '@/lib/branding-api'
import {
  getCommercialSettings,
  updateCommercialSettings,
  uploadCompanyLogo,
} from '@/lib/pricing-api'
import {
  getComparatorPreferences,
  getComparatorSettings,
  updateComparatorPreferences,
  updateComparatorSettings,
  type ComparatorSettings,
} from '@/lib/comparator-settings-api'
import {
  DEFAULT_PREFERRED_WAREHOUSES,
  NATIONAL_WAREHOUSE_PRIORITY_CSV,
  mergeWarehouseOptions,
  type WarehouseSelectOption,
} from '@/lib/preferred-warehouses'
import {
  PreferredWarehousesByWholesaler,
  groupsFromApiOrFallback,
  type WarehousesByWholesalerGroup,
} from '@/components/settings/PreferredWarehousesByWholesaler'
import { listWholesalers } from '@/lib/wholesalers-api'
import {
  CAPABILITY_LABELS,
  CAPABILITY_ROLES,
  getRoleCapabilityMatrix,
} from '@/lib/capabilities'
import { ROLE_LABELS } from '@/types'
import type { Wholesaler } from '@/types'

export function SettingsPage() {
  const { user } = useAuth()
  const {
    canModule,
    rolePermissions,
    canConfigIntegrations,
    canEditCommercialSettings,
    canManageUsers,
    canConsultInventory,
    isAdmin,
  } = usePermission()
  const capabilityMatrix = getRoleCapabilityMatrix(rolePermissions)
  const showCommercial = canEditCommercialSettings()
  const showIntegrations = canConfigIntegrations()
  const showRolesMatrix = canManageUsers()
  const showAlertThresholds = isAdmin
  const showPreferredWarehouses = canConsultInventory()
  const { setMinStockAlert } = useData()
  const { refreshBranding } = useBranding()
  const [defaultMargin, setDefaultMargin] = useState('30')
  const [taxPercent, setTaxPercent] = useState('16')
  const [unansweredQuoteDays, setUnansweredQuoteDays] = useState('3')
  const [currencyCode, setCurrencyCode] = useState('MXN')
  const [appName, setAppName] = useState(DEFAULT_APP_BRANDING.appName)
  const [appTagline, setAppTagline] = useState(DEFAULT_APP_BRANDING.appTagline)
  const [logoUrl, setLogoUrl] = useState<string | null>(null)
  const [uploadingLogo, setUploadingLogo] = useState(false)
  const [savingBranding, setSavingBranding] = useState(false)
  const [brandingSaved, setBrandingSaved] = useState(false)
  const [brandingError, setBrandingError] = useState<string | null>(null)
  const [loadingCommercial, setLoadingCommercial] = useState(true)
  const [savingCommercial, setSavingCommercial] = useState(false)
  const [commercialError, setCommercialError] = useState<string | null>(null)
  const [commercialSaved, setCommercialSaved] = useState(false)
  const [savingAlertThresholds, setSavingAlertThresholds] = useState(false)
  const [alertThresholdsSaved, setAlertThresholdsSaved] = useState(false)
  const [alertThresholdsError, setAlertThresholdsError] = useState<string | null>(null)
  const [loadingComparator, setLoadingComparator] = useState(true)
  const [savingComparator, setSavingComparator] = useState(false)
  const [comparatorSaved, setComparatorSaved] = useState(false)
  const [comparatorError, setComparatorError] = useState<string | null>(null)
  const [wholesalers, setWholesalers] = useState<Wholesaler[]>([])
  const [weightPrice, setWeightPrice] = useState('0.45')
  const [weightStock, setWeightStock] = useState('0.25')
  const [weightWarehouse, setWeightWarehouse] = useState('0.15')
  const [weightPerformance, setWeightPerformance] = useState('0.10')
  const [weightPreferred, setWeightPreferred] = useState('0.05')
  const [warehousePriority, setWarehousePriority] = useState(NATIONAL_WAREHOUSE_PRIORITY_CSV)
  const [preferredWholesalerIds, setPreferredWholesalerIds] = useState<string[]>([])
  const [importPenalty, setImportPenalty] = useState('0.08')
  const [leadDayPenalty, setLeadDayPenalty] = useState('0.005')
  const [minStockThreshold, setMinStockThreshold] = useState('1')
  const [preferredWarehouse, setPreferredWarehouse] = useState<string>(
    () => DEFAULT_PREFERRED_WAREHOUSES[0],
  )
  const [preferredWarehouses, setPreferredWarehouses] = useState<string[]>(() => [
    ...DEFAULT_PREFERRED_WAREHOUSES,
  ])
  const [, setAutoApplyBest] = useState(false)
  const [warehouseOptions, setWarehouseOptions] = useState<WarehouseSelectOption[]>(() =>
    mergeWarehouseOptions(),
  )
  const [warehouseGroups, setWarehouseGroups] = useState<WarehousesByWholesalerGroup[]>(() =>
    groupsFromApiOrFallback(),
  )
  const [loadingPrefs, setLoadingPrefs] = useState(true)
  const [savingPrefs, setSavingPrefs] = useState(false)
  const [prefsSaved, setPrefsSaved] = useState(false)
  const [prefsError, setPrefsError] = useState<string | null>(null)

  const applyComparatorSettings = (settings: ComparatorSettings) => {
    setWeightPrice(String(settings.weights.price))
    setWeightStock(String(settings.weights.stock))
    setWeightWarehouse(String(settings.weights.warehouse))
    setWeightPerformance(String(settings.weights.performance))
    setWeightPreferred(String(settings.weights.preferred))
    setWarehousePriority(settings.warehousePriority.join(', '))
    setPreferredWholesalerIds(settings.preferredWholesalerIds)
    setImportPenalty(String(settings.importPenalty))
    setLeadDayPenalty(String(settings.leadDayPenalty))
    setMinStockThreshold(String(settings.minStockThreshold))
  }

  useEffect(() => {
    let cancelled = false
    setLoadingPrefs(true)
    setPrefsError(null)
    getComparatorPreferences()
      .then((prefs) => {
        if (cancelled) return
        const list =
          prefs.preferredWarehouses && prefs.preferredWarehouses.length > 0
            ? prefs.preferredWarehouses.map((w) => w.toUpperCase())
            : [prefs.preferredWarehouse || 'CDMX']
        setPreferredWarehouses(list)
        setPreferredWarehouse(list[0] || 'CDMX')
        setAutoApplyBest(Boolean(prefs.autoApplyBest))
        setWarehouseOptions(mergeWarehouseOptions(prefs.availableWarehouses))
        setWarehouseGroups(
          groupsFromApiOrFallback(
            prefs.availableWarehousesByWholesaler,
            prefs.availableWarehouses,
          ),
        )
      })
      .catch(() => {
        if (!cancelled) setPrefsError('No se pudo cargar el almacén preferido.')
      })
      .finally(() => {
        if (!cancelled) setLoadingPrefs(false)
      })
    return () => {
      cancelled = true
    }
  }, [user?.email])

  const saveMyWarehousePrefs = async () => {
    setSavingPrefs(true)
    setPrefsSaved(false)
    setPrefsError(null)
    try {
      const list = preferredWarehouses.length > 0 ? preferredWarehouses : [preferredWarehouse || 'CDMX']
      const saved = await updateComparatorPreferences({
        preferredWarehouse: list[0],
        preferredWarehouses: list,
        autoApplyBest: false,
      })
      const savedList =
        saved.preferredWarehouses && saved.preferredWarehouses.length > 0
          ? saved.preferredWarehouses.map((w) => w.toUpperCase())
          : [saved.preferredWarehouse || list[0]]
      setPreferredWarehouses(savedList)
      setPreferredWarehouse(savedList[0] || 'CDMX')
      setAutoApplyBest(false)
      if (saved.availableWarehouses) {
        setWarehouseOptions(mergeWarehouseOptions(saved.availableWarehouses))
      }
      setWarehouseGroups(
        groupsFromApiOrFallback(
          saved.availableWarehousesByWholesaler,
          saved.availableWarehouses,
        ),
      )
      setPrefsSaved(true)
    } catch {
      setPrefsError('No se pudo guardar el almacén preferido.')
    } finally {
      setSavingPrefs(false)
    }
  }

  useEffect(() => {
    getCommercialSettings()
      .then((s) => {
        setDefaultMargin(String(s.defaultMarginPercent))
        setTaxPercent(String(s.taxPercent))
        setUnansweredQuoteDays(String(s.unansweredQuoteDays ?? 3))
        setCurrencyCode(s.currencyCode)
        setMinStockAlert(s.minStockAlert)
        setAppName(s.appName?.trim() || DEFAULT_APP_BRANDING.appName)
        setAppTagline(s.appTagline?.trim() || DEFAULT_APP_BRANDING.appTagline)
        setLogoUrl(s.logoUrl ?? null)
      })
      .catch(() => setCommercialError('No se pudo cargar la configuración comercial.'))
      .finally(() => setLoadingCommercial(false))
  }, [setMinStockAlert])

  useEffect(() => {
    if (!showIntegrations) {
      setLoadingComparator(false)
      setComparatorError(null)
      return
    }

    let cancelled = false
    setLoadingComparator(true)
    setComparatorError(null)

    Promise.all([getComparatorSettings(), listWholesalers()])
      .then(([settings, wholesalerList]) => {
        if (cancelled) return
        applyComparatorSettings(settings)
        setWholesalers(wholesalerList.filter((w) => w.active))
      })
      .catch(() => {
        if (!cancelled) {
          setComparatorError('No se pudo cargar la configuración del comparador.')
        }
      })
      .finally(() => {
        if (!cancelled) setLoadingComparator(false)
      })

    return () => {
      cancelled = true
    }
  }, [showIntegrations])

  const saveComparator = async () => {
    setSavingComparator(true)
    setComparatorError(null)
    setComparatorSaved(false)
    try {
      const saved = await updateComparatorSettings({
        weights: {
          price: Number(weightPrice) || 0,
          stock: Number(weightStock) || 0,
          warehouse: Number(weightWarehouse) || 0,
          performance: Number(weightPerformance) || 0,
          preferred: Number(weightPreferred) || 0,
        },
        warehousePriority: warehousePriority
          .split(',')
          .map((w) => w.trim().toUpperCase())
          .filter(Boolean),
        preferredWholesalerIds: preferredWholesalerIds,
        importPenalty: Number(importPenalty) || 0,
        leadDayPenalty: Number(leadDayPenalty) || 0,
        minStockThreshold: Number(minStockThreshold) || 0,
      })
      applyComparatorSettings(saved)
      setComparatorSaved(true)
    } catch {
      setComparatorError('No se pudo guardar la configuración del comparador.')
    } finally {
      setSavingComparator(false)
    }
  }

  const togglePreferredWholesaler = (id: string) => {
    setPreferredWholesalerIds((current) =>
      current.includes(id) ? current.filter((w) => w !== id) : [...current, id],
    )
  }

  const saveCommercial = async () => {
    setSavingCommercial(true)
    setCommercialError(null)
    setCommercialSaved(false)
    try {
      const saved = await updateCommercialSettings({
        defaultMarginPercent: Number(defaultMargin) || 30,
        taxPercent: Number(taxPercent) || 16,
        currencyCode,
      })
      setDefaultMargin(String(saved.defaultMarginPercent))
      setTaxPercent(String(saved.taxPercent))
      setCurrencyCode(saved.currencyCode)
      setCommercialSaved(true)
    } catch (err: unknown) {
      setCommercialError(
        err instanceof Error ? err.message : 'No se pudo guardar la configuración.',
      )
    } finally {
      setSavingCommercial(false)
    }
  }

  const saveAlertThresholds = async () => {
    setSavingAlertThresholds(true)
    setAlertThresholdsError(null)
    setAlertThresholdsSaved(false)
    try {
      const saved = await updateCommercialSettings({
        unansweredQuoteDays: Math.max(1, Number(unansweredQuoteDays) || 3),
      })
      setUnansweredQuoteDays(String(saved.unansweredQuoteDays ?? 3))
      setAlertThresholdsSaved(true)
    } catch (err: unknown) {
      setAlertThresholdsError(
        err instanceof Error ? err.message : 'No se pudo guardar el umbral de seguimiento.',
      )
    } finally {
      setSavingAlertThresholds(false)
    }
  }

  const saveAppBranding = async () => {
    setSavingBranding(true)
    setBrandingError(null)
    setBrandingSaved(false)
    try {
      const saved = await updateCommercialSettings({
        appName: appName.trim() || null,
        appTagline: appTagline.trim() || null,
      })
      setAppName(saved.appName?.trim() || DEFAULT_APP_BRANDING.appName)
      setAppTagline(saved.appTagline?.trim() || DEFAULT_APP_BRANDING.appTagline)
      setLogoUrl(saved.logoUrl ?? null)
      await refreshBranding()
      setBrandingSaved(true)
    } catch (err: unknown) {
      setBrandingError(
        err instanceof Error ? err.message : 'No se pudo guardar la identidad de la aplicación.',
      )
    } finally {
      setSavingBranding(false)
    }
  }

  const handleAppLogoUpload = async (file: File | undefined) => {
    if (!file) return
    setUploadingLogo(true)
    setBrandingError(null)
    try {
      const saved = await uploadCompanyLogo(file)
      setLogoUrl(saved.logoUrl ?? null)
      await refreshBranding()
      setBrandingSaved(true)
    } catch (err: unknown) {
      setBrandingError(err instanceof Error ? err.message : 'No se pudo subir el logotipo.')
    } finally {
      setUploadingLogo(false)
    }
  }

  const logoPreviewSrc = brandingLogoSrc(logoUrl)

  return (
    <div>
      <PageHeader
        title="Configuración"
        description="Usuarios, roles y parámetros del sistema"
      />

      {canModule('admin') && (
        <Card className="mb-6 border-indigo-200 bg-indigo-50/50">
          <CardBody className="flex flex-wrap items-center justify-between gap-4">
            <div className="flex items-center gap-3">
              <Shield className="h-8 w-8 text-indigo-600" />
              <div>
                <p className="font-semibold text-slate-900">Panel de administración</p>
                <p className="text-sm text-slate-600">Usuarios, roles y permisos por módulo</p>
              </div>
            </div>
            <Link to="/admin">
              <Button>Abrir administración</Button>
            </Link>
          </CardBody>
        </Card>
      )}

      {commercialError && (
        <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          {commercialError}
        </p>
      )}

      {commercialSaved && (
        <p className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          Parámetros comerciales guardados.
        </p>
      )}

      {showIntegrations && comparatorError && (
        <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          {comparatorError}
        </p>
      )}

      {showIntegrations && comparatorSaved && (
        <p className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          Configuración del comparador guardada.
        </p>
      )}

      {prefsError && (
        <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          {prefsError}
        </p>
      )}

      {prefsSaved && (
        <p className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          Almacén preferido guardado. Se usará en el comparador de precios.
        </p>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Mi perfil" />
          <CardBody className="space-y-2 text-sm">
            <p>
              <span className="text-slate-500">Nombre:</span>{' '}
              <strong>{user?.name}</strong>
            </p>
            <p>
              <span className="text-slate-500">Correo:</span> {user?.email}
            </p>
            <p>
              <span className="text-slate-500">Rol:</span>{' '}
              {user ? ROLE_LABELS[user.role] : '—'}
            </p>
          </CardBody>
        </Card>

        {showCommercial && (
          <Card>
            <CardHeader
              title="Identidad de la aplicación"
              subtitle="Nombre, subtítulo y logo: menú lateral y pantalla de inicio / login"
            />

            <CardBody className="space-y-4">
              {loadingCommercial ? (
                <div className="flex items-center gap-2 text-sm text-slate-500">
                  <InlineBusy size="sm" label="Cargando…" />
                </div>
              ) : (
                <>
                  <div>
                    <Label>Logotipo</Label>
                    <div className="mt-2 flex items-center gap-3">
                      {logoPreviewSrc ? (
                        <img
                          src={logoPreviewSrc}
                          alt="Logo de la aplicación"
                          className="h-12 w-12 rounded-lg border border-slate-200 bg-white object-contain p-1"
                        />
                      ) : (
                        <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white">
                          {(appName.trim().charAt(0) || 'C').toUpperCase()}
                        </div>
                      )}
                      <div className="min-w-0 flex-1">
                        <Input
                          type="file"
                          accept="image/png,image/jpeg,image/webp"
                          disabled={uploadingLogo}
                          onChange={(e) => void handleAppLogoUpload(e.target.files?.[0])}
                        />
                        <p className="mt-1 text-xs text-slate-500">
                          {uploadingLogo
                            ? 'Subiendo…'
                            : 'PNG, JPG o WebP — máx. 2 MB. Se guarda al elegir el archivo.'}
                        </p>
                      </div>
                    </div>
                  </div>
                  <div>
                    <Label>Nombre de la aplicación</Label>
                    <Input
                      value={appName}
                      onChange={(e) => setAppName(e.target.value)}
                      placeholder={DEFAULT_APP_BRANDING.appName}
                      maxLength={120}
                    />
                  </div>
                  <div>
                    <Label>Subtítulo</Label>
                    <Input
                      value={appTagline}
                      onChange={(e) => setAppTagline(e.target.value)}
                      placeholder={DEFAULT_APP_BRANDING.appTagline}
                      maxLength={160}
                    />
                  </div>
                  {brandingError && (
                    <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                      {brandingError}
                    </p>
                  )}
                  {brandingSaved && (
                    <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                      Identidad actualizada. Ya se refleja en el menú.
                    </p>
                  )}
                  <Button
                    size="sm"
                    onClick={() => void saveAppBranding()}
                    disabled={savingBranding || uploadingLogo}
                  >
                    {savingBranding ? <InlineBusy size="sm" /> : null}
                    Guardar identidad
                  </Button>
                </>
              )}
            </CardBody>
          </Card>
        )}

        {showPreferredWarehouses && (
        <Card>
          <CardHeader
            title="Almacenes preferidos por mayorista"
            subtitle="Cada almacén va en su apartado. Aplica al comparador en solicitudes y cotizaciones"
          />
          <CardBody className="space-y-4">
            {loadingPrefs ? (
              <div className="flex items-center gap-2 text-sm text-slate-500">
                <InlineBusy size="sm" label="Cargando…" />
              </div>
            ) : (
              <>
                <div>
                  <Label>
                    Seleccionados: {preferredWarehouses.length}
                  </Label>
                  <p className="mb-2 text-xs text-slate-500">
                    Marca las sucursales que te importan. El comparador prioriza
                    inventario de esos almacenes.
                  </p>
                  <PreferredWarehousesByWholesaler
                    groups={warehouseGroups}
                    preferredWarehouses={preferredWarehouses}
                    role={user?.role}
                    density="comfortable"
                    onChange={(next) => {
                      setPreferredWarehouses(next)
                      setPreferredWarehouse(next[0] || 'CDMX')
                    }}
                  />
                  <p className="mt-2 text-xs text-slate-500">
                    Seleccionados:{' '}
                    <strong className="font-medium text-slate-700">
                      {preferredWarehouses
                        .map((code) => {
                          for (const g of warehouseGroups) {
                            const hit = g.warehouses.find(
                              (w) => w.value.toUpperCase() === code.toUpperCase(),
                            )
                            if (hit) return hit.label
                          }
                          return (
                            warehouseOptions.find((w) => w.value === code)?.label ??
                            code
                          )
                        })
                        .join(', ') || '—'}
                    </strong>
                  </p>
                </div>
                <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    checked={false}
                    disabled
                    className="rounded border-slate-300"
                  />
                  <span>
                    Selección manual de oferta
                    <span className="mt-0.5 block text-xs font-normal text-slate-500">
                      Ya no se aplica sola la mejor: elige en Inventario de los mayoristas.
                    </span>
                  </span>
                </label>
                <Button
                  type="button"
                  disabled={savingPrefs}
                  onClick={() => void saveMyWarehousePrefs()}
                >
                  {savingPrefs ? (
                    <>
                      <InlineBusy size="sm" label="Guardando…" />
                    </>
                  ) : (
                    'Guardar almacenes preferidos'
                  )}
                </Button>
              </>
            )}
          </CardBody>
        </Card>
        )}

        {showCommercial && (
        <Card>
          <CardHeader
            title="Parámetros comerciales"
            subtitle="Motor de profit — margen e IVA para nuevas cotizaciones"
          />
          <CardBody className="space-y-4">
            {loadingCommercial ? (
              <div className="flex items-center gap-2 text-sm text-slate-500">
                <InlineBusy size="sm" label="Cargando…" />
              </div>
            ) : (
              <>
                <div>
                  <Label>Margen por defecto (%)</Label>
                  <Input
                    type="number"
                    min={0}
                    step={0.1}
                    value={defaultMargin}
                    onChange={(e) => setDefaultMargin(e.target.value)}
                    placeholder="30"
                  />
                </div>
                <div>
                  <Label>IVA (%)</Label>
                  <Input
                    type="number"
                    min={0}
                    step={0.1}
                    value={taxPercent}
                    onChange={(e) => setTaxPercent(e.target.value)}
                    placeholder="16"
                  />
                </div>
                <div>
                  <Label>Moneda</Label>
                  <Input
                    maxLength={3}
                    value={currencyCode}
                    onChange={(e) => setCurrencyCode(e.target.value.toUpperCase())}
                    placeholder="MXN"
                  />
                </div>
                <Button size="sm" onClick={saveCommercial} disabled={savingCommercial}>
                  {savingCommercial ? (
                    <InlineBusy size="sm" />
                  ) : null}
                  Guardar parámetros
                </Button>
              </>
            )}
          </CardBody>
        </Card>
        )}

        {showAlertThresholds && (
        <Card>
          <CardHeader
            title="Alertas del dashboard"
            subtitle="Umbral para «Cotizaciones sin avance» (solo administrador)"
          />
          <CardBody className="space-y-4">
            {loadingCommercial ? (
              <div className="flex items-center gap-2 text-sm text-slate-500">
                <InlineBusy size="sm" label="Cargando…" />
              </div>
            ) : (
              <>
                {alertThresholdsError && (
                  <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                    {alertThresholdsError}
                  </p>
                )}
                {alertThresholdsSaved && (
                  <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    Umbral guardado. Ya aplica en el dashboard.
                  </p>
                )}
                <div>
                  <Label htmlFor="unanswered-quote-days">Días sin avance para alertar</Label>
                  <Input
                    id="unanswered-quote-days"
                    type="number"
                    min={1}
                    max={365}
                    value={unansweredQuoteDays}
                    onChange={(e) => setUnansweredQuoteDays(e.target.value)}
                    placeholder="3"
                  />
                  <p className="mt-1 text-xs text-slate-500">
                    Si una cotización permanece en elaboración o Lista / Terminada sin abrirse ni
                    actualizarse durante este plazo, aparece en «Cotizaciones sin avance».
                  </p>
                </div>
                <Button
                  size="sm"
                  onClick={() => void saveAlertThresholds()}
                  disabled={savingAlertThresholds}
                >
                  {savingAlertThresholds ? <InlineBusy size="sm" /> : null}
                  Guardar umbral
                </Button>
              </>
            )}
          </CardBody>
        </Card>
        )}

        {showIntegrations && (
        <Card>
          <CardHeader
            title="Comparador de precios"
            subtitle="Pesos de ranking, almacenes y mayoristas preferidos"
          />
          <CardBody className="space-y-4">
            {loadingComparator ? (
              <div className="flex items-center gap-2 text-sm text-slate-500">
                <InlineBusy size="sm" label="Cargando…" />
              </div>
            ) : (
              <>
                <div className="grid gap-3 sm:grid-cols-2">
                  <div>
                    <Label>Peso precio</Label>
                    <Input type="number" min={0} max={1} step={0.01} value={weightPrice} onChange={(e) => setWeightPrice(e.target.value)} placeholder="0.45" />
                  </div>
                  <div>
                    <Label>Peso stock</Label>
                    <Input type="number" min={0} max={1} step={0.01} value={weightStock} onChange={(e) => setWeightStock(e.target.value)} placeholder="0.25" />
                  </div>
                  <div>
                    <Label>Peso almacén</Label>
                    <Input type="number" min={0} max={1} step={0.01} value={weightWarehouse} onChange={(e) => setWeightWarehouse(e.target.value)} placeholder="0.15" />
                  </div>
                  <div>
                    <Label>Peso desempeño</Label>
                    <Input type="number" min={0} max={1} step={0.01} value={weightPerformance} onChange={(e) => setWeightPerformance(e.target.value)} placeholder="0.10" />
                  </div>
                  <div>
                    <Label>Peso preferidos</Label>
                    <Input type="number" min={0} max={1} step={0.01} value={weightPreferred} onChange={(e) => setWeightPreferred(e.target.value)} placeholder="0.05" />
                  </div>
                  <div>
                    <Label>Penalización importación</Label>
                    <Input type="number" min={0} max={1} step={0.01} value={importPenalty} onChange={(e) => setImportPenalty(e.target.value)} placeholder="0.08" />
                  </div>
                  <div>
                    <Label>Penalización por día de entrega</Label>
                    <Input type="number" min={0} max={1} step={0.001} value={leadDayPenalty} onChange={(e) => setLeadDayPenalty(e.target.value)} placeholder="0.005" />
                  </div>
                  <div>
                    <Label>Umbral mínimo de stock</Label>
                    <Input type="number" min={0} value={minStockThreshold} onChange={(e) => setMinStockThreshold(e.target.value)} placeholder="1" />
                  </div>
                </div>
                <div>
                  <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                    <Label>Prioridad de almacenes (CSV)</Label>
                    <Button
                      type="button"
                      size="sm"
                      variant="secondary"
                      onClick={() => setWarehousePriority(NATIONAL_WAREHOUSE_PRIORITY_CSV)}
                    >
                      Toda la República
                    </Button>
                  </div>
                  <Textarea
                    className="min-h-[4.5rem] font-mono text-xs"
                    value={warehousePriority}
                    onChange={(e) => setWarehousePriority(e.target.value)}
                    placeholder={NATIONAL_WAREHOUSE_PRIORITY_CSV}
                  />
                  <p className="mt-1 text-xs text-slate-500">
                    Códigos de región CT (40 ciudades). El primero tiene más peso al comparar. Usa «Toda la
                    República» o edita el CSV (ej. CDMX, MTY, GDL, …). Corrección: SLP = San Luis Potosí (no SLN).
                  </p>
                </div>
                {wholesalers.length > 0 && (
                  <div>
                    <Label>Mayoristas preferidos</Label>
                    <div className="mt-2 flex flex-wrap gap-2">
                      {wholesalers.map((w) => (
                        <label
                          key={w.id}
                          className={`cursor-pointer rounded-full border px-3 py-1 text-xs ${
                            preferredWholesalerIds.includes(w.id)
                              ? 'border-indigo-300 bg-indigo-50 text-indigo-800'
                              : 'border-slate-200 bg-white text-slate-600'
                          }`}
                        >
                          <input
                            type="checkbox"
                            className="sr-only"
                            checked={preferredWholesalerIds.includes(w.id)}
                            onChange={() => togglePreferredWholesaler(w.id)}
                          />
                          {w.name}
                        </label>
                      ))}
                    </div>
                  </div>
                )}
                <Button size="sm" onClick={saveComparator} disabled={savingComparator}>
                  {savingComparator ? <InlineBusy size="sm" /> : null}
                  Guardar comparador
                </Button>
              </>
            )}
          </CardBody>
        </Card>
        )}

        {showRolesMatrix && (
        <Card className="lg:col-span-2">
          <CardHeader
            title="Roles del sistema"
            subtitle="Matriz de capacidades según permisos configurados en Administración"
          />
          <CardBody className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead className="border-b bg-slate-50 text-slate-500">
                <tr>
                  <th className="px-5 py-3 text-left font-medium">Rol</th>
                  {CAPABILITY_LABELS.map((p) => (
                    <th key={p} className="min-w-[7rem] px-2 py-3 text-center text-xs font-medium">
                      {p}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {CAPABILITY_ROLES.map((role) => (
                  <tr key={role} className="border-b border-slate-50">
                    <td className="px-5 py-3 font-medium">{ROLE_LABELS[role]}</td>
                    {CAPABILITY_LABELS.map((capability) => (
                      <td key={capability} className="px-2 py-3 text-center">
                        {capabilityMatrix[role][capability] ? (
                          <span className="text-emerald-600">✓</span>
                        ) : (
                          <span className="text-slate-300">—</span>
                        )}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </CardBody>
        </Card>
        )}

        {showIntegrations && (
        <Card className="lg:col-span-2">
          <CardHeader title="Integraciones" />
          <CardBody className="grid gap-3 text-sm sm:grid-cols-3">
            <div className="rounded-lg border border-slate-200 p-3">
              <p className="font-medium">Lectura de documentos</p>
              <p className="text-slate-500">PDF, Excel y Word con OCR automático</p>
            </div>
            <div className="rounded-lg border border-slate-200 p-3 sm:col-span-2">
              <p className="font-medium">SMTP — envío de cotizaciones</p>
              <p className="mt-1 text-slate-500">
                Configura el correo en el archivo <code className="text-xs">.env</code> del servidor:
                MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS y
                MAIL_FROM_NAME. Usa <code className="text-xs">QUEUE_CONNECTION=database</code> y ejecuta{' '}
                <code className="text-xs">php artisan queue:work</code> (o el cron en cPanel) para
                procesar los envíos en cola.
              </p>
            </div>
            <div className="rounded-lg border border-slate-200 p-3">
              <p className="font-medium">WhatsApp Business</p>
              <p className="text-slate-500">Fase 2 — API Meta</p>
            </div>
          </CardBody>
        </Card>
        )}
      </div>
    </div>
  )
}
