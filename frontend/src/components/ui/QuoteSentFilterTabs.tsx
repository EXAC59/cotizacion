export type QuoteSentFilter = 'unsent' | 'sent'

const OPTIONS: { value: QuoteSentFilter; label: string; hint: string }[] = [
  {
    value: 'unsent',
    label: 'No enviadas',
    hint: 'Sin evidencia de envío al cliente (sin fecha de envío)',
  },
  {
    value: 'sent',
    label: 'Enviadas',
    hint: 'Ya enviadas al cliente (con fecha de envío)',
  },
]

export function QuoteSentFilterTabs({
  value,
  onChange,
  unsentCount,
  sentCount,
}: {
  value: QuoteSentFilter
  onChange: (value: QuoteSentFilter) => void
  unsentCount?: number
  sentCount?: number
}) {
  const countFor = (option: QuoteSentFilter) =>
    option === 'unsent' ? unsentCount : sentCount

  return (
    <div className="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1">
      {OPTIONS.map((option) => {
        const active = value === option.value
        const count = countFor(option.value)
        return (
          <button
            key={option.value}
            type="button"
            title={option.hint}
            aria-pressed={active}
            onClick={() => onChange(option.value)}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium transition ${
              active
                ? 'bg-white text-indigo-700 shadow-sm'
                : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            {option.label}
            {typeof count === 'number' && (
              <span className={`ml-1.5 tabular-nums ${active ? 'text-indigo-500' : 'text-slate-400'}`}>
                {count}
              </span>
            )}
          </button>
        )
      })}
    </div>
  )
}
