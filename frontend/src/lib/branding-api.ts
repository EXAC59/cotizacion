import { getApiBase } from '@/lib/app-paths'

export type AppBranding = {
  appName: string
  appTagline: string
  logoUrl?: string | null
}

export const DEFAULT_APP_BRANDING: AppBranding = {
  appName: 'Cotización B2B',
  appTagline: 'Uso interno de Exacto',
  logoUrl: null,
}

const BRANDING_CACHE_KEY = 'cotizacion.appBranding.v1'

export function brandingLogoSrc(logoUrl: string | null | undefined): string | null {
  if (!logoUrl) return null
  if (logoUrl.startsWith('http://') || logoUrl.startsWith('https://')) return logoUrl
  return `${window.location.origin}${logoUrl.startsWith('/') ? '' : '/'}${logoUrl}`
}

export function readCachedBranding(): AppBranding | null {
  try {
    const raw = localStorage.getItem(BRANDING_CACHE_KEY)
    if (!raw) return null
    const data = JSON.parse(raw) as Partial<AppBranding>
    const appName = typeof data.appName === 'string' ? data.appName.trim() : ''
    if (!appName) return null
    return {
      appName,
      appTagline: typeof data.appTagline === 'string' ? data.appTagline.trim() : '',
      logoUrl: data.logoUrl ?? null,
    }
  } catch {
    return null
  }
}

export function writeCachedBranding(branding: AppBranding): void {
  try {
    localStorage.setItem(
      BRANDING_CACHE_KEY,
      JSON.stringify({
        appName: branding.appName,
        appTagline: branding.appTagline,
        logoUrl: branding.logoUrl ?? null,
      }),
    )
  } catch {
    // ignore quota / private mode
  }
}

/** Estado inicial: caché local o vacío (evita parpadeo del default genérico). */
export function getInitialBranding(): AppBranding {
  return (
    readCachedBranding() ?? {
      appName: '',
      appTagline: '',
      logoUrl: null,
    }
  )
}

export async function getAppBranding(): Promise<AppBranding> {
  // Público (login/landing) o autenticado (menú): no exige sesión.
  const response = await fetch(`${getApiBase()}/configuracion/branding`, {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })
  const data = (await response.json()) as AppBranding & { message?: string }
  if (!response.ok) {
    throw new Error(data.message || `Error al cargar branding (${response.status})`)
  }
  const branding: AppBranding = {
    appName: data.appName?.trim() || DEFAULT_APP_BRANDING.appName,
    appTagline: data.appTagline?.trim() || DEFAULT_APP_BRANDING.appTagline,
    logoUrl: data.logoUrl ?? null,
  }
  writeCachedBranding(branding)
  return branding
}
