import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { LogIn } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { useAuth } from '@/hooks/useAuth'
import { useRbac } from '@/hooks/useRbac'
import {
  brandingLogoSrc,
  DEFAULT_APP_BRANDING,
  getAppBranding,
  getInitialBranding,
  type AppBranding,
} from '@/lib/branding-api'
import { getDefaultRoute } from '@/lib/module-routes'

export function LandingPage() {
  const { isAuthenticated, user } = useAuth()
  const { rolePermissions } = useRbac()
  const navigate = useNavigate()
  const [branding, setBranding] = useState<AppBranding>(() => getInitialBranding())
  const logoSrc = brandingLogoSrc(branding.logoUrl)
  const initial = (branding.appName.trim().charAt(0) || 'C').toUpperCase()

  useEffect(() => {
    if (isAuthenticated && user) {
      navigate(getDefaultRoute(rolePermissions, user), { replace: true })
    }
  }, [isAuthenticated, user, rolePermissions, navigate])

  useEffect(() => {
    let cancelled = false
    getAppBranding()
      .then((next) => {
        if (!cancelled) setBranding(next)
      })
      .catch(() => {
        if (!cancelled && !branding.appName.trim()) setBranding(DEFAULT_APP_BRANDING)
      })
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <Card className="w-full max-w-md">
      <CardBody className="text-center">
        {logoSrc ? (
          <img
            src={logoSrc}
            alt={branding.appName}
            className="mx-auto h-16 w-16 rounded-2xl border border-slate-200 bg-white object-contain p-1.5 shadow-md shadow-indigo-100"
          />
        ) : (
          <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-700 text-2xl font-bold text-white shadow-lg shadow-indigo-200">
            {initial}
          </div>
        )}
        <h2 className="mt-5 text-2xl font-semibold tracking-tight text-slate-900">
          {branding.appName}
        </h2>
        <p className="mt-1.5 text-sm font-medium text-indigo-600">{branding.appTagline}</p>
        <p className="mt-2 text-sm text-slate-500">
          Solicitudes, comparador de precios y cotizaciones profesionales.
        </p>
        <Link to="/login" className="mt-7 block">
          <Button className="w-full" size="lg">
            <LogIn className="h-4 w-4" />
            Iniciar sesión
          </Button>
        </Link>
      </CardBody>
    </Card>
  )
}
