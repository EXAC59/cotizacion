const STORAGE_KEY = 'cotizacion_auth_token'

function readStorage(): Storage | null {
  if (typeof window === 'undefined') {
    return null
  }

  try {
    return window.localStorage
  } catch {
    try {
      return window.sessionStorage
    } catch {
      return null
    }
  }
}

export function getAuthToken(): string | null {
  const storage = readStorage()
  if (!storage) {
    return null
  }

  try {
    return storage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

export function setAuthToken(token: string | null): void {
  const storage = readStorage()
  if (!storage) {
    if (token) {
      throw new Error('El navegador bloquea el almacenamiento local. Desactiva modo incógnito o extensiones de privacidad.')
    }
    return
  }

  try {
    if (token) {
      storage.setItem(STORAGE_KEY, token)
    } else {
      storage.removeItem(STORAGE_KEY)
    }
  } catch {
    throw new Error('No se pudo guardar la sesión en el navegador. Recarga con Ctrl+Shift+R e intenta de nuevo.')
  }
}
