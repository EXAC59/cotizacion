import { NavLink } from 'react-router-dom'
import { useBranding } from '@/hooks/useBranding'
import { usePermission } from '@/hooks/usePermission'
import { brandingLogoSrc } from '@/lib/branding-api'
import { MODULE_ROUTES } from '@/lib/module-routes'
import { ROLE_LABELS } from '@/types'

export function Sidebar() {
  const { user, canModule } = usePermission()
  const { branding } = useBranding()
  const visibleNav = MODULE_ROUTES.filter((item) => {
    if (!canModule(item.module)) return false
    if (item.roles && user && !item.roles.includes(user.role)) return false
    return true
  })
  const logoSrc = brandingLogoSrc(branding.logoUrl)
  const initial = (branding.appName.trim().charAt(0) || 'C').toUpperCase()

  return (
    <aside className="app-sidebar-shell relative flex w-64 shrink-0 flex-col text-slate-300 shadow-[8px_0_32px_-18px_rgba(0,0,0,0.55)] sm:w-72">
      <div className="pointer-events-none absolute inset-y-0 right-0 w-px bg-gradient-to-b from-indigo-400/40 via-slate-600/40 to-transparent" />

      <div className="border-b border-white/5 px-5 py-5">
        <div className="flex items-start gap-3">
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
          <div className="min-w-0 flex-1">
            <p className="text-sm font-semibold leading-snug tracking-tight break-words text-white">
              {branding.appName}
            </p>
            <p className="mt-0.5 text-xs leading-snug break-words text-slate-400">
              {branding.appTagline}
            </p>
          </div>
        </div>
      </div>

      <nav className="flex-1 space-y-1 overflow-y-auto p-3">
        {visibleNav.map(({ path, label, icon: Icon, end }) => (
          <NavLink
            key={path}
            to={path}
            end={end}
            className={({ isActive }) =>
              `nav-link-motion flex items-start gap-3 rounded-xl px-3 py-2.5 text-sm font-medium ${
                isActive
                  ? 'is-active bg-gradient-to-r from-indigo-600 to-indigo-500 text-white'
                  : 'text-slate-300 hover:bg-white/5 hover:text-white'
              }`
            }
          >
            <Icon className="mt-0.5 h-5 w-5 shrink-0 opacity-90" />
            <span className="min-w-0 leading-snug break-words">{label}</span>
          </NavLink>
        ))}
      </nav>

      {user && (
        <div className="border-t border-white/5 p-4">
          <div className="rounded-xl bg-white/5 px-3 py-2.5 ring-1 ring-white/10">
            <p className="text-sm font-medium leading-snug break-words text-white">{user.name}</p>
            <p className="mt-0.5 text-xs leading-snug break-words text-slate-400">
              {ROLE_LABELS[user.role]}
            </p>
          </div>
        </div>
      )}
    </aside>
  )
}
