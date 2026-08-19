import { useEffect, useState } from 'react'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { InlineBusy, LoadingState } from '@/components/ui/LoadingState'
import { useAuth } from '@/hooks/useAuth'
import { canViewComparatorFreightInfo } from '@/lib/capabilities'
import { formatCurrency, formatDateTime } from '@/lib/format'
import { displayWholesalerName, displayWarehouseLabel } from '@/lib/wholesaler-display'
import type { ComparatorResult, WholesalerOffer } from '@/types'

function availabilityVariant(type?: string): 'success' | 'warning' {
  return type === 'import' ? 'warning' : 'success'
}

function availabilityLabel(type?: string): string {
  return type === 'import' ? 'Importación' : 'Local'
}

function freightLabel(offer: WholesalerOffer): string {
  if (offer.hasFreight === true) return 'Con flete'
  if (offer.hasFreight === false) return 'Sin flete'
  return 'Flete N/D'
}

function freightVariant(offer: WholesalerOffer): 'warning' | 'success' | 'muted' {
  if (offer.hasFreight === true) return 'warning'
  if (offer.hasFreight === false) return 'success'
  return 'muted'
}

export function ProductComparatorPanel({
  partNumber,
  productLabel,
  comparison,
  loading,
  error,
  variant = 'cards',
  selectedWholesalerId,
  onSelectOffer,
  onClearSelection,
  onRetry,
}: {
  partNumber: string
  productLabel?: string
  comparison: ComparatorResult | null
  loading: boolean
  error: string | null
  variant?: 'cards' | 'table'
  selectedWholesalerId?: string
  onSelectOffer?: (offer: WholesalerOffer) => void
  onClearSelection?: (offer: WholesalerOffer) => void
  onRetry?: () => void
}) {
  const { user } = useAuth()
  const showFreight = canViewComparatorFreightInfo(user)
  const [othersExpanded, setOthersExpanded] = useState(false)

  useEffect(() => {
    setOthersExpanded(false)
  }, [partNumber, comparison?.best?.wholesalerId, comparison?.offers?.length])

  const offerLabel = (o: WholesalerOffer, preferDescription = false) => {
    if (preferDescription && o.description?.trim()) {
      return o.description.trim()
    }
    return displayWholesalerName({
      role: user?.role,
      code: o.wholesalerCode,
      name: o.wholesalerName,
    })
  }

  const sku = partNumber.trim()
  if (sku === '') return null

  if (loading && (!comparison || comparison.offers.length === 0)) {
    return (
      <div className="mt-3 rounded-xl border border-indigo-100 bg-indigo-50/60 px-4 py-4">
        <LoadingState
          label={`Comparando precios para ${sku}…`}
          variant="inline"
          className="py-2"
        />
      </div>
    )
  }

  if (error) {
    // Solo fallos técnicos (red, timeout, etc.). Producto no encontrado usa aviso abajo.
    return (
      <div className="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {error}
        {onRetry && (
          <Button variant="ghost" size="sm" className="ml-2" onClick={onRetry}>
            Reintentar
          </Button>
        )}
      </div>
    )
  }

  const offers = [...(comparison?.offers ?? [])].sort(
    (a, b) => (a.rank ?? 999) - (b.rank ?? 999) || (b.score ?? 0) - (a.score ?? 0),
  )
  const notFound = comparison?.notFound ?? []

  // Si ya existe al menos una oferta útil, no ocupar la pantalla con avisos
  // por cada proveedor sin coincidencia. El aviso importa únicamente cuando
  // ningún mayorista pudo cotizar el SKU.
  const notFoundBanner =
    offers.length === 0 && (notFound.length > 0 || comparison?.notFoundSummary) ? (
      <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950">
        <p className="font-medium">
          {offers.length === 0 ? 'Sin existencias' : 'Sin existencias en algunos mayoristas'}
        </p>
        {notFound.length > 0 ? (
          <ul className="mt-2 space-y-1 text-xs text-amber-900/90">
            {notFound.map((row) => {
              const name = displayWholesalerName({
                role: user?.role,
                code: row.wholesalerCode,
                name: row.wholesalerName,
              })
              const detail =
                row.reason === 'no_stock' ? 'sin existencias' : 'no disponible'
              return (
                <li key={row.wholesalerId || row.message}>
                  <span className="font-semibold text-amber-950">{name}</span>
                  {': '}
                  {detail}
                </li>
              )
            })}
          </ul>
        ) : (
          <p className="mt-1 text-xs text-amber-900/90">
            {comparison?.notFoundSummary || `Sin existencias de ${sku}.`}
          </p>
        )}
        {offers.length === 0 && onRetry && (
          <Button variant="ghost" size="sm" className="mt-2" onClick={onRetry}>
            Buscar de nuevo
          </Button>
        )}
      </div>
    ) : null

  if (!offers.length) {
    return <div className="mt-3 space-y-2">{notFoundBanner}</div>
  }

  const best =
    comparison?.best ??
    offers.find((o) => o.isBest) ??
    offers[0] ??
    null
  const bestId = best?.wholesalerId
  const bestPart = best?.supplierPartNumber || best?.partNumber
  const requestedSku = comparison?.partNumber?.trim() || sku
  const isVariantList =
    offers.length > 1 &&
    offers.every((o) => (o.wholesalerCode ?? '').toUpperCase() === 'CT') &&
    offers.some(
      (o) => (o.supplierPartNumber || o.partNumber || '').trim().toUpperCase() !== requestedSku.toUpperCase(),
    )

  const offerKey = (o: WholesalerOffer, index: number) =>
    `${o.wholesalerId}-${o.supplierPartNumber || o.partNumber || ''}-${o.warehouse}-${o.rank ?? index}`

  const isOfferBest = (o: WholesalerOffer) => {
    const offerPart = (o.supplierPartNumber || o.partNumber || '').trim().toUpperCase()
    const bestPartNorm = (bestPart ?? '').trim().toUpperCase()
    return (
      o.wholesalerId === bestId &&
      (offerPart === '' || bestPartNorm === '' || offerPart === bestPartNorm)
    )
  }

  const bestOffers = best ? [best] : offers.filter(isOfferBest).slice(0, 1)
  const otherOffers = offers.filter((o) => !isOfferBest(o))
  const visibleOffers = othersExpanded ? [...bestOffers, ...otherOffers] : bestOffers
  const alternativeReason = (offer: WholesalerOffer) => {
    if (!best) return 'menos conveniente'
    const hasHigherPrice = (offer.unitCost ?? offer.cost) > (best.unitCost ?? best.cost)
    const hasLowerStock = offer.stock < best.stock
    if (hasHigherPrice && hasLowerStock) return 'precio mayor y menor stock'
    if (hasHigherPrice) return 'precio mayor'
    if (hasLowerStock) return 'menor stock'
    return 'menos conveniente'
  }

  const bestSummary =
    showFreight && best ? (
      <div className="mb-3 rounded-lg border border-emerald-200 bg-emerald-50/80 px-3 py-2 text-xs text-emerald-950">
        <p className="font-semibold text-emerald-900">
          Mejor oferta ·{' '}
          <span className="tabular-nums">{formatCurrency(best.unitCost ?? best.cost)}</span>
        </p>
        <p className="mt-0.5 text-emerald-900/90">
          Stock {best.stock}
          {' · '}
          {displayWarehouseLabel({ role: user?.role, warehouse: best.warehouse })}
          {' · '}
          {freightLabel(best)}
        </p>
      </div>
    ) : null

  const othersToggle =
    otherOffers.length > 0 ? (
      <div className={variant === 'table' ? 'border-t border-slate-100 px-2 py-1.5' : 'mt-2'}>
        <Button
          type="button"
          size="sm"
          variant="ghost"
          className={variant === 'table'
            ? 'h-8 w-full justify-between px-2 text-[11px] text-slate-600'
            : 'h-7 px-2 text-[11px] text-slate-600'}
          onClick={() => setOthersExpanded((v) => !v)}
        >
          {variant === 'table' ? (
            othersExpanded ? (
              'Minimizar ofertas menos convenientes'
            ) : (
              <>
                <span>
                  {otherOffers.length === 1
                    ? `Oferta con ${alternativeReason(otherOffers[0])}: ${offerLabel(otherOffers[0])}`
                    : `Otras ofertas (${otherOffers.length}): precio mayor o menor stock`}
                </span>
                <span className="font-semibold tabular-nums text-slate-700">
                  {otherOffers.length === 1
                    ? `${formatCurrency(otherOffers[0].unitCost ?? otherOffers[0].cost)} · Mostrar`
                    : 'Mostrar'}
                </span>
              </>
            )
          ) : othersExpanded ? (
            'Ocultar otros mayoristas'
          ) : (
            `Ver otros mayoristas (${otherOffers.length})`
          )}
        </Button>
      </div>
    ) : null

  const renderTableRows = (rows: WholesalerOffer[]) =>
    rows.map((o, index) => {
      const offerPart = (o.supplierPartNumber || o.partNumber || '').trim().toUpperCase()
      const isBest = isOfferBest(o)
      const rowSelected = Boolean(
        selectedWholesalerId &&
          selectedWholesalerId === o.wholesalerId &&
          (!isVariantList || offerPart === requestedSku.toUpperCase()),
      )
      const unitCost = o.unitCost ?? o.cost
      const canSelect = Boolean(onSelectOffer)
      const canClear = Boolean(onClearSelection)
      return (
        <tr
          key={offerKey(o, index)}
          className={rowSelected ? 'bg-indigo-50/40' : 'border-t border-slate-100'}
        >
          <td className="px-2 py-1 align-middle">
            <div className="flex flex-wrap items-center gap-1">
              <span className="font-medium text-slate-900">
                {isVariantList ? o.description || offerLabel(o) : offerLabel(o)}
              </span>
              {isBest && <Badge variant="success">Mejor</Badge>}
              {o.availabilityType && (
                <Badge variant={availabilityVariant(o.availabilityType)}>
                  {availabilityLabel(o.availabilityType)}
                </Badge>
              )}
            </div>
            <p className="mt-0.5 truncate text-[11px] text-slate-500">
              {isVariantList
                ? `${o.supplierPartNumber || o.partNumber || '—'} · ${displayWarehouseLabel({ role: user?.role, warehouse: o.warehouse })}`
                : `${displayWarehouseLabel({ role: user?.role, warehouse: o.warehouse })} · ${o.leadDays ?? 0}d`}
            </p>
          </td>
          <td className="px-2 py-1 text-right align-middle font-semibold tabular-nums text-slate-900">
            {formatCurrency(unitCost)}
          </td>
          <td className="px-2 py-1 text-right align-middle tabular-nums text-slate-700">
            {o.stock}
          </td>
          {showFreight ? (
            <td className="px-2 py-1 align-middle">
              <Badge variant={freightVariant(o)}>{freightLabel(o)}</Badge>
            </td>
          ) : null}
          <td className="px-2 py-1 text-right align-middle">
            {rowSelected ? (
              <Button
                size="sm"
                variant="ghost"
                className="h-7 px-2 text-[11px]"
                disabled={!canClear}
                onClick={() => onClearSelection?.(o)}
              >
                Cancelar
              </Button>
            ) : (
              <Button
                size="sm"
                variant="secondary"
                className="h-7 px-2 text-[11px]"
                disabled={!canSelect}
                onClick={() => onSelectOffer?.(o)}
              >
                Seleccionar
              </Button>
            )}
          </td>
        </tr>
      )
    })

  if (variant === 'table') {
    return (
      <div className="space-y-2">
        {notFoundBanner}
        <div className="overflow-hidden rounded-md border border-slate-200">
          {loading && comparison?.demoMode && (
            <p className="flex items-center gap-2 border-b border-slate-100 bg-amber-50/60 px-2 py-1.5 text-[11px] text-amber-800">
              <InlineBusy size="xs" />
              Ofertas simuladas en servidor — no usan APIs de mayoristas reales.
            </p>
          )}
          {isVariantList && (
            <p className="border-b border-indigo-100 bg-indigo-50/70 px-2 py-1 text-[11px] text-indigo-800">
              SKU incompleto <strong className="font-mono">{requestedSku}</strong>: {offers.length}{' '}
              coincidencias CT. Elige una.
            </p>
          )}
          {showFreight && bestSummary ? (
            <div className="border-b border-emerald-100 px-2 py-2">{bestSummary}</div>
          ) : null}
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px] text-xs">
              <thead className="border-b bg-slate-50 text-slate-500">
                <tr>
                  <th className="px-2 py-1 text-left font-medium">
                    {isVariantList ? 'Producto / clave' : 'Mayorista'}
                  </th>
                  <th className="px-2 py-1 text-right font-medium">Costo</th>
                  <th className="px-2 py-1 text-right font-medium">Inv.</th>
                  {showFreight ? (
                    <th className="px-2 py-1 text-left font-medium">Flete</th>
                  ) : null}
                  <th className="px-2 py-1 text-right font-medium">Acciones</th>
                </tr>
              </thead>
              <tbody>{renderTableRows(visibleOffers)}</tbody>
            </table>
          </div>
          {othersToggle}
        </div>
      </div>
    )
  }

  return (
    <div className="mt-3 space-y-2">
      {notFoundBanner}
      <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
            Comparador — {productLabel || sku}
          </p>
          {comparison?.demoMode && <Badge variant="warning">Modo demo</Badge>}
        </div>
        {bestSummary}
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {visibleOffers.map((o, index) => {
            const isBest = isOfferBest(o)
            const unitCost = o.unitCost ?? o.cost
            return (
              <button
                key={offerKey(o, index)}
                type="button"
                onClick={() => onSelectOffer?.(o)}
                className={`rounded-lg border bg-white p-3 text-left text-sm transition hover:shadow-sm ${
                  isBest
                    ? 'border-emerald-400 ring-1 ring-emerald-400'
                    : 'border-slate-200'
                } ${onSelectOffer ? 'cursor-pointer' : 'cursor-default'}`}
              >
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="font-medium">{o.description || offerLabel(o)}</span>
                  <div className="flex flex-wrap gap-1">
                    <Badge variant={availabilityVariant(o.availabilityType)}>
                      {availabilityLabel(o.availabilityType)}
                    </Badge>
                    {isBest && <Badge variant="success">Mejor opción</Badge>}
                    {showFreight && (
                      <Badge variant={freightVariant(o)}>{freightLabel(o)}</Badge>
                    )}
                  </div>
                </div>
                {o.supplierPartNumber && (
                  <p className="mt-0.5 font-mono text-xs text-slate-500">
                    Referencia del mayorista: {o.supplierPartNumber}
                  </p>
                )}
                <p className="mt-1 text-lg font-semibold">{formatCurrency(unitCost)}</p>
                {unitCost !== o.cost && (
                  <p className="text-xs text-slate-500">Total: {formatCurrency(o.cost)}</p>
                )}
                <p className="text-xs text-slate-500">
                  Stock: {o.stock} ·{' '}
                  {displayWarehouseLabel({ role: user?.role, warehouse: o.warehouse })} ·{' '}
                  {o.leadDays ?? 0} días
                  {o.etaAt ? ` · ETA ${formatDateTime(o.etaAt)}` : ''}
                </p>
                {showFreight && (
                  <p className="mt-1 text-xs text-slate-600">
                    Flete: <span className="font-medium">{freightLabel(o)}</span>
                  </p>
                )}
                {o.performanceScore !== undefined && (
                  <p className="mt-1 text-xs text-slate-600">
                    Desempeño: {Math.round(o.performanceScore * 100)}%
                  </p>
                )}
                {o.reasons && o.reasons.length > 0 && (
                  <div className="mt-2 flex flex-wrap gap-1">
                    {o.reasons.map((r) => (
                      <Badge key={r} variant="muted">
                        {r}
                      </Badge>
                    ))}
                  </div>
                )}
              </button>
            )
          })}
        </div>
        {othersToggle}
      </div>
    </div>
  )
}
