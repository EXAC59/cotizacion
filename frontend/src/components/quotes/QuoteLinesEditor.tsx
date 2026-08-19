import { useEffect, useMemo, useRef, useState } from 'react'
import { ChevronDown, ChevronUp, Trash2, Wand2 } from 'lucide-react'
import { CtSkuAutocompleteInput } from '@/components/quotes/CtSkuAutocompleteInput'
import { ProductLineComparator } from '@/components/quotes/ProductLineComparator'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { InlineBusy } from '@/components/ui/LoadingState'
import {
  DEFAULT_MARGIN,
  lineProfitForLine,
  recalcLine,
  recalcLineFromSalePrice,
} from '@/lib/calculations'
import { formatCurrency } from '@/lib/format'
import { preventNegativeNumberKey, sanitizePositiveDecimalInput, sanitizeQuantityInput } from '@/lib/quantity-input'
import { compareBatch } from '@/lib/wholesalers-api'
import type { QuoteLine, WholesalerOffer } from '@/types'

export function QuoteLinesEditor({
  lines,
  globalMargin,
  preferredWarehouse = 'CDMX',
  quoteId,
  autoApplyBest: _autoApplyBest = false,
  comparatorEnabled = true,
  showComparator = true,
  readOnly = false,
  readOnlyMargins: _readOnlyMargins = false,
  showProfit = true,
  onChange,
}: {
  lines: QuoteLine[]
  globalMargin: number
  preferredWarehouse?: string
  quoteId?: string
  autoApplyBest?: boolean
  /** false mientras cargan preferencias del comparador (evita abortar el primer disparo). */
  comparatorEnabled?: boolean
  /** false para roles sin inventario (p. ej. ventas): oculta el bloque del comparador. */
  showComparator?: boolean
  readOnly?: boolean
  /** Conservado por compatibilidad; el margen % ya no se bloquea por RBAC. */
  readOnlyMargins?: boolean
  /** Si false, oculta la columna Utilidad (p. ej. rol ventas). */
  showProfit?: boolean
  onChange: (lines: QuoteLine[]) => void
}) {
  void _readOnlyMargins
  /** Con comparador: costo/p.venta vienen de ofertas. Sin él (ventas): captura manual. */
  const pricingFieldsLocked = showComparator
  const marginEditable = !readOnly
  const [activeLineId, setActiveLineId] = useState<string | null>(null)
  const [inventoryCollapsed, setInventoryCollapsed] = useState(false)
  const [quantityDrafts, setQuantityDrafts] = useState<Record<string, string>>({})
  const [marginDrafts, setMarginDrafts] = useState<Record<string, string>>({})
  const [batchApplying, setBatchApplying] = useState(false)
  const [batchMessage, setBatchMessage] = useState<string | null>(null)
  const lastBatchFingerprintRef = useRef<string | null>(null)
  const linesRef = useRef(lines)
  const batchApplyingRef = useRef(batchApplying)

  useEffect(() => {
    linesRef.current = lines
  }, [lines])

  useEffect(() => {
    batchApplyingRef.current = batchApplying
  }, [batchApplying])

  const applyCtSuggestion = (line: QuoteLine, item: {
    clave: string
    partNumber?: string | null
    nombre: string
    descripcion: string
  }) => {
    const label = (item.descripcion || item.nombre).trim()
    // La clave interna del mayorista identifica su producto, pero nunca
    // sustituye el SKU capturado por el usuario.
    updateLine(line.id, {
      ...(label !== '' ? { product: label } : {}),
    })
    setActiveLineId(line.id)
  }

  const applyOffer = (
    lineId: string,
    offer: WholesalerOffer,
    allOffers?: WholesalerOffer[],
  ) => {
    onChange(
      lines.map((l) => {
        if (l.id !== lineId) return l
        const offers = allOffers ?? l.offers
        const marked =
          offers?.map((o) => ({
            ...o,
            isSelected: o.wholesalerId === offer.wholesalerId,
          })) ?? l.offers
        const description = (offer.description ?? '').trim()
        return recalcLine(
          {
            ...l,
            product: description !== '' ? description.replace(/\s*\[[^\]]+\]\s*$/, '').trim() || description : l.product,
            cost: offer.cost,
            warehouse: offer.warehouse || l.warehouse,
            selectedWholesalerId: offer.wholesalerId,
            offers: marked,
          },
          l.usesGlobalMargin === false ? undefined : globalMargin,
        )
      }),
    )
  }

  const updateLine = (id: string, patch: Partial<QuoteLine>) => {
    onChange(
      lines.map((l) => {
        if (l.id !== id) return l
        return recalcLine(
          { ...l, ...patch },
          l.usesGlobalMargin === false ? undefined : globalMargin,
        )
      }),
    )
  }

  const clearOfferSelection = (lineId: string, allOffers?: WholesalerOffer[]) => {
    onChange(
      lines.map((l) => {
        if (l.id !== lineId) return l
        const offers =
          allOffers?.map((o) => ({
            ...o,
            isSelected: false,
          })) ??
          l.offers?.map((o) => ({
            ...o,
            isSelected: false,
          }))
        return {
          ...l,
          selectedWholesalerId: undefined,
          offers,
        }
      }),
    )
  }

  const updateMargin = (id: string, marginPercent: number) => {
    onChange(
      lines.map((l) => {
        if (l.id !== id) return l
        // Permite 0 y rechaza NaN/negativos (antes `|| DEFAULT_MARGIN` forzaba 30 y
        // hacía imposible borrar el campo o capturar margen 0).
        const margin =
          Number.isFinite(marginPercent) && marginPercent >= 0
            ? marginPercent
            : DEFAULT_MARGIN
        const usesGlobalMargin = Math.abs(margin - globalMargin) < 0.01
        return recalcLine(
          { ...l, marginPercent: margin, usesGlobalMargin },
          usesGlobalMargin ? globalMargin : undefined,
        )
      }),
    )
  }

  const updateSalePrice = (id: string, salePrice: number) => {
    onChange(
      lines.map((l) => (l.id === id ? recalcLineFromSalePrice(l, salePrice) : l)),
    )
  }

  const removeLine = (id: string) => {
    onChange(lines.filter((l) => l.id !== id))
    if (activeLineId === id) setActiveLineId(null)
  }

  const addLine = () => {
    const line = recalcLine(
      {
        id: `ln${Date.now()}`,
        quantity: 1,
        product: '',
        partNumber: '',
        cost: 0,
        marginPercent: globalMargin,
        salePrice: 0,
        amount: 0,
        warehouse: preferredWarehouse,
        usesGlobalMargin: true,
      },
      globalMargin,
    )
    onChange([...lines, line])
    setActiveLineId(line.id)
  }

  const linesWithSku = useMemo(
    () => lines.filter((l) => l.partNumber.trim() !== ''),
    [lines],
  )

  const batchFingerprint = useMemo(() => {
    if (linesWithSku.length < 3) return null
    return linesWithSku
      .map((l) => `${l.partNumber.trim().toUpperCase()}|${l.quantity}|${preferredWarehouse}`)
      .sort()
      .join('||')
  }, [linesWithSku, preferredWarehouse])

  const effectiveActiveLineId = useMemo(() => {
    if (lines.length === 0) return null
    if (activeLineId && lines.some((l) => l.id === activeLineId)) return activeLineId
    return linesWithSku[0]?.id ?? lines[0]?.id ?? null
  }, [lines, activeLineId, linesWithSku])

  const applyBestToAll = async () => {
    if (readOnly || linesWithSku.length === 0 || batchApplyingRef.current) return
    setBatchApplying(true)
    setBatchMessage(null)
    try {
      const currentLines = linesRef.current
      const skus = currentLines.filter((l) => l.partNumber.trim() !== '')
      const results = await compareBatch(
        skus.map((l) => ({
          partNumber: l.partNumber.trim(),
          quantity: l.quantity,
          preferredWarehouse,
        })),
        preferredWarehouse,
      )

      const bySku = new Map(results.map((r) => [r.partNumber.trim().toUpperCase(), r]))
      let applied = 0
      const next = currentLines.map((line) => {
        const sku = line.partNumber.trim()
        if (sku === '') return line
        const result = bySku.get(sku.toUpperCase())
        const best = result?.best
        if (!best || best.cost <= 0) return line
        applied += 1
        const offers = (result?.offers ?? []).map((o) => ({
          ...o,
          isSelected: o.wholesalerId === best.wholesalerId,
        }))
        const description = (best.description ?? '').trim()
        return recalcLine(
          {
            ...line,
            product: description !== ''
              ? description.replace(/\s*\[[^\]]+\]\s*$/, '').trim() || description
              : line.product,
            cost: best.cost,
            warehouse: best.warehouse || line.warehouse,
            selectedWholesalerId: best.wholesalerId,
            offers,
          },
          line.usesGlobalMargin === false ? undefined : globalMargin,
        )
      })
      onChange(next)
      setBatchMessage(
        applied > 0
          ? `Se aplicó la mejor oferta a ${applied} partida${applied === 1 ? '' : 's'}.`
          : 'No hubo ofertas aplicables para las partidas con SKU.',
      )
      setInventoryCollapsed(false)
    } catch (err) {
      setBatchMessage(err instanceof Error ? err.message : 'Error al comparar en lote')
    } finally {
      setBatchApplying(false)
    }
  }

  useEffect(() => {
    // Ya no se autoaplican mejores precios al detectar partidas con SKU:
    // el usuario elige oferta en «Inventario de los mayoristas» o usa el botón manual.
    if (batchFingerprint === null) {
      lastBatchFingerprintRef.current = null
    }
  }, [batchFingerprint])

  return (
    <div>
      {showComparator && !readOnly && linesWithSku.length > 0 && (
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <Button
            type="button"
            variant="secondary"
            size="sm"
            disabled={batchApplying}
            onClick={() => void applyBestToAll()}
          >
            {batchApplying ? (
              <InlineBusy size="sm" />
            ) : (
              <Wand2 className="h-4 w-4" />
            )}
            Aplicar mejores precios a todas
          </Button>
          {batchMessage && <p className="text-xs text-slate-600">{batchMessage}</p>}
        </div>
      )}
      <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table className="w-full min-w-[960px] table-fixed border-separate border-spacing-0 text-left text-xs">
          <thead className="sticky top-0 z-10 bg-slate-50 text-slate-500">
            <tr>
              <th className="w-16 border-b border-slate-200 px-2 py-2 font-medium">Cant.</th>
              <th className="border-b border-slate-200 px-2 py-2 font-medium">Descripción detallada</th>
              <th className="w-36 border-b border-slate-200 px-2 py-2 font-medium">SKU</th>
              <th className="w-24 border-b border-slate-200 px-2 py-2 font-medium text-right">Costo</th>
              <th className="w-24 border-b border-slate-200 px-2 py-2 font-medium text-right">Margen %</th>
              <th className="w-24 border-b border-slate-200 px-2 py-2 font-medium text-right">P. venta</th>
              {showProfit && (
                <th className="w-24 border-b border-slate-200 px-2 py-2 font-medium text-right">Utilidad</th>
              )}
              <th className="w-24 border-b border-slate-200 px-2 py-2 font-medium text-right">Importe</th>
              <th className="w-36 border-b border-slate-200 px-2 py-2 font-medium">Almacén</th>
              <th className="w-10 border-b border-slate-200 px-1 py-2" />
            </tr>
          </thead>
          <tbody>
            {lines.map((line) => {
              const customMargin = line.usesGlobalMargin === false
              const isActive = effectiveActiveLineId === line.id
              return (
                <tr
                  key={line.id}
                  role="button"
                  tabIndex={0}
                  className={`group cursor-pointer border-b border-slate-100 transition-colors ${
                    isActive
                      ? 'bg-indigo-50/70 shadow-[inset_3px_0_0_0_#6366f1]'
                      : 'hover:bg-slate-50'
                  }`}
                  onClick={() => setActiveLineId(line.id)}
                  onKeyDown={(e) => {
                    // Los eventos de los campos hijos burbujean hasta la fila.
                    // No consumir Espacio/Enter mientras el usuario está escribiendo.
                    if (e.target !== e.currentTarget) return
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault()
                      setActiveLineId(line.id)
                    }
                  }}
                >
                  <td className="px-2 py-1.5 align-middle">
                    <Input
                      type="number"
                      min={1}
                      className="w-full px-2 py-1.5 shadow-sm"
                      placeholder="Cant."
                      value={quantityDrafts[line.id] ?? String(line.quantity)}
                      disabled={readOnly}
                      onKeyDown={preventNegativeNumberKey}
                      onClick={(e) => e.stopPropagation()}
                      onFocus={() => {
                        setActiveLineId(line.id)
                        setQuantityDrafts((prev) => ({
                          ...prev,
                          [line.id]: String(line.quantity),
                        }))
                      }}
                      onChange={(e) => {
                        const val = sanitizeQuantityInput(e.target.value)
                        setQuantityDrafts((prev) => ({ ...prev, [line.id]: val }))
                        if (val !== '' && !Number.isNaN(Number(val))) {
                          updateLine(line.id, { quantity: Number(val) })
                        }
                      }}
                      onBlur={() => {
                        const val = quantityDrafts[line.id]
                        if (val === undefined) return
                        const parsed = Number(val)
                        updateLine(line.id, { quantity: parsed >= 1 ? parsed : 1 })
                        setQuantityDrafts((prev) => {
                          const next = { ...prev }
                          delete next[line.id]
                          return next
                        })
                      }}
                    />
                  </td>
                  <td className="px-2 py-1.5 align-middle">
                    <CtSkuAutocompleteInput
                      className="w-full min-w-0 px-2 py-1.5 shadow-sm"
                      value={line.product}
                      sku={line.partNumber}
                      descripcion={line.product}
                      placeholder="Descripción o producto CT"
                      disabled={readOnly}
                      onFocus={() => setActiveLineId(line.id)}
                      onChange={(text) => updateLine(line.id, { product: text })}
                      onSelect={(item) => applyCtSuggestion(line, item)}
                    />
                  </td>
                  <td className="px-2 py-1.5 align-middle">
                    <CtSkuAutocompleteInput
                      className="w-full min-w-0 px-2 py-1.5 font-mono text-xs shadow-sm"
                      value={line.partNumber}
                      sku={line.partNumber}
                      descripcion={line.product}
                      disabled={readOnly}
                      onFocus={() => setActiveLineId(line.id)}
                      onChange={(sku) => updateLine(line.id, { partNumber: sku })}
                      onSelect={(item) => applyCtSuggestion(line, item)}
                    />
                  </td>
                  <td className="px-2 py-1.5 align-middle">
                    <Input
                      type="number"
                      min={0}
                      step={0.01}
                      className="w-full bg-slate-50 px-2 py-1.5 text-right shadow-sm"
                      placeholder="0.00"
                      value={line.cost}
                      disabled={readOnly || pricingFieldsLocked}
                      readOnly={pricingFieldsLocked}
                      title="Se asigna al seleccionar oferta del comparador"
                      onKeyDown={preventNegativeNumberKey}
                      onClick={(e) => e.stopPropagation()}
                      onFocus={() => setActiveLineId(line.id)}
                      onChange={(e) => {
                        if (pricingFieldsLocked) return
                        const val = sanitizePositiveDecimalInput(e.target.value)
                        updateLine(line.id, { cost: Number(val) || 0 })
                      }}
                    />
                  </td>
                  <td className="px-2 py-1.5 align-middle">
                    <div className="flex items-center justify-end gap-1">
                      <Input
                        type="text"
                        inputMode="decimal"
                        className={`w-full px-2 py-1.5 text-right shadow-sm ${
                          marginEditable ? 'bg-white' : 'bg-slate-50'
                        }`}
                        placeholder="30"
                        value={marginDrafts[line.id] ?? String(line.marginPercent ?? DEFAULT_MARGIN)}
                        disabled={!marginEditable}
                        title={
                          line.cost > 0
                            ? 'Margen de la partida (editable)'
                            : 'Sin costo: asigna una oferta para calcular el precio'
                        }
                        onKeyDown={preventNegativeNumberKey}
                        onClick={(e) => e.stopPropagation()}
                        onFocus={() => {
                          setActiveLineId(line.id)
                          setMarginDrafts((prev) => ({
                            ...prev,
                            [line.id]: String(line.marginPercent ?? DEFAULT_MARGIN),
                          }))
                        }}
                        onChange={(e) => {
                          if (!marginEditable) return
                          const val = sanitizePositiveDecimalInput(e.target.value)
                          setMarginDrafts((prev) => ({ ...prev, [line.id]: val }))
                          if (val !== '' && !Number.isNaN(Number(val))) {
                            updateMargin(line.id, Number(val))
                          }
                        }}
                        onBlur={() => {
                          const val = marginDrafts[line.id]
                          if (val === undefined) return
                          const parsed = Number(val)
                          // Vacío/borrado → vuelve al margen por defecto (no 0 accidental).
                          updateMargin(line.id, val.trim() === '' ? DEFAULT_MARGIN : parsed)
                          setMarginDrafts((prev) => {
                            const next = { ...prev }
                            delete next[line.id]
                            return next
                          })
                        }}
                      />
                      {customMargin && (
                        <span title="Margen personalizado">
                          <Badge variant="warning">P</Badge>
                        </span>
                      )}
                    </div>
                  </td>
                  <td className="px-2 py-1.5 align-middle">
                    <Input
                      type="number"
                      min={0}
                      step={0.01}
                      className="w-full bg-slate-50 px-2 py-1.5 text-right font-medium shadow-sm"
                      placeholder="0.00"
                      value={line.salePrice}
                      disabled={readOnly || pricingFieldsLocked}
                      readOnly={pricingFieldsLocked}
                      title="Se calcula con costo + margen"
                      onKeyDown={preventNegativeNumberKey}
                      onClick={(e) => e.stopPropagation()}
                      onFocus={() => setActiveLineId(line.id)}
                      onChange={(e) => {
                        if (pricingFieldsLocked) return
                        const val = sanitizePositiveDecimalInput(e.target.value)
                        updateSalePrice(line.id, Number(val) || 0)
                      }}
                    />
                  </td>
                  {showProfit && (
                    <td className="px-2 py-1.5 text-right align-middle text-emerald-700 tabular-nums">
                      {formatCurrency(lineProfitForLine(line))}
                    </td>
                  )}
                  <td className="px-2 py-1.5 text-right align-middle font-semibold tabular-nums text-slate-900">
                    {formatCurrency(line.amount)}
                  </td>
                  <td className="px-2 py-1.5 align-middle">
                    <Input
                      className="w-full min-w-0 bg-slate-50 px-2 py-1.5 text-xs shadow-sm"
                      placeholder="Almacén"
                      value={line.warehouse}
                      disabled
                      readOnly
                      title="Ciudad / sucursal del mayorista (se asigna al seleccionar oferta)"
                      onClick={(e) => e.stopPropagation()}
                    />
                  </td>
                  <td className="px-1 py-1.5 align-middle">
                    {!readOnly && (
                      <Button
                        variant="ghost"
                        size="sm"
                        className="opacity-70 transition-opacity group-hover:opacity-100"
                        title="Eliminar partida"
                        onClick={(e) => {
                          e.stopPropagation()
                          removeLine(line.id)
                        }}
                      >
                        <Trash2 className="h-4 w-4 text-red-500" />
                      </Button>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
        <div className="border-t border-slate-100 bg-slate-50/50 px-3 py-2">
          {!readOnly && (
            <Button variant="secondary" size="sm" onClick={addLine}>
              + Agregar partida
            </Button>
          )}
        </div>
      </div>

      {showComparator ? (
      <div className="mt-2 rounded-lg border border-slate-200 bg-white">
        <div className="flex items-center justify-between border-b border-slate-100 px-3 py-1.5">
          <h4 className="text-[11px] font-semibold uppercase tracking-wide text-slate-700">
            Inventario de los mayoristas
          </h4>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => setInventoryCollapsed((prev) => !prev)}
          >
            {inventoryCollapsed ? (
              <>
                <ChevronDown className="h-4 w-4" />
                Maximizar
              </>
            ) : (
              <>
                <ChevronUp className="h-4 w-4" />
                Minimizar
              </>
            )}
          </Button>
        </div>
        {!inventoryCollapsed && (
          <div className="space-y-2 p-2 sm:p-3">
            {linesWithSku.length === 0 ? (
              <p className="text-xs text-slate-500">
                Captura SKU en las partidas para ver ofertas de bodega y la descripción de cada
                producto.
              </p>
            ) : (
              linesWithSku.map((line, index) => {
                const sku = line.partNumber.trim()
                const label = (line.product || sku).trim()
                return (
                  <div
                    key={line.id}
                    className={`rounded-md border px-2 py-1.5 ${
                      effectiveActiveLineId === line.id
                        ? 'border-indigo-300 bg-indigo-50/30'
                        : 'border-slate-200 bg-white'
                    }`}
                  >
                    <div className="mb-1 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                      <span className="shrink-0 font-semibold uppercase tracking-wide text-slate-500">
                        #{index + 1}
                      </span>
                      <span className="shrink-0 text-slate-500">SKU solicitado:</span>
                      <span className="shrink-0 font-mono font-semibold text-slate-900">{sku}</span>
                      <span className="min-w-0 truncate text-slate-600" title={label}>
                        {label}
                      </span>
                    </div>
                    <ProductLineComparator
                      variant="table"
                      partNumber={sku}
                      quantity={line.quantity}
                      preferredWarehouse={preferredWarehouse}
                      productLabel={label}
                      enabled={comparatorEnabled}
                      context={{
                        screen: 'quotes',
                        lineId: line.id,
                        quoteId,
                      }}
                      autoApplyBest={false}
                      selectedWholesalerId={line.selectedWholesalerId}
                      onSelectOffer={(offer, allOffers) => {
                        setActiveLineId(line.id)
                        applyOffer(line.id, offer, allOffers)
                      }}
                      onClearSelection={(_, allOffers) => clearOfferSelection(line.id, allOffers)}
                      onBestApplied={undefined}
                    />
                  </div>
                )
              })
            )}
          </div>
        )}
      </div>
      ) : null}
    </div>
  )
}
