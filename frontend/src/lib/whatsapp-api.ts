import { apiFetch } from '@/lib/api-fetch'
import { getApiBase } from '@/lib/app-paths'

export type WhatsAppTemplate = {
  name: string
  label: string
  body: string
  language: string
  status: string
  id: string | null
  compatible: boolean
  canSend: boolean
}

export type WhatsAppCatalog = {
  configured: boolean
  canCreate: boolean
  connectionError?: string
  data: WhatsAppTemplate[]
}

async function readResponse<T>(response: Response): Promise<T> {
  const data = await response.json() as T & { message?: string; errors?: Record<string, string[]> }
  if (!response.ok) {
    throw new Error(Object.values(data.errors ?? {}).flat()[0] || data.message || 'No se pudo conectar con WhatsApp.')
  }
  return data
}

export async function getWhatsAppTemplates(): Promise<WhatsAppCatalog> {
  return readResponse<WhatsAppCatalog>(await apiFetch(`${getApiBase()}/whatsapp/plantillas`))
}

export async function createWhatsAppTemplate(name: string, sample: File): Promise<void> {
  const body = new FormData()
  body.append('name', name)
  body.append('sample', sample)
  await readResponse(await apiFetch(`${getApiBase()}/whatsapp/plantillas`, { method: 'POST', body }))
}

export async function sendQuoteByWhatsApp(id: string, template: string): Promise<{ message: string; messageId: string }> {
  return readResponse(await apiFetch(`${getApiBase()}/cotizaciones/${encodeURIComponent(id)}/enviar-whatsapp`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ template }),
  }))
}
