import { useEffect, useId, useRef, useState } from 'react'
import { Input } from '@/components/ui/Input'
import { InlineBusy } from '@/components/ui/LoadingState'
import {
  autocompleteCtProducts,
  autocompleteCvaProducts,
  type CtAutocompleteItem,
} from '@/lib/wholesalers-api'

/**
 * Autocomplete opcional de catálogo CT/CVA.
 * - Escribir SKU/descripción es libre.
 * - Clic en sugerencia aplica el producto por SKU (numParte), no por clave CT.
 * - Enter solo elige si hubo navegación con flechas.
 * - Al salir del campo (blur): si CT/CVA tienen exactamente 1 producto,
 *   completa su descripción sin modificar el SKU escrito.
 */
export function CtSkuAutocompleteInput({
  value,
  sku = '',
  descripcion = '',
  disabled = false,
  placeholder = 'SKU / No. parte',
  className = 'w-40 font-mono text-xs',
  onChange,
  onSelect,
  onFocus,
}: {
  value: string
  /** SKU de la partida (para filtrar junto con descripción). */
  sku?: string
  /** Descripción de la partida (para filtrar junto con SKU). */
  descripcion?: string
  disabled?: boolean
  placeholder?: string
  className?: string
  onChange: (value: string) => void
  onSelect: (item: CtAutocompleteItem) => void
  onFocus?: () => void
}) {
  const listId = useId()
  const wrapRef = useRef<HTMLDivElement>(null)
  const valueRef = useRef(value)
  const catalogItemsRef = useRef<CtAutocompleteItem[]>([])
  const pendingCatalogRef = useRef<Promise<CtAutocompleteItem[]> | null>(null)
  const pickingRef = useRef(false)
  const skipNextLookupRef = useRef(false)
  const [focused, setFocused] = useState(false)
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<CtAutocompleteItem[]>([])
  const [loading, setLoading] = useState(false)
  const [highlight, setHighlight] = useState(0)
  /** Solo true si el usuario movió el resaltado con flechas (Enter entonces sí elige). */
  const [arrowPicked, setArrowPicked] = useState(false)

  useEffect(() => {
    valueRef.current = value
  }, [value])

  const fillSku = (item: CtAutocompleteItem): string => {
    const part = (item.partNumber ?? '').trim()
    return part !== '' ? part : item.clave
  }

  useEffect(() => {
    const skuQ = sku.trim()
    const descQ = descripcion.trim()
    const valueQ = value.trim()
    const usable =
      skuQ.length >= 2 || descQ.length >= 2 || valueQ.length >= 2

    if (skipNextLookupRef.current) {
      skipNextLookupRef.current = false
      setItems([])
      setOpen(false)
      setLoading(false)
      return
    }

    if (disabled || !usable) {
      setItems([])
      catalogItemsRef.current = []
      pendingCatalogRef.current = null
      setLoading(false)
      return
    }

    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      setLoading(true)
      const req = {
        sku: skuQ,
        descripcion: descQ,
        q: skuQ === '' && descQ === '' ? valueQ : undefined,
        limit: 12,
        signal: controller.signal,
      }
      const request = Promise.allSettled([
        autocompleteCtProducts(req),
        autocompleteCvaProducts(req),
      ]).then((results) => {
          const ct = results[0].status === 'fulfilled' ? results[0].value : []
          const cva = results[1].status === 'fulfilled' ? results[1].value : []
          const merged: CtAutocompleteItem[] = []
          const indexBySku = new Map<string, number>()
          const maxLen = Math.max(ct.length, cva.length)
          for (let i = 0; i < maxLen && merged.length < 12; i++) {
            for (const item of [ct[i], cva[i]]) {
              if (!item || merged.length >= 12) continue
              const comparableSku = (item.partNumber || item.clave)
                .toUpperCase()
                .replace(/[^A-Z0-9]/g, '')
              const key = comparableSku || item.clave
              const existingIndex = indexBySku.get(key)
              if (existingIndex !== undefined) {
                const existing = merged[existingIndex]
                merged[existingIndex] = {
                  ...existing,
                  providers: Array.from(new Set([
                    ...(existing.providers ?? []),
                    ...(item.providers ?? []),
                  ])),
                }
                continue
              }
              indexBySku.set(key, merged.length)
              merged.push(item)
            }
          }
          catalogItemsRef.current = merged
          setItems(merged)
          setHighlight(0)
          setArrowPicked(false)
          // Solo abrir si hay sugerencias reales (no mensaje vacío molesto).
          if (focused && merged.length > 0) setOpen(true)
          else if (merged.length === 0) setOpen(false)
          return merged
        })

      pendingCatalogRef.current = request
      request
        .catch((err: unknown) => {
          if (err instanceof DOMException && err.name === 'AbortError') return []
          setItems([])
          catalogItemsRef.current = []
          setOpen(false)
          return []
        })
        .finally(() => setLoading(false))
    }, 300)

    return () => {
      controller.abort()
      window.clearTimeout(timer)
    }
  }, [value, sku, descripcion, disabled, focused])

  useEffect(() => {
    const onDocClick = (event: MouseEvent) => {
      if (!wrapRef.current?.contains(event.target as Node)) {
        setOpen(false)
        setFocused(false)
      }
    }
    document.addEventListener('mousedown', onDocClick)
    return () => document.removeEventListener('mousedown', onDocClick)
  }, [])

  const pick = (item: CtAutocompleteItem) => {
    pickingRef.current = true
    skipNextLookupRef.current = true
    onSelect(item)
    setOpen(false)
    setItems([])
    setArrowPicked(false)
  }

  const tryAutoFillUniqueCatalogProduct = async () => {
    if (pickingRef.current) {
      pickingRef.current = false
      return
    }
    if (disabled) return

    try {
      if (pendingCatalogRef.current) {
        await pendingCatalogRef.current
      }
    } catch {
      // abort / red: no rellenar
    }

    const bySku = new Map<string, CtAutocompleteItem>()
    for (const item of catalogItemsRef.current) {
      const preferred = fillSku(item)
      const normalized = preferred.toUpperCase().replace(/[^A-Z0-9]/g, '')
      if (normalized === '' || bySku.has(normalized)) continue
      bySku.set(normalized, item)
    }
    if (bySku.size !== 1) return

    const item = bySku.values().next().value as CtAutocompleteItem
    const current = valueRef.current.trim()
    if (current.length < 2) return

    // Una sola coincidencia oficial → completar únicamente el producto.
    onSelect(item)
  }

  const showList = open && focused && items.length > 0

  return (
    <div ref={wrapRef} className="relative">
      <Input
        className={className}
        value={value}
        placeholder={placeholder}
        disabled={disabled}
        role="combobox"
        aria-expanded={showList}
        aria-controls={listId}
        aria-autocomplete="list"
        autoComplete="off"
        onFocus={() => {
          setFocused(true)
          onFocus?.()
          if (items.length > 0) setOpen(true)
        }}
        onBlur={() => {
          window.setTimeout(() => {
            void tryAutoFillUniqueCatalogProduct().finally(() => {
              setFocused(false)
              setOpen(false)
            })
          }, 150)
        }}
        onClick={(e) => e.stopPropagation()}
        onChange={(e) => {
          onChange(e.target.value)
          setArrowPicked(false)
          // No forzar panel vacío; se abre solo cuando lleguen matches.
        }}
        onKeyDown={(e) => {
          if (e.key === 'Escape') {
            setOpen(false)
            return
          }
          if (e.key === 'ArrowDown') {
            if (items.length === 0) return
            e.preventDefault()
            setOpen(true)
            setArrowPicked(true)
            setHighlight((h) => (open ? (h + 1) % items.length : 0))
            return
          }
          if (e.key === 'ArrowUp') {
            if (!open || items.length === 0) return
            e.preventDefault()
            setArrowPicked(true)
            setHighlight((h) => (h - 1 + items.length) % items.length)
            return
          }
          // Enter solo elige si el usuario navegó con flechas; si no, deja escribir normal.
          if (e.key === 'Enter' && open && arrowPicked && items.length > 0) {
            e.preventDefault()
            pick(items[highlight] ?? items[0])
          }
        }}
      />
      {showList && (
        <ul
          id={listId}
          role="listbox"
          className="absolute left-0 z-30 mt-1 max-h-56 w-[min(24rem,75vw)] overflow-auto rounded-lg border border-slate-200 bg-white py-1 text-left shadow-lg"
        >
          {loading && (
            <li className="flex items-center gap-2 px-3 py-1.5 text-[10px] text-slate-400">
              <InlineBusy size="xs" label="Actualizando…" />
            </li>
          )}
          {items.map((item, index) => (
            <li key={`${item.clave}-${item.partNumber ?? ''}-${index}`}>
              <button
                type="button"
                role="option"
                aria-selected={index === highlight}
                className={`flex w-full touch-manipulation flex-col gap-0.5 px-3 py-2 text-left text-xs hover:bg-indigo-50 ${
                  index === highlight && arrowPicked ? 'bg-indigo-50' : ''
                }`}
                onMouseDown={(e) => {
                  e.preventDefault()
                  e.stopPropagation()
                  pickingRef.current = true
                  pick(item)
                }}
                onTouchStart={(e) => {
                  e.preventDefault()
                  e.stopPropagation()
                  pickingRef.current = true
                  pick(item)
                }}
                onClick={() => pick(item)}
              >
                <span className="font-mono font-semibold text-slate-900">
                  {item.partNumber && item.partNumber !== item.clave
                    ? item.partNumber
                    : item.clave}
                </span>
                <span className="line-clamp-2 text-slate-600">
                  {item.nombre || item.descripcion}
                  {item.providers?.length ? ` · ${item.providers.join(' / ')}` : ''}
                  {item.marca ? ` · ${item.marca}` : ''}
                </span>
                {item.partNumber && item.partNumber !== item.clave && (
                  <span className="font-mono text-[10px] text-slate-400">
                    Clave de catálogo: {item.clave}
                  </span>
                )}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
