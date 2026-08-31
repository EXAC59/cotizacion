import { getApiBase } from '@/lib/app-paths'
import { setAuthToken } from '@/lib/auth-token'

let csrfCookiePromise: Promise<void> | null = null
let unauthorizedHandler: (() => void) | null = null
let clearedLegacyToken = false

export function setUnauthorizedHandler(handler: (() => void) | null): void {
  unauthorizedHandler = handler
}

/** Fuerza nueva cookie CSRF (p. ej. al abrir login tras error de sesión). */
export function resetCsrfCookie(): void {
  csrfCookiePromise = null
}

function sanctumBase(): string {
  return getApiBase().replace(/\/api\/?$/i, '')
}

function clearLegacyBearerOnce(): void {
  if (clearedLegacyToken) {
    return
  }
  clearedLegacyToken = true
  try {
    setAuthToken(null)
  } catch {
    // ignore storage errors al limpiar legado
  }
}

function readXsrfToken(): string | null {
  if (typeof document === 'undefined') {
    return null
  }

  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)
  return match ? decodeURIComponent(match[1]) : null
}

/** Obtiene la cookie CSRF de Sanctum (requerida para POST/PUT/DELETE con sesión). */
export async function ensureCsrfCookie(): Promise<void> {
  if (!csrfCookiePromise) {
    csrfCookiePromise = fetch(`${sanctumBase()}/sanctum/csrf-cookie`, {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    }).then(() => undefined)
  }

  await csrfCookiePromise
}

export async function apiFetch(input: string, init: RequestInit = {}): Promise<Response> {
  clearLegacyBearerOnce()

  const method = (init.method ?? 'GET').toUpperCase()
  const isLogin = String(input).includes('/login')
  const needsCsrf = !['GET', 'HEAD', 'OPTIONS'].includes(method) && !isLogin

  if (needsCsrf) {
    await ensureCsrfCookie()
  }

  const headers = new Headers(init.headers)
  if (!headers.has('Accept')) {
    headers.set('Accept', 'application/json')
  }
  if (typeof __SPA_BUILD_ID__ === 'string' && __SPA_BUILD_ID__) {
    headers.set('X-Spa-Build-Id', __SPA_BUILD_ID__)
  }

  if (needsCsrf) {
    const token = readXsrfToken()
    if (token) {
      headers.set('X-XSRF-TOKEN', token)
    }
  }

  const response = await fetch(input, {
    ...init,
    headers,
    credentials: 'include',
  })

  if (response.status === 419 && needsCsrf) {
    csrfCookiePromise = null
    await ensureCsrfCookie()
    const retryHeaders = new Headers(init.headers)
    if (!retryHeaders.has('Accept')) {
      retryHeaders.set('Accept', 'application/json')
    }
    const retryToken = readXsrfToken()
    if (retryToken) {
      retryHeaders.set('X-XSRF-TOKEN', retryToken)
    }
    return fetch(input, {
      ...init,
      headers: retryHeaders,
      credentials: 'include',
    })
  }

  if (
    response.status === 401 &&
    !String(input).includes('/login') &&
    !String(input).includes('/user')
  ) {
    unauthorizedHandler?.()
    if (response.headers.get('X-Spa-Upgrade-Required') === '1') {
      window.location.reload()
    }
  }

  return response
}
