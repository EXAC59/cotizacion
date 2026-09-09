import { useEffect, useState } from 'react'
import { Send } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Label, Select } from '@/components/ui/Input'
import { InlineBusy } from '@/components/ui/LoadingState'
import { listComprasUsers, type AssignRecipientOption } from '@/lib/assign-to-sales-api'

/** Destino de asignación: solo compras (el flujo hacia ventas se eliminó). */
export type AssignTarget = 'compras'

type TeamCopy = {
  empty: string
  select: string
  loadError: string
  pickError: string
}

const COMPRAS_COPY: TeamCopy = {
  empty: 'Sin usuarios de compras activos',
  select: 'Selecciona compras',
  loadError: 'No se pudieron cargar usuarios de compras.',
  pickError: 'Elige a quién de compras enviar.',
}

export function AssignToSalesPanel({
  disabled,
  onAssign,
  onRecipientChange,
  entityLabel = 'documento',
  saveWithParent = false,
}: {
  disabled?: boolean
  entityLabel?: string
  /** @deprecated Solo compras; se ignora si se pasa otro valor. */
  allowedTargets?: AssignTarget[]
  onAssign?: (recipientId: number, target: AssignTarget) => Promise<void>
  onRecipientChange?: (recipientId: number | null, target: AssignTarget | null) => void
  saveWithParent?: boolean
}) {
  const [options, setOptions] = useState<AssignRecipientOption[]>([])
  const [recipientId, setRecipientId] = useState('')
  const [loadingOptions, setLoadingOptions] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const showOwnButton = Boolean(onAssign) && !saveWithParent
  const copy = COMPRAS_COPY

  useEffect(() => {
    let cancelled = false
    setLoadingOptions(true)
    setRecipientId('')
    onRecipientChange?.(null, null)
    listComprasUsers()
      .then((rows) => {
        if (cancelled) return
        setOptions(rows)
        if (rows.length === 1 && !saveWithParent) {
          const id = String(rows[0].id)
          setRecipientId(id)
          onRecipientChange?.(rows[0].id, 'compras')
        }
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : copy.loadError)
        }
      })
      .finally(() => {
        if (!cancelled) setLoadingOptions(false)
      })

    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const handleAssign = async () => {
    if (!onAssign) return
    const id = Number(recipientId)
    if (!Number.isFinite(id) || id <= 0) {
      setError(copy.pickError)
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      await onAssign(id, 'compras')
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : `No se pudo asignar la ${entityLabel}.`)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-[12rem] flex-1">
          <Label htmlFor="assign-compras-recipient">Persona de compras</Label>
          <Select
            id="assign-compras-recipient"
            value={recipientId}
            disabled={disabled || loadingOptions || submitting || options.length === 0}
            onChange={(e) => {
              const value = e.target.value
              setRecipientId(value)
              const id = Number(value)
              onRecipientChange?.(
                Number.isFinite(id) && id > 0 ? id : null,
                Number.isFinite(id) && id > 0 ? 'compras' : null,
              )
            }}
          >
            <option value="">{loadingOptions ? 'Cargando…' : copy.select}</option>
            {options.map((row) => (
              <option key={row.id} value={row.id}>
                {row.name}
              </option>
            ))}
          </Select>
          {options.length === 0 && !loadingOptions && (
            <p className="mt-1 text-xs text-slate-500">{copy.empty}</p>
          )}
        </div>
        {showOwnButton && (
          <Button
            type="button"
            size="sm"
            disabled={disabled || submitting || loadingOptions || !recipientId}
            onClick={() => void handleAssign()}
          >
            {submitting ? <InlineBusy size="sm" /> : <Send className="h-4 w-4" />}
            Enviar a compras
          </Button>
        )}
      </div>
      {error && <p className="mt-2 text-sm text-red-700">{error}</p>}
      {saveWithParent && (
        <p className="mt-2 text-xs text-slate-500">
          La asignación a compras se confirma al guardar la {entityLabel}.
        </p>
      )}
    </div>
  )
}
