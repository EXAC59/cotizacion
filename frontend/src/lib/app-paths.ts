function readBaseHref(): string | null {
  if (typeof document === 'undefined') {
    return null
  }
  const href = document.querySelector('base')?.getAttribute('href')
  return href ? href : null
}

/** Base del router (coincide con &lt;base href&gt; que inyecta Laravel). */
export function getRouterBasename(): string {
  if (import.meta.env.DEV) {
    return '/'
  }

  const base = readBaseHref()
  if (base) {
    try {
      const pathname = new URL(base, window.location.origin).pathname
      return pathname.replace(/\/$/, '') || '/'
    } catch {
      /* fallback */
    }
  }

  return '/spa'
}

/** URL de la API Laravel según la base de la app. */
export function getApiBase(): string {
  const env = import.meta.env.VITE_API_URL as string | undefined

  if (env?.startsWith('http')) {
    return env.replace(/\/$/, '')
  }

  const base = readBaseHref()
  if (base && typeof window !== 'undefined') {
    try {
      const url = new URL(base, window.location.origin)
      const apiPath = url.pathname.replace(/\/spa\/?$/i, '/api')
      return `${url.origin}${apiPath}`
    } catch {
      /* fallback */
    }
  }

  return env?.startsWith('/') ? env : '/api'
}
