import { useCallback, useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useBlocker, useNavigate } from 'react-router-dom'
import { AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import {
  ModalBusyPanel,
  waitMinBusyMs,
  waitModalBusyPaint,
} from '@/components/ui/LoadingState'

type LeaveIntent = 'navigate' | 'reload'
type Phase = 'idle' | 'step1' | 'step2' | 'leaving' | 'saving'

export type UnsavedChangesGuardOptions = {
  /**
   * Guardar desde el segundo aviso. Debe devolver true si el guardado
   * tuvo éxito (y entonces se sale / recarga).
   */
  onSave?: () => Promise<boolean | void>
}

function locationToPath(location: { pathname: string; search: string; hash: string }): string {
  return `${location.pathname}${location.search}${location.hash}`
}

/**
 * Doble aviso al salir (SPA) o recargar (F5 / Ctrl+R) si hay trabajo sin guardar.
 * 1) Cambios sin guardar → Seguir editando | Salir
 * 2) ¿Estás seguro? → Guardar y salir | Salir y se borra todo
 *
 * Nota RR7: si `when` pasa a false (tras guardar), el blocker cancela la navegación
 * pendiente. Por eso guardamos el destino y, si hace falta, navegamos a mano.
 */
export function useUnsavedChangesGuard(
  when: boolean,
  options: UnsavedChangesGuardOptions = {},
) {
  const navigate = useNavigate()
  const blocker = useBlocker(when)
  const [phase, setPhase] = useState<Phase>('idle')
  const [intent, setIntent] = useState<LeaveIntent | null>(null)
  const [leaveError, setLeaveError] = useState<string | null>(null)
  const onSaveRef = useRef(options.onSave)
  onSaveRef.current = options.onSave
  const phaseRef = useRef(phase)
  phaseRef.current = phase
  const pendingPathRef = useRef<string | null>(null)
  const leaveConfirmedRef = useRef(false)

  const openStep1 = useCallback((next: LeaveIntent) => {
    setLeaveError(null)
    setIntent(next)
    setPhase('step1')
  }, [])

  const closeGuard = useCallback(() => {
    leaveConfirmedRef.current = false
    pendingPathRef.current = null
    setLeaveError(null)
    setPhase('idle')
    setIntent(null)
    if (blocker.state === 'blocked') {
      blocker.reset()
    }
  }, [blocker])

  // Recarga accidental: F5 / Ctrl+R / Cmd+R → modal del sistema (no diálogo del navegador).
  useEffect(() => {
    if (!when) return
    const onKeyDown = (event: KeyboardEvent) => {
      const isReload =
        event.key === 'F5' ||
        ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'r')
      if (!isReload) return
      event.preventDefault()
      if (phaseRef.current !== 'idle') return
      openStep1('reload')
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [when, openStep1])

  // Abrir aviso solo al bloquear navegación; no tocar step2/saving/leaving.
  useEffect(() => {
    if (blocker.state === 'blocked') {
      pendingPathRef.current = locationToPath(blocker.location)
      setPhase((prev) => {
        if (prev === 'step1' || prev === 'step2' || prev === 'leaving' || prev === 'saving') {
          return prev
        }
        setLeaveError(null)
        setIntent('navigate')
        return 'step1'
      })
      return
    }

    // Bloqueo liberado: si ya confirmamos salir, no cerrar el modal a mitad de salida.
    if (leaveConfirmedRef.current) {
      return
    }

    setIntent((current) => {
      if (current === 'navigate') {
        setPhase('idle')
        setLeaveError(null)
        pendingPathRef.current = null
        return null
      }
      return current
    })
  }, [blocker.state, blocker.location])

  const finishLeave = async () => {
    leaveConfirmedRef.current = true
    setPhase('leaving')
    await waitModalBusyPaint()
    await waitMinBusyMs(performance.now(), 700)

    if (intent === 'reload') {
      window.location.reload()
      return
    }

    const target = pendingPathRef.current
    if (blocker.state === 'blocked') {
      blocker.proceed()
    } else if (target) {
      // Tras guardar, RR7 limpia el blocker al quitar dirty: salir a mano.
      navigate(target)
    }

    pendingPathRef.current = null
    leaveConfirmedRef.current = false
    setPhase('idle')
    setIntent(null)
    setLeaveError(null)
  }

  const confirmDiscardAndLeave = () => {
    setLeaveError(null)
    void finishLeave()
  }

  const confirmSaveAndLeave = async () => {
    const save = onSaveRef.current
    if (!save) {
      void finishLeave()
      return
    }
    setLeaveError(null)
    setPhase('saving')
    await waitModalBusyPaint()
    const started = performance.now()
    try {
      const ok = await save()
      await waitMinBusyMs(started, 600)
      if (ok === false) {
        setLeaveError('No se pudo guardar. Revisa los datos e intenta de nuevo.')
        setPhase('step2')
        return
      }
      await finishLeave()
    } catch (err) {
      setLeaveError(
        err instanceof Error ? err.message : 'No se pudo guardar. Intenta de nuevo.',
      )
      setPhase('step2')
    }
  }

  const goToStep2 = () => {
    setLeaveError(null)
    setPhase('step2')
  }

  const busy = phase === 'leaving' || phase === 'saving'
  const open = phase !== 'idle' && typeof document !== 'undefined'

  const dialog = open
    ? createPortal(
        <div
          className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/50 p-4"
          role="dialog"
          aria-modal="true"
          aria-labelledby="unsaved-changes-title"
        >
          <div className="relative w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
            {busy ? (
              <ModalBusyPanel
                label={phase === 'saving' ? 'Guardando…' : 'Saliendo…'}
              />
            ) : phase === 'step1' ? (
              <div className="flex items-start gap-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                  <AlertCircle className="h-5 w-5" />
                </span>
                <div className="min-w-0 flex-1">
                  <h3 id="unsaved-changes-title" className="text-lg font-bold text-slate-900">
                    Cambios sin guardar
                  </h3>
                  <p className="mt-2 text-sm text-slate-600">
                    Tienes trabajo pendiente en esta pantalla. Si sales ahora, puedes perder lo que no
                    hayas guardado.
                  </p>
                  <div className="mt-5 flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={closeGuard}>
                      Seguir editando
                    </Button>
                    <Button type="button" onClick={goToStep2}>
                      Salir
                    </Button>
                  </div>
                </div>
              </div>
            ) : (
              <div className="flex items-start gap-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700">
                  <AlertCircle className="h-5 w-5" />
                </span>
                <div className="min-w-0 flex-1">
                  <h3 id="unsaved-changes-title" className="text-lg font-bold text-slate-900">
                    ¿Estás seguro de salir?
                  </h3>
                  <p className="mt-2 text-sm text-slate-600">
                    Si sales sin guardar, se borrará todo lo que no hayas guardado en esta pantalla.
                    Esta acción no se puede deshacer.
                  </p>
                  {leaveError && (
                    <p className="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                      {leaveError}
                    </p>
                  )}
                  <div className="mt-5 flex flex-wrap justify-end gap-2">
                    <Button
                      type="button"
                      variant="secondary"
                      onClick={() => void confirmSaveAndLeave()}
                    >
                      Guardar y salir
                    </Button>
                    <Button type="button" variant="danger" onClick={confirmDiscardAndLeave}>
                      Salir y se borra todo
                    </Button>
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>,
        document.body,
      )
    : null

  return { dialog }
}

/**
 * Marca dirty tras la primera hidratación; `markClean` tras guardar con éxito.
 */
export function useDirtyTracker(active: boolean, deps: unknown[]) {
  const [dirty, setDirty] = useState(false)
  const readyRef = useRef(false)
  const skipNextRef = useRef(false)

  useEffect(() => {
    if (!active) {
      readyRef.current = false
      skipNextRef.current = false
      setDirty(false)
      return
    }

    if (!readyRef.current) {
      readyRef.current = true
      return
    }

    if (skipNextRef.current) {
      skipNextRef.current = false
      return
    }

    setDirty(true)
    // eslint-disable-next-line react-hooks/exhaustive-deps -- deps explícitas del llamador
  }, [active, ...deps])

  const markClean = useCallback(() => {
    skipNextRef.current = true
    setDirty(false)
  }, [])

  return { dirty, markClean, setDirty }
}
