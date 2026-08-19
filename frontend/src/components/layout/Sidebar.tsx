import { NavLink } from 'react-router-dom'
import { useBranding } from '@/hooks/useBranding'
import { usePermission } from '@/hooks/usePermission'
import { brandingLogoSrc } from '@/lib/branding-api'
import { MODULE_ROUTES } from '@/lib/module-routes'
import { ROLE_LABELS } from '@/types'

export function Sidebar() {
  const { user, canModule } = usePermission()
  const { branding } = useBranding()
  const visibleNav = MODULE_ROUTES.filter((item) => canModule(item.module))
  const logoSrc = brandingLogoSrc(branding.logoUrl)
  const initial = (branding.appName.trim().charAt(0) || 'C').toUpperCase()

  return (
    <aside className="app-sidebar-shell relative flex w-64 shrink-0 flex-col text-slate-300 shadow-[8px_0_32px_-18px_rgba(0,0,0,0.55)]">
      <div className="pointer-events-none absolute inset-y-0 right-0 w-px bg-gradient-to-b from-indigo-400/40 via-slate-600/40 to-transparent" />

      <div className="border-b border-white/5 px-5 py-5">
        <div className="flex items-center gap-3">
          {logoSrc ? (
            <img
              src={logoSrc}
              alt={branding.appName}
              className="h-10 w-10 shrink-0 rounded-xl bg-white object-contain p-0.5 shadow-lg shadow-indigo-950/40 ring-1 ring-white/20"
            />
          ) : (
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 text-sm font-bold text-white shadow-lg shadow-indigo-900/50 ring-1 ring-white/20">
              {initial}
            </div>
          )}
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold tracking-tight text-white">
              {branding.appName}
            </p>
            <p className="truncate text-xs text-slate-400">{branding.appTagline}</p>
          </div>
        </div>
      </div>

      <nav className="flex-1 space-y-1 p-3">
        {visibleNav.map(({ path, label, icon: Icon, end }) => (
          <NavLink
            key={path}
            to={path}
            end={end}
            className={({ isActive }) =>
              `nav-link-motion flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium ${
                isActive
                  ? 'is-active bg-gradient-to-r from-indigo-600 to-indigo-500 text-white'
                  : 'text-slate-300 hover:bg-white/5 hover:text-white'
              }`
            }
          >
            <Icon className="h-5 w-5 shrink-0 opacity-90" />
            {label}
          </NavLink>
        ))}
      </nav>

      {user && (
        <div className="border-t border-white/5 p-4">
          <div className="rounded-xl bg-white/5 px-3 py-2.5 ring-1 ring-white/10">
            <p className="truncate text-sm font-medium text-white">{user.name}</p>
            <p className="truncate text-xs text-slate-400">{ROLE_LABELS[user.role]}</p>
          </div>
        </div>
      )}
    </aside>
  )
}
