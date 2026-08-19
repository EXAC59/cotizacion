import type { ReactNode } from 'react'
import { flushSync } from 'react-dom'
import { Loader2 } from 'lucide-react'

type LoadingStateProps = {
  label?: string
  /** Compact for inside cards/tables; page for full sections */
  variant?: 'page' | 'section' | 'inline'
  className?: string
}

type SpinnerSize = 'xs' | 'sm' | 'md'

const spinnerSizeClass: Record<SpinnerSize, string> = {
  xs: 'loading-spinner--xs',
  sm: 'loading-spinner--sm',
  md: '',
}

export function LoadingSpinner({
  className = '',
  size = 'md',
}: {
  className?: string
  size?: SpinnerSize
}) {
  return (
    <span className={`loading-spinner ${spinnerSizeClass[size]} ${className}`} aria-hidden>
      <span className="loading-spinner-ring" />
      <span className="loading-spinner-core" />
    </span>
  )
}

export function LoadingDots({ className = '' }: { className?: string }) {
  return (
    <span className={`loading-dots ${className}`} aria-hidden>
      <span />
      <span />
      <span />
    </span>
  )
}

/**
 * Indicador compacto para botones / filas (reemplaza Loader2).
 */
export function InlineBusy({
  label,
  size = 'sm',
  className = '',
}: {
  label?: string
  size?: SpinnerSize
  className?: string
}) {
  return (
    <span className={`inline-flex items-center gap-2 ${className}`} role="status" aria-live="polite">
      <LoadingSpinner size={size} />
      {label ? <span>{label}</span> : null}
    </span>
  )
}

/**
 * Estado de carga visual (spinner brand + etiqueta).
 */
export function LoadingState({
  label = 'Cargando…',
  variant = 'section',
  className = '',
}: LoadingStateProps) {
  const padding =
    variant === 'page' ? 'py-20' : variant === 'section' ? 'py-12' : 'py-4'

  return (
    <div
      className={`flex flex-col items-center justify-center gap-3 text-center ${padding} ${className}`}
      role="status"
      aria-live="polite"
      aria-busy="true"
    >
      <div className="relative flex h-14 w-14 items-center justify-center">
        <span className="loading-orb loading-orb-a" aria-hidden />
        <span className="loading-orb loading-orb-b" aria-hidden />
        <LoadingSpinner className="relative z-10" />
      </div>
      <div>
        <p className="text-sm font-medium text-slate-700">{label}</p>
        <LoadingDots className="mt-2 justify-center" />
      </div>
    </div>
  )
}

export function TableSkeleton({
  rows = 5,
  cols = 4,
}: {
  rows?: number
  cols?: number
}) {
  return (
    <div className="animate-fade-up space-y-0 p-2" aria-hidden>
      <div className="mb-2 flex gap-3 px-3 py-2">
        {Array.from({ length: cols }).map((_, i) => (
          <div key={`h-${i}`} className="skeleton-bar h-3 flex-1" />
        ))}
      </div>
      {Array.from({ length: rows }).map((_, r) => (
        <div
          key={`r-${r}`}
          className="flex gap-3 border-t border-slate-100 px-3 py-3"
          style={{ animationDelay: `${r * 60}ms` }}
        >
          {Array.from({ length: cols }).map((_, c) => (
            <div
              key={`c-${r}-${c}`}
              className="skeleton-bar h-3.5 flex-1"
              style={{ maxWidth: c === 0 ? '30%' : undefined }}
            />
          ))}
        </div>
      ))}
    </div>
  )
}

export function StatGridSkeleton({ count = 3 }: { count?: number }) {
  return (
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-hidden>
      {Array.from({ length: count }).map((_, i) => (
        <div
          key={i}
          className="rounded-2xl border border-slate-200/80 bg-white/80 p-5"
          style={{ animationDelay: `${i * 80}ms` }}
        >
          <div className="flex items-start justify-between">
            <div className="w-2/3 space-y-3">
              <div className="skeleton-bar h-3 w-24" />
              <div className="skeleton-bar h-7 w-20" />
              <div className="skeleton-bar h-2.5 w-32" />
            </div>
            <div className="skeleton-bar h-10 w-10 rounded-xl" />
          </div>
        </div>
      ))}
    </div>
  )
}

export function LoadingOverlay({
  children,
  show,
  label = 'Cargando…',
}: {
  children: ReactNode
  show: boolean
  label?: string
}) {
  return (
    <div className="relative">
      {children}
      {show && (
        <div className="absolute inset-0 z-10 flex items-center justify-center rounded-2xl bg-white/70 backdrop-blur-[2px]">
          <LoadingState label={label} variant="inline" className="py-8" />
        </div>
      )}
    </div>
  )
}

/**
 * Fuerza pintar el estado busy del modal antes de la acción async
 * (evita que APIs rápidas cierren sin mostrar animación).
 */
export function beginModalBusy(setBusy: (busy: boolean) => void) {
  flushSync(() => setBusy(true))
}

export async function waitModalBusyPaint() {
  await new Promise<void>((resolve) => {
    requestAnimationFrame(() => requestAnimationFrame(() => resolve()))
  })
}

export async function waitMinBusyMs(startedAt: number, minMs = 900) {
  const left = minMs - (performance.now() - startedAt)
  if (left > 0) {
    await new Promise<void>((resolve) => {
      window.setTimeout(resolve, left)
    })
  }
}

/** Spinner con animación SVG (no depende de clases CSS / animate-spin). */
function ModalBusySpinner() {
  return (
    <svg
      className="h-12 w-12 text-indigo-600"
      viewBox="0 0 48 48"
      fill="none"
      aria-hidden
    >
      <circle cx="24" cy="24" r="18" stroke="currentColor" strokeOpacity="0.2" strokeWidth="4" />
      <path
        d="M42 24a18 18 0 0 0-18-18"
        stroke="currentColor"
        strokeWidth="4"
        strokeLinecap="round"
      >
        <animateTransform
          attributeName="transform"
          type="rotate"
          from="0 24 24"
          to="360 24 24"
          dur="0.8s"
          repeatCount="indefinite"
        />
      </path>
    </svg>
  )
}

/**
 * Cuerpo de carga del modal (reemplaza el contenido; más fiable que un overlay).
 */
export function ModalBusyPanel({ label = 'Procesando…' }: { label?: string }) {
  return (
    <div
      className="flex min-h-[200px] flex-col items-center justify-center gap-4 px-4 py-10 text-center"
      role="status"
      aria-live="polite"
      aria-busy="true"
    >
      <ModalBusySpinner />
      <div>
        <p className="text-base font-semibold text-slate-800">{label}</p>
        <LoadingDots className="mt-2 justify-center" />
      </div>
    </div>
  )
}

/**
 * Capa opaca de carga sobre el contenido de un modal (al confirmar acciones).
 * Preferir `ModalBusyPanel` como reemplazo de contenido cuando se pueda.
 */
export function ModalBusyOverlay({
  show,
  label = 'Procesando…',
}: {
  show: boolean
  label?: string
}) {
  if (!show) return null
  return (
    <div
      className="absolute inset-0 z-50 flex items-center justify-center rounded-2xl bg-white px-6"
      role="status"
      aria-live="polite"
      aria-busy="true"
    >
      <ModalBusyPanel label={label} />
      {/* Fallback CSS por si el SVG animateTransform está desactivado */}
      <Loader2
        className="modal-busy-spinner pointer-events-none absolute h-0 w-0 opacity-0"
        aria-hidden
      />
    </div>
  )
}
