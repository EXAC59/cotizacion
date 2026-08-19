/** ID local para borradores (funciona sin HTTPS; crypto.randomUUID exige contexto seguro). */
export function newLocalId(prefix = ''): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return `${prefix}${crypto.randomUUID()}`
  }

  return `${prefix}${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 11)}`
}
