import type { LucideIcon } from 'lucide-react'
import type { KeyboardEvent, MouseEvent } from 'react'

export function StatCard({
  label,
  value,
  hint,
  icon: Icon,
  trend,
  selected = false,
  onClick,
}: {
  label: string
  value: string
  hint?: string
  icon: LucideIcon
  trend?: string
  selected?: boolean
  onClick?: () => void
}) {
  const interactive = typeof onClick === 'function'

  const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (!interactive) return
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      onClick()
    }
  }

  const handleClick = (event: MouseEvent<HTMLDivElement>) => {
    if (!interactive) return
    event.preventDefault()
    onClick()
  }

  return (
    <div
      data-reveal
      role={interactive ? 'button' : undefined}
      tabIndex={interactive ? 0 : undefined}
      aria-pressed={interactive ? selected : undefined}
      onClick={interactive ? handleClick : undefined}
      onKeyDown={interactive ? handleKeyDown : undefined}
      className={`ui-surface group relative min-h-36 overflow-hidden rounded-2xl border bg-white/90 p-5 shadow-[0_1px_2px_rgb(15_23_42/0.04),0_8px_24px_-16px_rgb(15_23_42/0.12)] backdrop-blur-sm transition ${
        interactive
          ? 'cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/40'
          : ''
      } ${
        selected
          ? 'border-indigo-400 shadow-[0_1px_2px_rgb(15_23_42/0.04),0_10px_28px_-14px_rgb(79_70_229/0.45)] ring-2 ring-indigo-500/30'
          : 'border-slate-200/80 hover:border-slate-300'
      }`}
    >
      <div
        className={`pointer-events-none absolute -right-6 -top-6 h-24 w-24 rounded-full blur-2xl transition-opacity duration-300 ${
          selected ? 'bg-indigo-500/20 opacity-100' : 'bg-indigo-500/10 group-hover:opacity-100'
        }`}
        aria-hidden
      />
      <div className="relative flex h-full min-h-26 flex-col">
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0">
            <p className="pt-0.5 text-sm font-medium leading-5 text-slate-500">{label}</p>
            {selected && (
              <p className="mt-1 text-[11px] font-semibold uppercase tracking-wide text-indigo-600">
                Seleccionada
              </p>
            )}
          </div>
          <div
            className={`shrink-0 rounded-xl p-2.5 ring-1 transition-transform duration-300 group-hover:scale-105 ${
              selected
                ? 'bg-gradient-to-br from-indigo-100 to-sky-100 text-indigo-700 ring-indigo-200'
                : 'bg-gradient-to-br from-indigo-50 to-sky-50 text-indigo-600 ring-indigo-100'
            }`}
          >
            <Icon className="h-5 w-5" aria-hidden="true" />
          </div>
        </div>
        <div className="mt-auto pt-3">
          <p className="text-2xl font-semibold leading-none tracking-tight text-slate-900">{value}</p>
          {hint && <p className="mt-2 text-xs leading-4 text-slate-500">{hint}</p>}
          {trend && <p className="mt-2 text-xs font-medium leading-4 text-emerald-600">{trend}</p>}
        </div>
      </div>
    </div>
  )
}
