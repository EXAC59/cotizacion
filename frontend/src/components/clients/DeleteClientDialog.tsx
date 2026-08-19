import { useEffect, useState } from 'react'
import { AlertTriangle, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input, Label } from '@/components/ui/Input'
import { ModalBusyPanel } from '@/components/ui/LoadingState'

type Props = {
  open: boolean
  company: string
  quotesCount?: number
  deleting?: boolean
  error?: string | null
  onClose: () => void
  onConfirm: () => void
}

/**
 * Modal de seguridad para eliminar cliente (admin).
 * - Si tiene cotizaciones: bloquea y explica.
 * - Si no: pide escribir ELIMINAR para confirmar.
 */
export function DeleteClientDialog({
  open,
  company,
  quotesCount = 0,
  deleting = false,
  error = null,
  onClose,
  onConfirm,
}: Props) {
  const [confirmText, setConfirmText] = useState('')
  const blocked = quotesCount > 0
  const canConfirm = !blocked && confirmText.trim().toUpperCase() === 'ELIMINAR'

  useEffect(() => {
    if (open) setConfirmText('')
  }, [open, company])

  if (!open) return null

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="delete-client-title"
      onClick={() => !deleting && onClose()}
    >
      <div
        className="relative w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        {deleting ? (
          <ModalBusyPanel label="Eliminando cliente…" />
        ) : (
          <>
            <div className="mb-4 flex items-start justify-between gap-3">
              <div className="flex gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700">
                  <AlertTriangle className="h-5 w-5" aria-hidden />
                </div>
                <div>
                  <h3 id="delete-client-title" className="text-lg font-bold text-slate-900">
                    Eliminar cliente
                  </h3>
                  <p className="mt-1 text-sm text-slate-600">
                    Esta acción es permanente y solo está disponible para administradores.
                  </p>
                </div>
              </div>
              <button
                type="button"
                className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"
                onClick={onClose}
                aria-label="Cerrar"
              >
                <X className="h-5 w-5" />
              </button>
            </div>

            <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
              <span className="text-slate-500">Cliente: </span>
              <strong className="text-slate-900">{company || '—'}</strong>
            </div>

            {blocked ? (
              <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-900">
                <p className="font-medium">No se puede eliminar.</p>
                <p className="mt-1">
                  Tiene <strong>{quotesCount}</strong> cotización(es) asociada(s). Primero debes
                  gestionar o reasignar esas cotizaciones.
                </p>
              </div>
            ) : (
              <div className="mt-4 space-y-3">
                <p className="text-sm text-slate-600">
                  Se borrarán los datos del cliente. Las solicitudes vinculadas perderán la referencia
                  al cliente. Para confirmar, escribe <strong>ELIMINAR</strong> abajo.
                </p>
                <div>
                  <Label htmlFor="delete-client-confirm">Confirmación</Label>
                  <Input
                    id="delete-client-confirm"
                    value={confirmText}
                    onChange={(e) => setConfirmText(e.target.value)}
                    placeholder="Escribe ELIMINAR"
                    autoComplete="off"
                  />
                </div>
              </div>
            )}

            {error && (
              <p className="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                {error}
              </p>
            )}

            <div className="mt-6 flex justify-end gap-2">
              <Button variant="secondary" size="sm" onClick={onClose}>
                Cancelar
              </Button>
              {!blocked && (
                <Button variant="danger" size="sm" disabled={!canConfirm} onClick={onConfirm}>
                  Eliminar definitivamente
                </Button>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  )
}
