import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import { Check, GripHorizontal, Warehouse, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import {
  type WarehouseOptionGroup,
  type WarehouseSelectOption,
  mergeWarehouseOptions,
} from '@/lib/preferred-warehouses'
import { displayWholesalerName } from '@/lib/wholesaler-display'

export type WarehouseByWholesalerOption = {
  value: string
  label: string
  city?: string
  code?: string
  region?: string
}

export type WarehousesByWholesalerGroup = {
  wholesalerCode: string
  wholesalerName: string
  warehouses: WarehouseByWholesalerOption[]
}

/** ¿Está seleccionado este almacén? Respeta preferencias legado (CDMX → todas las sucursales CDMX). */
export function isWarehousePreferred(
  option: WarehouseByWholesalerOption,
  preferred: string[],
): boolean {
  const value = option.value.toUpperCase()
  const region = (option.region ?? '').toUpperCase()
  const set = preferred.map((p) => p.toUpperCase())
  if (set.includes(value)) return true
  if (region && set.includes(region)) return true
  return false
}

/**
 * Alterna una sucursal. Si había preferencia de región (legado), la expande a
 * sucursales de esa región antes de quitar/poner la actual.
 */
export function toggleWarehousePreference(
  option: WarehouseByWholesalerOption,
  preferred: string[],
  groupWarehouses: WarehouseByWholesalerOption[],
): string[] {
  const value = option.value.toUpperCase()
  const region = (option.region ?? '').toUpperCase()
  let next = preferred.map((p) => p.toUpperCase())

  if (region && next.includes(region)) {
    const siblings = groupWarehouses
      .filter((w) => (w.region ?? '').toUpperCase() === region)
      .map((w) => w.value.toUpperCase())
    next = [...next.filter((p) => p !== region), ...siblings]
  }

  if (next.includes(value)) {
    next = next.filter((p) => p !== value)
  } else {
    next = [...next, value]
  }

  if (next.length === 0) {
    const fallback = groupWarehouses[0]?.value.toUpperCase() ?? 'D2A'
    return [fallback]
  }

  return next
}

/** Marca todos los almacenes del mayorista activo (mantiene los de otros mayoristas). */
export function selectAllWarehousesForGroup(
  group: WarehousesByWholesalerGroup,
  preferred: string[],
): string[] {
  const groupValues = new Set(
    group.warehouses.map((w) => w.value.toUpperCase()),
  )
  const regions = new Set(
    group.warehouses
      .map((w) => (w.region ?? '').toUpperCase())
      .filter((r) => r !== ''),
  )

  const kept = preferred
    .map((p) => p.toUpperCase())
    .filter((p) => !groupValues.has(p) && !regions.has(p))

  const added = group.warehouses.map((w) => w.value.toUpperCase())
  return [...new Set([...kept, ...added])]
}

/** Quita la selección del mayorista activo (conserva otros mayoristas). */
export function clearWarehousesForGroup(
  group: WarehousesByWholesalerGroup,
  preferred: string[],
  allGroups: WarehousesByWholesalerGroup[],
): string[] {
  const groupValues = new Set(
    group.warehouses.map((w) => w.value.toUpperCase()),
  )
  const regions = new Set(
    group.warehouses
      .map((w) => (w.region ?? '').toUpperCase())
      .filter((r) => r !== ''),
  )

  const next = preferred
    .map((p) => p.toUpperCase())
    .filter((p) => !groupValues.has(p) && !regions.has(p))

  if (next.length > 0) {
    return next
  }

  const other = allGroups.find((g) => g.wholesalerCode !== group.wholesalerCode)
  const fallback =
    other?.warehouses[0]?.value ?? group.warehouses[0]?.value ?? 'D2A'
  return [String(fallback).toUpperCase()]
}

type PreferredWarehousesByWholesalerProps = {
  groups: WarehousesByWholesalerGroup[]
  preferredWarehouses: string[]
  onChange: (next: string[]) => void
  disabled?: boolean
  /** compacto = cotización/solicitud; confort = configuración */
  density?: 'compact' | 'comfortable'
  role?: string | null
}

export function PreferredWarehousesByWholesaler({
  groups,
  preferredWarehouses,
  onChange,
  disabled = false,
  density = 'comfortable',
  role = null,
}: PreferredWarehousesByWholesalerProps) {
  const [open, setOpen] = useState(false)
  const [minimized, setMinimized] = useState(false)
  const [activeCode, setActiveCode] = useState<string>(() => groups[0]?.wholesalerCode ?? 'CT')
  const [offset, setOffset] = useState({ x: 0, y: 0 })
  const [dragging, setDragging] = useState(false)
  const dragRef = useRef<{
    pointerId: number
    startX: number
    startY: number
    origX: number
    origY: number
  } | null>(null)

  useEffect(() => {
    if (!open || minimized) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setMinimized(true)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, minimized])

  useEffect(() => {
    if (groups.length === 0) return
    if (!groups.some((g) => g.wholesalerCode === activeCode)) {
      setActiveCode(groups[0].wholesalerCode)
    }
  }, [groups, activeCode])

  if (groups.length === 0) {
    return (
      <p className="text-xs text-slate-500">No hay almacenes disponibles.</p>
    )
  }

  const activeGroup =
    groups.find((g) => g.wholesalerCode === activeCode) ?? groups[0]
  const btnSize = density === 'compact' ? 'sm' : 'md'

  const openModal = () => {
    setOffset({ x: 0, y: 0 })
    setOpen(true)
    setMinimized(false)
  }

  const closeModal = () => {
    setOpen(false)
    setMinimized(false)
    setOffset({ x: 0, y: 0 })
    dragRef.current = null
    setDragging(false)
  }

  const onDragHandlePointerDown = (e: ReactPointerEvent<HTMLElement>) => {
    if (disabled) return
    const target = e.target as HTMLElement
    if (target.closest('button')) return
    dragRef.current = {
      pointerId: e.pointerId,
      startX: e.clientX,
      startY: e.clientY,
      origX: offset.x,
      origY: offset.y,
    }
    setDragging(true)
    e.currentTarget.setPointerCapture(e.pointerId)
  }

  const onDragHandlePointerMove = (e: ReactPointerEvent<HTMLElement>) => {
    const drag = dragRef.current
    if (!drag || drag.pointerId !== e.pointerId) return
    setOffset({
      x: drag.origX + (e.clientX - drag.startX),
      y: drag.origY + (e.clientY - drag.startY),
    })
  }

  const onDragHandlePointerUp = (e: ReactPointerEvent<HTMLElement>) => {
    const drag = dragRef.current
    if (!drag || drag.pointerId !== e.pointerId) return
    dragRef.current = null
    setDragging(false)
    try {
      e.currentTarget.releasePointerCapture(e.pointerId)
    } catch {
      /* ya liberado */
    }
  }

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-2">
        <Button
          type="button"
          variant="secondary"
          size={btnSize}
          disabled={disabled}
          onClick={openModal}
        >
          <Warehouse className="mr-1.5 h-4 w-4" aria-hidden />
          Ver almacenes preferidos
        </Button>
        <span className="text-xs text-slate-500">
          {preferredWarehouses.length} seleccionado
          {preferredWarehouses.length === 1 ? '' : 's'}
        </span>
      </div>

      {/* Mini barra flotante al minimizar */}
      {open && minimized && (
        <div className="fixed bottom-5 left-1/2 z-50 -translate-x-1/2">
          <button
            type="button"
            onClick={() => setMinimized(false)}
            className="flex items-center gap-2 rounded-full border border-indigo-200 bg-white px-4 py-2 text-sm font-medium text-indigo-800 shadow-lg hover:bg-indigo-50"
          >
            <Warehouse className="h-4 w-4" aria-hidden />
            Almacenes preferidos ({preferredWarehouses.length})
            <span className="text-xs font-normal text-indigo-600">Ampliar</span>
          </button>
        </div>
      )}

      {/* Modal flotante centrado (arrastrable por el encabezado) */}
      {open && !minimized && (
        <div
          className="fixed inset-0 z-50 bg-slate-900/45"
          role="presentation"
          onClick={() => setMinimized(true)}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="warehouses-modal-title"
            className="fixed left-1/2 top-1/2 flex max-h-[85vh] w-[min(42rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"
            style={{
              transform: `translate(calc(-50% + ${offset.x}px), calc(-50% + ${offset.y}px))`,
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <header
              className={`flex select-none items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 ${
                dragging ? 'cursor-grabbing' : 'cursor-grab'
              }`}
              onPointerDown={onDragHandlePointerDown}
              onPointerMove={onDragHandlePointerMove}
              onPointerUp={onDragHandlePointerUp}
              onPointerCancel={onDragHandlePointerUp}
              title="Arrastra para mover"
            >
              <div className="flex min-w-0 items-start gap-2">
                <GripHorizontal
                  className="mt-0.5 h-4 w-4 shrink-0 text-slate-400"
                  aria-hidden
                />
                <div>
                  <h2
                    id="warehouses-modal-title"
                    className="text-base font-semibold text-slate-900"
                  >
                    Almacenes preferidos
                  </h2>
                  <p className="mt-0.5 text-xs text-slate-500">
                    Elige el mayorista y marca almacenes. Usa Seleccionar todos /
                    Quitar selección del tab activo. Arrastra para mover.
                  </p>
                </div>
              </div>
              <div className="flex shrink-0 items-center gap-1">
                <button
                  type="button"
                  className="rounded-lg px-2 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100"
                  onClick={() => setMinimized(true)}
                  title="Minimizar"
                >
                  Minimizar
                </button>
                <button
                  type="button"
                  className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                  onClick={closeModal}
                  aria-label="Cerrar"
                >
                  <X className="h-5 w-5" />
                </button>
              </div>
            </header>

            {/* Tabs mayoristas */}
            <div className="flex gap-1 border-b border-slate-100 px-3 pt-2">
              {groups.map((group) => {
                const heading = displayWholesalerName({
                  role,
                  code: group.wholesalerCode,
                  name: group.wholesalerName,
                })
                const selectedCount = group.warehouses.filter((w) =>
                  isWarehousePreferred(w, preferredWarehouses),
                ).length
                const active = group.wholesalerCode === activeGroup.wholesalerCode
                return (
                  <button
                    key={group.wholesalerCode}
                    type="button"
                    className={`rounded-t-lg px-3 py-2 text-sm transition ${
                      active
                        ? 'border border-b-white border-slate-200 bg-white font-semibold text-indigo-700'
                        : 'text-slate-600 hover:bg-slate-50'
                    }`}
                    onClick={() => setActiveCode(group.wholesalerCode)}
                  >
                    {heading}
                    <span className="ml-1.5 text-[11px] font-normal text-slate-500">
                      {selectedCount}/{group.warehouses.length}
                    </span>
                  </button>
                )
              })}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-3 py-2">
              <p className="text-[11px] text-slate-500">
                {displayWholesalerName({
                  role,
                  code: activeGroup.wholesalerCode,
                  name: activeGroup.wholesalerName,
                })}
                :{' '}
                {
                  activeGroup.warehouses.filter((w) =>
                    isWarehousePreferred(w, preferredWarehouses),
                  ).length
                }
                /{activeGroup.warehouses.length}
              </p>
              <div className="flex flex-wrap gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant="secondary"
                  disabled={disabled || activeGroup.warehouses.length === 0}
                  onClick={() => {
                    if (disabled) return
                    onChange(
                      selectAllWarehousesForGroup(
                        activeGroup,
                        preferredWarehouses,
                      ),
                    )
                  }}
                >
                  Seleccionar todos
                </Button>
                <Button
                  type="button"
                  size="sm"
                  variant="secondary"
                  disabled={
                    disabled ||
                    activeGroup.warehouses.every(
                      (w) => !isWarehousePreferred(w, preferredWarehouses),
                    )
                  }
                  onClick={() => {
                    if (disabled) return
                    onChange(
                      clearWarehousesForGroup(
                        activeGroup,
                        preferredWarehouses,
                        groups,
                      ),
                    )
                  }}
                >
                  Quitar selección
                </Button>
              </div>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto p-3">
              <ul className="space-y-1.5">
                {activeGroup.warehouses.map((w) => {
                  const checked = isWarehousePreferred(w, preferredWarehouses)
                  return (
                    <li key={`${activeGroup.wholesalerCode}-${w.value}`}>
                      <button
                        type="button"
                        disabled={disabled}
                        onClick={() => {
                          if (disabled) return
                          onChange(
                            toggleWarehousePreference(
                              w,
                              preferredWarehouses,
                              activeGroup.warehouses,
                            ),
                          )
                        }}
                        className={`flex w-full items-start gap-3 rounded-lg border px-3 py-2.5 text-left transition ${
                          checked
                            ? 'border-indigo-300 bg-indigo-50'
                            : 'border-slate-200 bg-white hover:border-slate-300'
                        } ${disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'}`}
                      >
                        <span
                          className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border ${
                            checked
                              ? 'border-indigo-500 bg-indigo-600 text-white'
                              : 'border-slate-300 bg-white text-transparent'
                          }`}
                          aria-hidden
                        >
                          <Check className="h-3.5 w-3.5" />
                        </span>
                        <span className="min-w-0 flex-1">
                          <span className="block text-sm font-medium text-slate-800">
                            {w.label}
                          </span>
                          {(w.city || w.code || w.region) && (
                            <span className="mt-0.5 block text-[11px] text-slate-500">
                              {[
                                w.city && `Ciudad ${w.city}`,
                                w.code && `Código ${w.code}`,
                              ]
                                .filter(Boolean)
                                .join(' · ')}
                            </span>
                          )}
                        </span>
                      </button>
                    </li>
                  )
                })}
              </ul>
            </div>

            <footer className="flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-3">
              <p className="text-xs text-slate-500">
                {preferredWarehouses.length} almacén
                {preferredWarehouses.length === 1 ? '' : 'es'} marcado
                {preferredWarehouses.length === 1 ? '' : 's'}
              </p>
              <div className="flex gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant="secondary"
                  onClick={() => setMinimized(true)}
                >
                  Minimizar
                </Button>
                <Button type="button" size="sm" onClick={closeModal}>
                  Listo
                </Button>
              </div>
            </footer>
          </div>
        </div>
      )}
    </div>
  )
}

/** Fallback local si el API aún no envía grupos (CT por región + CVA). */
export function fallbackWarehouseGroups(
  flat?: WarehouseSelectOption[] | null,
): WarehousesByWholesalerGroup[] {
  const options = mergeWarehouseOptions(flat)
  return [
    {
      wholesalerCode: 'CT',
      wholesalerName: 'CT Internacional',
      warehouses: options.map((o) => ({
        value: o.value,
        label: o.label,
        region: o.value,
        city: o.label,
        code: o.value,
      })),
    },
    {
      wholesalerCode: 'CVA',
      wholesalerName: 'Grupo CVA',
      warehouses: [
        { value: 'CVA-46', label: 'CEDIS Guadalajara (CVA-46)', city: 'CEDIS Guadalajara', code: '46', region: 'CEDIS_GDL' },
        { value: 'CVA-51', label: 'CEDIS CDMX Centro Sur (CVA-51)', city: 'CEDIS CDMX Centro Sur', code: '51', region: 'CEDIS_CDMX' },
        { value: 'CVA-54', label: 'CEDIS Monterrey (CVA-54)', city: 'CEDIS Monterrey', code: '54', region: 'CEDIS_MTY' },
        { value: 'CVA-23', label: 'Aguascalientes (CVA-23)', city: 'Aguascalientes', code: '23', region: 'AGS' },
        { value: 'CVA-19', label: 'Cancún (CVA-19)', city: 'Cancún', code: '19', region: 'CUN' },
        { value: 'CVA-24', label: 'CDMX (CVA-24)', city: 'CDMX', code: '24', region: 'CDMX' },
        { value: 'CVA-27', label: 'Chihuahua (CVA-27)', city: 'Chihuahua', code: '27', region: 'CHI' },
        { value: 'CVA-5', label: 'Culiacán (CVA-5)', city: 'Culiacán', code: '5', region: 'CLN' },
        { value: 'CVA-1', label: 'Guadalajara (CVA-1)', city: 'Guadalajara', code: '1', region: 'GDL' },
        { value: 'CVA-14', label: 'Hermosillo (CVA-14)', city: 'Hermosillo', code: '14', region: 'HMO' },
        { value: 'CVA-4', label: 'León (CVA-4)', city: 'León', code: '4', region: 'LEO' },
        { value: 'CVA-18', label: 'Mérida (CVA-18)', city: 'Mérida', code: '18', region: 'MID' },
        { value: 'CVA-9', label: 'Monterrey (CVA-9)', city: 'Monterrey', code: '9', region: 'MTY' },
        { value: 'CVA-3', label: 'Morelia (CVA-3)', city: 'Morelia', code: '3', region: 'MOR' },
        { value: 'CVA-31', label: 'Oaxaca (CVA-31)', city: 'Oaxaca', code: '31', region: 'OAX' },
        { value: 'CVA-40', label: 'Pachuca (CVA-40)', city: 'Pachuca', code: '40', region: 'PAC' },
        { value: 'CVA-10', label: 'Puebla (CVA-10)', city: 'Puebla', code: '10', region: 'PUE' },
        { value: 'CVA-6', label: 'Querétaro (CVA-6)', city: 'Querétaro', code: '6', region: 'QRO' },
        { value: 'CVA-8', label: 'Tepic (CVA-8)', city: 'Tepic', code: '8', region: 'TPC' },
        { value: 'CVA-33', label: 'Tijuana (CVA-33)', city: 'Tijuana', code: '33', region: 'TIJ' },
        { value: 'CVA-29', label: 'Toluca (CVA-29)', city: 'Toluca', code: '29', region: 'TOL' },
        { value: 'CVA-7', label: 'Torreón (CVA-7)', city: 'Torreón', code: '7', region: 'TRN' },
        { value: 'CVA-13', label: 'Tuxtla (CVA-13)', city: 'Tuxtla', code: '13', region: 'TXA' },
        { value: 'CVA-11', label: 'Veracruz (CVA-11)', city: 'Veracruz', code: '11', region: 'VER' },
        { value: 'CVA-12', label: 'Villahermosa (CVA-12)', city: 'Villahermosa', code: '12', region: 'VHA' },
      ],
    },
  ]
}

/** Compat: sigue existiendo groupWarehouseOptions para otros usos. */
export function groupsFromApiOrFallback(
  fromApi?: WarehousesByWholesalerGroup[] | null,
  flat?: WarehouseSelectOption[] | null,
): WarehousesByWholesalerGroup[] {
  if (fromApi && fromApi.length > 0) {
    return fromApi.map((g) => ({
      wholesalerCode: g.wholesalerCode,
      wholesalerName: g.wholesalerName,
      warehouses: (g.warehouses ?? []).map((w) => ({
        value: String(w.value).toUpperCase(),
        label: w.label,
        city: w.city,
        code: w.code,
        region: w.region ? String(w.region).toUpperCase() : undefined,
      })),
    }))
  }
  return fallbackWarehouseGroups(flat)
}

export type { WarehouseOptionGroup }
