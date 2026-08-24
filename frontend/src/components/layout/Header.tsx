import { type FormEvent, useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { AlertCircle, LogOut, Search } from 'lucide-react'
import { useLocation, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { NotificationsBell } from '@/components/layout/NotificationsBell'
import {
  beginModalBusy,
  ModalBusyPanel,
  waitMinBusyMs,
  waitModalBusyPaint,
} from '@/components/ui/LoadingState'
import { useAuth } from '@/hooks/useAuth'
import { buildQuoteSearchParams, isQuotesListPath } from '@/lib/quote-search-params'

export function Header({ title }: { title?: string }) {
  const { logout, user } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const params = new URLSearchParams(location.search)
  const urlQuery = isQuotesListPath(location.pathname) ? (params.get('q') ?? '') : ''

  const [searchTerm, setSearchTerm] = useState(urlQuery)
  const [logoutOpen, setLogoutOpen] = useState(false)
  const [loggingOut, setLoggingOut] = useState(false)

  useEffect(() => {
    if (isQuotesListPath(location.pathname)) {
      setSearchTerm(urlQuery)
    }
  }, [urlQuery, location.pathname])

  useEffect(() => {
    if (!logoutOpen) return
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !loggingOut) setLogoutOpen(false)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [logoutOpen, loggingOut])

  const handleConfirmLogout = async () => {
    beginModalBusy(setLoggingOut)
    await waitModalBusyPaint()
    // Mostrar animación ANTES de logout: al limpiar sesión, ProtectedRoute
    // desmonta el layout y el modal desaparecería al instante.
    await waitMinBusyMs(performance.now(), 1000)
    try {
      await logout()
      navigate('/login')
    } catch {
      setLoggingOut(false)
    }
  }

  const submitSearch = (event?: FormEvent) => {
    event?.preventDefault()
    const trimmed = searchTerm.trim()

    if (isQuotesListPath(location.pathname)) {
      const next = buildQuoteSearchParams(params, trimmed)
      const query = next.toString()
      navigate(
        { pathname: '/cotizaciones', search: query ? `?${query}` : '' },
        { replace: true },
      )
      return
    }

    const next = new URLSearchParams()
    if (trimmed) {
      next.set('q', trimmed)
    }
    const query = next.toString()
    navigate(`/cotizaciones${query ? `?${query}` : ''}`)
  }

  const logoutModal =
    logoutOpen && typeof document !== 'undefined'
      ? createPortal(
          <div
            className="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/50 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="logout-confirm-title"
            onClick={() => !loggingOut && setLogoutOpen(false)}
          >
            <div
              className="relative w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl"
              onClick={(e) => e.stopPropagation()}
            >
              {loggingOut ? (
                <ModalBusyPanel label="Cerrando sesión…" />
              ) : (
                <div className="flex items-start gap-3">
                  <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                    <AlertCircle className="h-5 w-5" aria-hidden />
                  </span>
                  <div className="min-w-0 flex-1">
                    <h3 id="logout-confirm-title" className="text-lg font-bold text-slate-900">
                      Cerrar sesión
                    </h3>
                    <p className="mt-2 text-sm text-slate-600">
                      ¿Seguro que deseas salir
                      {user?.email ? (
                        <>
                          {' '}
                          de <strong className="font-medium text-slate-800">{user.email}</strong>
                        </>
                      ) : null}
                      ? Guarda tu trabajo pendiente antes de continuar.
                    </p>
                    <div className="mt-5 flex flex-wrap justify-end gap-2">
                      <Button
                        type="button"
                        variant="secondary"
                        onClick={() => setLogoutOpen(false)}
                      >
                        Cancelar
                      </Button>
                      <Button type="button" onClick={() => void handleConfirmLogout()}>
                        <LogOut className="h-4 w-4" />
                        Sí, cerrar sesión
                      </Button>
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>,
          document.body,
        )
      : null

  return (
    <>
      <header className="app-header-glass sticky top-0 z-30 flex h-16 shrink-0 items-center justify-between gap-4 px-4 sm:px-6">
        <div className="flex min-w-0 flex-1 items-center gap-4">
          {title && (
            <span className="shrink-0 text-sm font-medium text-slate-600 lg:hidden">{title}</span>
          )}
          <form
            className="relative min-w-0 flex-1 max-w-xl"
            onSubmit={submitSearch}
            role="search"
          >
            <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              type="search"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              placeholder="Buscar folio o cliente (ej. demo-001)…"
              className="w-full rounded-xl border border-slate-200/90 bg-slate-50/90 py-2 pl-10 pr-24 text-sm shadow-sm focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
              aria-label="Buscar cotización por folio o cliente"
            />
            <Button
              type="submit"
              size="sm"
              className="absolute right-1.5 top-1/2 h-8 -translate-y-1/2 px-3 text-xs"
            >
              Buscar
            </Button>
          </form>
        </div>
        <div className="flex shrink-0 items-center gap-2 sm:gap-3">
          <NotificationsBell />
          {user?.email && (
            <div className="hidden max-w-[14rem] truncate rounded-xl border border-slate-200/80 bg-white/70 px-3 py-1.5 text-sm text-slate-600 shadow-sm md:block">
              {user.email}
            </div>
          )}
          <Button variant="ghost" size="sm" onClick={() => setLogoutOpen(true)}>
            <LogOut className="h-4 w-4" />
            Salir
          </Button>
        </div>
      </header>
      {logoutModal}
    </>
  )
}
