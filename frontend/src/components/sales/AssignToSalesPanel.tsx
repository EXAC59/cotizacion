import { useEffect, useState } from 'react'
import { Send } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Label, Select } from '@/components/ui/Input'
import { InlineBusy } from '@/components/ui/LoadingState'
import {
  listSalespeople,
  type SalespersonOption,
} from '@/lib/assign-to-sales-api'

export function AssignToSalesPanel({
  disabled,
  onAssign,
  onRecipientChange,
  entityLabel = 'documento',
  saveWithParent = false,
}: {
  disabled?: boolean
  entityLabel?: string
  /** Si se pasa, muestra botón propio «Enviar a ventas». */
  onAssign?: (recipientId: number) => Promise<void>
  /** Notifica al padre el vendedor elegido (para Guardar + asignar). */
  onRecipientChange?: (recipientId: number | null) => void
  /** Texto: la asignación ocurre al Guardar del padre (sin botón propio). */
  saveWithParent?: boolean
}) {
  const [options, setOptions] = useState<SalespersonOption[]>([])
  const [recipientId, setRecipientId] = useState('')
  const [loadingOptions, setLoadingOptions] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const showOwnButton = Boolean(onAssign) && !saveWithParent

  useEffect(() => {
    let cancelled = false
    setLoadingOptions(true)
    listSalespeople()
      .then((rows) => {
        if (cancelled) return
        setOptions(rows)
        if (rows.length === 1 && !saveWithParent) {
          const id = String(rows[0].id)
          setRecipientId(id)
          onRecipientChange?.(rows[0].id)
        }
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'No se pudieron cargar vendedores.')
        }
      })
      .finally(() => {
        if (!cancelled) setLoadingOptions(false)
      })

    return () => {
      cancelled = true
    }
    // Solo al montar: cargar lista de vendedores.
    // eslint-disable-next-line react-hooks/exhaustive-deps -- onRecipientChange estable vía setState del padre
  }, [])

  const handleRecipientChange = (value: string) => {
    setRecipientId(value)
    setError(null)
    onRecipientChange?.(value ? Number(value) : null)
  }

  const handleSubmit = async () => {
    if (!onAssign) return
    if (!recipientId) {
      setError('Elige a quién de ventas enviar.')
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      await onAssign(Number(recipientId))
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : `No se pudo enviar la ${entityLabel}.`)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="rounded-xl border border-indigo-200 bg-indigo-50/70 px-4 py-3">
      <p className="text-sm font-medium text-indigo-950">Enviar a ventas</p>
      <p className="mt-1 text-xs text-indigo-800">
        {saveWithParent
          ? `Elige el vendedor. Al Guardar (elaboración o terminada) se reasigna la ${entityLabel}, se cambia el folio a su código y se conservan los datos.`
          : `Elige el vendedor. Se reasigna la ${entityLabel}, se cambia el folio a su código y se conservan los datos ya capturados.`}
      </p>
      <div
        className={
          showOwnButton
            ? 'mt-3 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end'
            : 'mt-3'
        }
      >
        <div>
          <Label htmlFor="assign-to-sales">Enviar a</Label>
          <Select
            id="assign-to-sales"
            className="mt-1"
            value={recipientId}
            disabled={disabled || loadingOptions || submitting || options.length === 0}
            onChange={(e) => handleRecipientChange(e.target.value)}
          >
            <option value="">
              {loadingOptions
                ? 'Cargando…'
                : options.length === 0
                  ? 'Sin vendedores activos'
                  : saveWithParent
                    ? 'Sin reasignar (opcional)'
                    : 'Selecciona vendedor'}
            </option>
            {options.map((person) => (
              <option key={person.id} value={person.id}>
                {person.name}
                {person.folioCode ? ` (${person.folioCode})` : ''}
              </option>
            ))}
          </Select>
        </div>
        {showOwnButton && (
          <Button
            type="button"
            size="sm"
            disabled={disabled || submitting || !recipientId}
            onClick={() => void handleSubmit()}
          >
            {submitting ? <InlineBusy size="sm" /> : <Send className="h-4 w-4" />}
            {submitting ? 'Enviando…' : 'Enviar a ventas'}
          </Button>
        )}
      </div>
      {error && <p className="mt-2 text-sm text-red-700">{error}</p>}
    </div>
  )
}
