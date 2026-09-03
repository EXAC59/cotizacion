const RELOAD_ONCE_KEY = 'spa-build-reload-for'

/**
 * Recarga a lo sumo una vez por build. Evita el bucle login ↔ “Verificando sesión”
 * cuando API y SPA tienen build-id distintos.
 */
export function reloadOnceForSpaBuild(): void {
  if (typeof window === 'undefined') {
    return
  }

  const current = typeof __SPA_BUILD_ID__ === 'string' ? __SPA_BUILD_ID__ : ''
  try {
    if (sessionStorage.getItem(RELOAD_ONCE_KEY) === current) {
      return
    }
    sessionStorage.setItem(RELOAD_ONCE_KEY, current)
  } catch {
    return
  }

  window.location.reload()
}
