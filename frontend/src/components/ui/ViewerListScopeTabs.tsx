import type { ViewerListScope } from '@/lib/viewer-list-scope'

const OPTIONS: { value: ViewerListScope; label: string; hint: string }[] = [
  { value: 'mine', label: 'Mías', hint: 'Solo las que hiciste tú (o que compras te asignó)' },
  { value: 'all', label: 'Todas', hint: 'Las tuyas y las del resto del equipo' },
]

export function ViewerListScopeTabs({
  value,
  onChange,
}: {
  value: ViewerListScope
  onChange: (scope: ViewerListScope) => void
}) {
  return (
    <div className="mb-4">
      <div className="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1">
        {OPTIONS.map((option) => {
          const active = value === option.value
          return (
            <button
              key={option.value}
              type="button"
              title={option.hint}
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
      <p className="mt-2 text-xs text-slate-500">
        {OPTIONS.find((option) => option.value === value)?.hint}
      </p>
    </div>
  )
}
