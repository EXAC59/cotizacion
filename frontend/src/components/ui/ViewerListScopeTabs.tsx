import type { ViewerListScope } from '@/lib/viewer-list-scope'

const OPTIONS: { value: ViewerListScope; label: string; hint: string }[] = [
  { value: 'all', label: 'Todas', hint: '' },
  { value: 'mine', label: 'Mías', hint: 'Solo las que hiciste tú (o que compras te asignó)' },
]

export function ViewerListScopeTabs({
  value,
  onChange,
}: {
  value: ViewerListScope
  onChange: (scope: ViewerListScope) => void
}) {
  const hint = OPTIONS.find((option) => option.value === value)?.hint

  return (
    <div className="mb-4">
      <div className="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1">
        {OPTIONS.map((option) => {
          const active = value === option.value
          return (
            <button
              key={option.value}
              type="button"
              title={option.hint || undefined}
              onClick={() => onChange(option.value)}
              className={`rounded-lg px-3 py-1.5 text-sm font-medium transition ${
                active
                  ? 'bg-white text-indigo-700 shadow-sm'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              {option.label}
            </button>
          )
        })}
      </div>
      {hint && <p className="mt-2 text-xs text-slate-500">{hint}</p>}
    </div>
  )
}
