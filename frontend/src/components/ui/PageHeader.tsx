import type { ReactNode } from 'react'

export function PageHeader({
  title,
  description,
  actions,
}: {
  title: string
  description?: string
  actions?: ReactNode
}) {
  return (
    <div className="mb-7 flex flex-wrap items-start justify-between gap-4" data-reveal>
      <div className="min-w-0">
        <div className="mb-2 h-1 w-10 rounded-full bg-gradient-to-r from-indigo-500 to-sky-400" />
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900 sm:text-[1.75rem]">
          {title}
        </h1>
        {description && <p className="mt-1.5 max-w-2xl text-sm leading-relaxed text-slate-500">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}
