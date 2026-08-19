import type { LucideIcon } from 'lucide-react'

export function StatCard({
  label,
  value,
  hint,
  icon: Icon,
  trend,
}: {
  label: string
  value: string
  hint?: string
  icon: LucideIcon
  trend?: string
}) {
  return (
    <div
      data-reveal
      className="ui-surface group relative min-h-36 overflow-hidden rounded-2xl border border-slate-200/80 bg-white/90 p-5 shadow-[0_1px_2px_rgb(15_23_42/0.04),0_8px_24px_-16px_rgb(15_23_42/0.12)] backdrop-blur-sm"
    >
      <div
        className="pointer-events-none absolute -right-6 -top-6 h-24 w-24 rounded-full bg-indigo-500/10 blur-2xl transition-opacity duration-300 group-hover:opacity-100"
        aria-hidden
      />
      <div className="relative flex h-full min-h-26 flex-col">
        <div className="flex items-start justify-between gap-4">
          <p className="min-w-0 pt-0.5 text-sm font-medium leading-5 text-slate-500">
            {label}
          </p>
          <div className="shrink-0 rounded-xl bg-gradient-to-br from-indigo-50 to-sky-50 p-2.5 text-indigo-600 ring-1 ring-indigo-100 transition-transform duration-300 group-hover:scale-105">
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
