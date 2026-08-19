import { useEffect, useState } from 'react'
import { Outlet } from 'react-router-dom'
import {
  brandingLogoSrc,
  DEFAULT_APP_BRANDING,
  getAppBranding,
  getInitialBranding,
  type AppBranding,
} from '@/lib/branding-api'

export function AuthLayout() {
  const [branding, setBranding] = useState<AppBranding>(() => getInitialBranding())
  const logoSrc = brandingLogoSrc(branding.logoUrl)
  const showBrandCopy = Boolean(branding.appName.trim())

  useEffect(() => {
    let cancelled = false
    getAppBranding()
      .then((next) => {
        if (!cancelled) {
          setBranding(next)
          document.title = next.appName
        }
      })
      .catch(() => {
        if (!cancelled && !branding.appName.trim()) {
          setBranding(DEFAULT_APP_BRANDING)
          document.title = DEFAULT_APP_BRANDING.appName
        }
      })
    return () => {
      cancelled = true
    }
    // Solo al montar: branding.appName se usa solo como respaldo en el catch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <div className="flex min-h-svh">
      <div className="auth-hero-panel relative hidden w-1/2 flex-col p-12 text-white lg:flex">
        <div className="relative z-10">
          {showBrandCopy ? (
            <>
              {logoSrc ? (
                <img
                  src={logoSrc}
                  alt={branding.appName}
                  className="h-14 w-14 rounded-2xl bg-white object-contain p-1 shadow-xl shadow-indigo-950/40 ring-1 ring-white/25"
                />
              ) : null}
              <h1
                className={`${logoSrc ? 'mt-10' : ''} text-4xl font-semibold leading-tight tracking-tight sm:text-5xl`}
              >
                {branding.appName}
              </h1>
              {branding.appTagline ? (
                <p className="mt-4 text-lg font-medium tracking-wide text-indigo-200/95">
                  {branding.appTagline}
                </p>
              ) : null}
            </>
          ) : (
            <div className="space-y-4" aria-hidden>
              <div className="h-14 w-14 animate-pulse rounded-2xl bg-white/15" />
              <div className="h-10 w-64 max-w-full animate-pulse rounded-lg bg-white/15" />
              <div className="h-5 w-48 max-w-full animate-pulse rounded-lg bg-white/10" />
            </div>
          )}
        </div>
        <div
          className="pointer-events-none absolute -bottom-16 -right-10 h-56 w-56 rounded-full bg-indigo-500/25 blur-3xl"
          aria-hidden
        />
        <div
          className="pointer-events-none absolute bottom-24 left-10 h-32 w-32 rounded-full bg-sky-400/20 blur-3xl"
          aria-hidden
        />
      </div>
      <div className="auth-outlet relative flex flex-1 items-center justify-center overflow-hidden bg-slate-50 p-6">
        <div
          className="pointer-events-none absolute -left-20 top-10 h-64 w-64 rounded-full bg-indigo-400/15 blur-3xl"
          aria-hidden
        />
        <div
          className="pointer-events-none absolute -right-16 bottom-0 h-56 w-56 rounded-full bg-sky-400/15 blur-3xl"
          aria-hidden
        />
        <div className="relative z-10 w-full max-w-md animate-fade-up">
          <Outlet />
        </div>
      </div>
    </div>
  )
}
