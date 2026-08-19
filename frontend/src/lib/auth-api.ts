import { apiFetch } from '@/lib/api-fetch'
import { parseApiJson, type ApiErrorBody } from '@/lib/api-response'
import { setAuthToken } from '@/lib/auth-token'
import { getApiBase } from '@/lib/app-paths'
import type { User, UserRole } from '@/types'

type AuthUserPayload = {
  id: string
  name: string
  email: string
  username?: string | null
  folioCode?: string | null
  role: UserRole | null
  active?: boolean
}

function mapUser(payload: AuthUserPayload): User | null {
  if (!payload.role) {
    return null
  }

  return {
    id: payload.id,
    name: payload.name,
    email: payload.email,
    username: payload.username ?? undefined,
    folioCode: payload.folioCode ?? undefined,
    role: payload.role,
    active: payload.active ?? true,
  }
}

export async function fetchCurrentUser(): Promise<User | null> {
  const response = await apiFetch(`${getApiBase()}/user`)

  if (response.status === 401) {
    return null
  }

  if (!response.ok) {
    return null
  }

  const data = await parseApiJson<{ user?: AuthUserPayload }>(response)
  return data.user ? mapUser(data.user) : null
}

export async function loginRequest(login: string, password: string): Promise<User | null> {
  const trimmed = login.trim()
  const payload: Record<string, string> = {
    password: password.trim(),
  }
  // Enviar ambos: el API acepta usuario o correo en cualquiera de los campos.
  if (trimmed.includes('@')) {
    payload.email = trimmed
    payload.username = trimmed
  } else {
    payload.username = trimmed
  }

  const response = await apiFetch(`${getApiBase()}/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(payload),
  })

  if (!response.ok) {
    const data = await parseApiJson<ApiErrorBody>(response)
    const usernameError = data.errors?.username ?? data.errors?.email
    const message = Array.isArray(usernameError)
      ? usernameError[0]
      : typeof usernameError === 'string'
        ? usernameError
        : response.status === 419
          ? 'Sesión expirada. Recarga la página e intenta de nuevo.'
          : data.message ?? 'Usuario/correo o contraseña incorrectos.'
    throw new Error(message)
  }

  const data = await parseApiJson<{ user?: AuthUserPayload }>(response)
  // Sesión por cookie httpOnly; limpia tokens bearer viejos de localStorage.
  setAuthToken(null)

  return data.user ? mapUser(data.user) : null
}

export async function logoutRequest(): Promise<void> {
  try {
    await apiFetch(`${getApiBase()}/logout`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
    })
  } finally {
    setAuthToken(null)
  }
}
