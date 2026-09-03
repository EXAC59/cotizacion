export type ApiErrorBody = {
  message?: string
  errors?: Record<string, string[] | string>
}

export class ClientRequiredApiError extends Error {
  constructor() {
    super('Debes asignar un cliente para poder guardar la solicitud.')
    this.name = 'ClientRequiredApiError'
  }
}

export class ApiRequestError extends Error {
  readonly status: number
  readonly errors?: Record<string, string[] | string>

  constructor(
    message: string,
    status: number,
    errors?: Record<string, string[] | string>,
  ) {
    super(message)
    this.name = 'ApiRequestError'
    this.status = status
    this.errors = errors
  }
}

export function formatApiErrorMessage(data: ApiErrorBody, status: number): string {
  const errors = data.errors ?? {}

  if (errors.client_id) {
    return 'Debes asignar un cliente para poder guardar la solicitud.'
  }

  const columnas = errors.columnas_faltantes
  if (Array.isArray(columnas) && columnas.length > 0) {
    return `El archivo debe incluir columnas: ${columnas.join(', ')}.`
  }

  const fieldMessage = firstValidationErrorMessage(errors)
  if (fieldMessage) {
    return fieldMessage
  }

  const message = typeof data.message === 'string' ? data.message.trim() : ''
  if (message && !looksLikeValidationKey(message)) {
    return message
  }

  return `Error del servidor (${status}).`
}

function looksLikeValidationKey(message: string): boolean {
  return /^validation\.[a-z0-9_.]+$/i.test(message)
}

function firstValidationErrorMessage(
  errors: Record<string, string[] | string>,
): string | null {
  for (const [key, value] of Object.entries(errors)) {
    const raw = Array.isArray(value) ? value[0] : value
    if (typeof raw !== 'string' || raw.trim() === '') continue
    if (!looksLikeValidationKey(raw)) {
      return raw
    }
    return humanizeValidationKey(key, raw)
  }
  return null
}

function humanizeValidationKey(field: string, key: string): string {
  const lineMatch = field.match(/^lineas\.(\d+)\.(.+)$/i)
  if (lineMatch) {
    const row = Number(lineMatch[1]) + 1
    const attr = lineMatch[2]
    if (attr === 'product' && key.includes('required')) {
      return `La partida ${row} necesita un producto.`
    }
    if (attr === 'quantity' && (key.includes('required') || key.includes('min'))) {
      return `La partida ${row} necesita una cantidad válida.`
    }
    if (attr === 'partNumber') {
      return `Revisa el número de parte de la partida ${row}.`
    }
    if (attr === 'brand') {
      return `Revisa la marca de la partida ${row}.`
    }
    return `Revisa la partida ${row} (${attr}).`
  }

  if (key.includes('required')) {
    return 'Falta un dato obligatorio. Revisa las partidas.'
  }

  return 'Hay datos inválidos. Revisa el formulario.'
}

export async function parseApiJson<T>(response: Response): Promise<T> {
  const text = await response.text()
  const trimmed = text.trim()

  if (trimmed.startsWith('<!DOCTYPE') || trimmed.startsWith('<html')) {
    throw new Error(
      `El servidor respondió HTML en lugar de JSON (HTTP ${response.status}). Inténtalo de nuevo.`,
    )
  }

  if (!trimmed) {
    throw new Error(`Respuesta vacía del servidor (HTTP ${response.status}).`)
  }

  try {
    return JSON.parse(trimmed) as T
  } catch {
    throw new Error(`Respuesta no válida del servidor (HTTP ${response.status}).`)
  }
}

export async function parseApiJsonOrThrow<T extends ApiErrorBody>(
  response: Response,
): Promise<T> {
  const data = await parseApiJson<T>(response)

  if (!response.ok) {
    if (data.errors?.client_id) {
      throw new ClientRequiredApiError()
    }
    throw new ApiRequestError(formatApiErrorMessage(data, response.status), response.status, data.errors)
  }

  return data
}
