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

  if (data.message) {
    return data.message
  }

  return `Error del servidor (${status}).`
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
