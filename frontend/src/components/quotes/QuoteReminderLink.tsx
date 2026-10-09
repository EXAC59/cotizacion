import { Link } from 'react-router-dom'
import { ArrowRight } from 'lucide-react'

export function QuoteReminderLink({ id, folio }: { id: string; folio: string }) {
  const target = import.meta.env.VITE_MENSAJERIA_LIVE === 'true' ? `/mensajeria?quote=${encodeURIComponent(id)}&reminder=1` : `/recordatorios?quote=${encodeURIComponent(id)}`
  return <Link to={target} aria-label={`Abrir recordatorio: ${folio}`} className="inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:border-indigo-400 hover:text-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Abrir recordatorio<ArrowRight className="h-4 w-4" aria-hidden="true" /></Link>
}
