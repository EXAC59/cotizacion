import type { ReactNode } from 'react'

export function Card({
  children,
  className = '',
}: {
  children: ReactNode
  className?: string
}) {
  return (
    <div
      data-reveal
      className={`ui-surface rounded-2xl border border-slate-200/80 bg-white/90 shadow-[0_1px_2px_rgb(15_23_42/0.04),0_8px_24px_-16px_rgb(15_23_42/0.12)] backdrop-blur-sm ${className}`}
    >
      {children}
    </div>
  )
}

export function CardHeader({
  title,
  subtitle,
  action,
}: {
  title: string
  subtitle?: string
  action?: ReactNode
}) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100/90 px-5 py-4">
      <div className="min-w-0">
        <div className="mb-1.5 h-1 w-8 rounded-full bg-gradient-to-r from-indigo-500 to-sky-400" />
        <h2 className="text-base font-semibold tracking-tight text-slate-900">{title}</h2>
        {subtitle && <p className="mt-0.5 text-sm text-slate-500">{subtitle}</p>}
      </div>
      {action}
    </div>
  )
}

export function CardBody({
  children,
  className = '',
}: {
  children: ReactNode
  className?: string
}) {
  return <div className={`p-5 ${className}`}>{children}</div>
}
