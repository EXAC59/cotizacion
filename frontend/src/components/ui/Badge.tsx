import type { ReactNode } from 'react'

const variants = {
  default: 'bg-slate-100/90 text-slate-700 ring-1 ring-slate-200/80',
  brand: 'bg-indigo-50 text-indigo-800 ring-1 ring-indigo-100',
  success: 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-100',
  warning: 'bg-amber-50 text-amber-800 ring-1 ring-amber-100',
  danger: 'bg-red-50 text-red-800 ring-1 ring-red-100',
  muted: 'bg-slate-200/80 text-slate-600 ring-1 ring-slate-300/60',
} as const

export function Badge({
  children,
  variant = 'default',
}: {
  children: ReactNode
  variant?: keyof typeof variants
}) {
  return (
    <span
      className={`inline-flex items-center rounded-lg px-2.5 py-0.5 text-xs font-medium ${variants[variant]}`}
    >
      {children}
    </span>
  )
}
