import { useCallback, useEffect, useRef, useState } from 'react'
import { Search, X } from 'lucide-react'
import { InlineBusy } from '@/components/ui/LoadingState'
import { Input, Label } from '@/components/ui/Input'
import { listClients } from '@/lib/clients-api'
import type { Client } from '@/types'

export function ClientSearchSelect({
  value,
  onChange,
  label = 'Cliente',
  required = false,
  placeholder = 'Escribe para buscar…',
  disabled = false,
}: {
  value: string
  onChange: (clientId: string, client?: Client) => void
  label?: string
  required?: boolean
  placeholder?: string
  disabled?: boolean
}) {
  const [query, setQuery] = useState('')
  const [debouncedQuery, setDebouncedQuery] = useState('')
  const [results, setResults] = useState<Client[]>([])
  const [selected, setSelected] = useState<Client | null>(null)
  const [loading, setLoading] = useState(false)
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedQuery(query.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [query])

  const loadSelected = useCallback(async (clientId: string) => {
    if (!clientId) {
      setSelected(null)
      return
    }
    try {
      const clients = await listClients()
      const found = clients.find((c) => c.id === clientId) ?? null
      setSelected(found)
      if (found) setQuery(found.company)
    } catch {
      setSelected(null)
    }
  }, [])

  useEffect(() => {
    void loadSelected(value)
  }, [value, loadSelected])

  useEffect(() => {
    if (!open) return
    setLoading(true)
    listClients(debouncedQuery ? { q: debouncedQuery } : undefined)
      .then(setResults)
      .catch(() => setResults([]))
      .finally(() => setLoading(false))
  }, [debouncedQuery, open])

  useEffect(() => {
    const onDocClick = (e: MouseEvent) => {
      if (!containerRef.current?.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onDocClick)
    return () => document.removeEventListener('mousedown', onDocClick)
  }, [])

  const pick = (client: Client) => {
    setSelected(client)
    setQuery(client.company)
    setOpen(false)
    onChange(client.id, client)
  }

  const clear = () => {
    setSelected(null)
    setQuery('')
    onChange('')
  }

  return (
    <div ref={containerRef} className="relative">
      <Label>
        {label}
        {required && ' *'}
      </Label>
      <div className="relative mt-1">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <Input
          value={query}
          onChange={(e) => {
            setQuery(e.target.value)
            setOpen(true)
            if (selected && e.target.value !== selected.company) {
              setSelected(null)
              onChange('')
            }
          }}
          onFocus={() => setOpen(true)}
          placeholder={placeholder}
          disabled={disabled}
          className="pl-9 pr-9"
          autoComplete="off"
        />
        {(query || selected) && !disabled && (
          <button
            type="button"
            className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
            onClick={clear}
            aria-label="Limpiar"
          >
            <X className="h-4 w-4" />
          </button>
        )}
      </div>

      {open && !disabled && (
        <ul className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
          {loading && (
            <li className="flex items-center gap-2 px-3 py-2 text-sm text-slate-500">
              <InlineBusy size="sm" label="Buscando…" />
            </li>
          )}
          {!loading && results.length === 0 && (
            <li className="px-3 py-2 text-sm text-slate-500">
              {debouncedQuery ? 'Sin resultados' : 'Escribe para buscar clientes'}
            </li>
          )}
          {!loading &&
            results.map((client) => (
              <li key={client.id}>
                <button
                  type="button"
                  className={`w-full px-3 py-2 text-left text-sm hover:bg-indigo-50 ${
                    client.id === value ? 'bg-indigo-50 font-medium text-indigo-800' : 'text-slate-700'
                  }`}
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => pick(client)}
                >
                  <span className="block font-medium">{client.company}</span>
                  {(client.rfc || client.contact) && (
                    <span className="text-xs text-slate-500">
                      {[client.rfc, client.contact].filter(Boolean).join(' · ')}
                    </span>
                  )}
                </button>
              </li>
            ))}
        </ul>
      )}
    </div>
  )
}
