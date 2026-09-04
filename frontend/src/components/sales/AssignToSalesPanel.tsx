import { useEffect, useState } from 'react'
import { Send } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Label, Select } from '@/components/ui/Input'
import { InlineBusy } from '@/components/ui/LoadingState'
import {
  listComprasUsers,
  listSalespeople,
  type AssignRecipientOption,
} from '@/lib/assign-to-sales-api'

export type AssignTarget = 'ventas' | 'compras'

type TeamCopy = {
  empty: string
  select: string
  loadError: string
  pickError: string
}

const TEAM_COPY: Record<AssignTarget, TeamCopy> = {
  ventas: {
    empty: 'Sin vendedores activos',
    select: 'Selecciona vendedor',
    loadError: 'No se pudieron cargar vendedores.',
    pickError: 'Elige a quién de ventas enviar.',
  },
  compras: {
    empty: 'Sin usuarios de compras activos',
    select: 'Selecciona compras',
    loadError: 'No se pudieron cargar usuarios de compras.',
    pickError: 'Elige a quién de compras enviar.',
  },
}

export function AssignToSalesPanel({
  disabled,
  onAssign,
  onRecipientChange,
  entityLabel = 'documento',
  saveWithParent = false,
  /** Destinos permitidos (compras y/o ventas). */
  allowedTargets = ['ventas'],
}: {
  disabled?: boolean
  entityLabel?: string
  allowedTargets?: AssignTarget[]
  onAssign?: (recipientId: number, target: AssignTarget) => Promise<void>
  onRecipientChange?: (recipientId: number | null, target: AssignTarget | null) => void
  saveWithParent?: boolean
}) {
  const targets = allowedTargets.filter((t, i, arr) => arr.indexOf(t) === i)
  const [target, setTarget] = useState<AssignTarget>(() => targets[0] ?? 'ventas')
  const [options, setOptions] = useState<AssignRecipientOption[]>([])
  const [recipientId, setRecipientId] = useState('')
  const [loadingOptions, setLoadingOptions] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const showOwnButton = Boolean(onAssign) && !saveWithParent
  const copy = TEAM_COPY[target]
  const canPickTeam = targets.length > 1

  useEffect(() => {
    if (targets.length === 0) return
    if (!targets.includes(target)) {
      setTarget(targets[0])
    }
  }, [targets.join('|'), target])

  useEffect(() => {
    if (targets.length === 0) return
    let cancelled = false
    setLoadingOptions(true)
    setRecipientId('')
    onRecipientChange?.(null, null)
    const loader = target === 'compras' ? listComprasUsers : listSalespeople
    loader()
      .then((rows) => {
        if (cancelled) return
        setOptions(rows)
        if (rows.length === 1 && !saveWithParent) {
          const id = String(rows[0].id)
          setRecipientId(id)
          onRecipientChange?.(rows[0].id, target)
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
  }, [target])

  const handleTeamChange = (next: AssignTarget) => {
    setTarget(next)
    setError(null)
  }

  const handleRecipientChange = (value: string) => {
    setRecipientId(value)
    setError(null)
    onRecipientChange?.(value ? Number(value) : null, value ? target : null)
  }

  const handleSubmit = async () => {
    if (!onAssign) return
    if (!recipientId) {
      setError(copy.pickError)
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      await onAssign(Number(recipientId), target)
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : `No se pudo enviar la ${entityLabel}.`)
    } finally {
      setSubmitting(false)
    }
  }

  if (targets.length === 0) return null

  return (
    <div className="rounded-xl border border-slate-200 bg-slate-50/80 px-4 py-3">
      <p className="text-sm font-medium text-slate-900">Enviar a compras / ventas</p>

      {canPickTeam && (
        <div className="mt-3 flex gap-2">
          {targets.includes('compras') && (
            <button
              type="button"
              disabled={disabled || submitting}
              onClick={() => handleTeamChange('compras')}
              className={`rounded-lg px-3 py-1.5 text-sm font-medium ring-1 transition ${
                target === 'compras'
                  ? 'bg-amber-600 text-white ring-amber-600'
                  : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50'
              }`}
            >
              Compras
            </button>
          )}
          {targets.includes('ventas') && (
            <button
              type="button"
              disabled={disabled || submitting}
              onClick={() => handleTeamChange('ventas')}
              className={`rounded-lg px-3 py-1.5 text-sm font-medium ring-1 transition ${
                target === 'ventas'
                  ? 'bg-indigo-600 text-white ring-indigo-600'
                  : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50'
              }`}
            >
              Ventas
            </button>
          )}
        </div>
      )}

      <div
        className={
          showOwnButton
            ? 'mt-3 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end'
            : 'mt-3'
        }
      >
        <div>
          <Label htmlFor={`assign-to-${target}`}>
            {target === 'ventas' ? 'Persona de ventas' : 'Persona de compras'}
          </Label>
          <Select
            id={`assign-to-${target}`}
            className="mt-1"
            value={recipientId}
            disabled={disabled || loadingOptions || submitting || options.length === 0}
            onChange={(e) => handleRecipientChange(e.target.value)}
          >
            <option value="">
              {loadingOptions
                ? 'Cargando…'
                : options.length === 0
                  ? copy.empty
                  : saveWithParent
                    ? 'Sin reasignar (opcional)'
                    : copy.select}
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
            {submitting ? 'Enviando…' : `Enviar a ${target}`}
          </Button>
        )}
      </div>
      {error && <p className="mt-2 text-sm text-red-700">{error}</p>}
    </div>
  )
}
