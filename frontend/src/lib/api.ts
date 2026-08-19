import { apiFetch } from '@/lib/api-fetch'
import { getApiBase } from '@/lib/app-paths'

const API_BASE = getApiBase()

export type HealthResponse = {
  app: string
  status: string
  stack: {
    backend: string
    database: string
    frontend: string
    automation: string
  }
  services: {
    database: { ok: boolean; error: string | null }
    n8n: { configured: boolean; base_url: string }
  }
}

export async function fetchHealth(): Promise<HealthResponse> {
  const response = await apiFetch(`${API_BASE}/health`)
  if (!response.ok) {
    throw new Error(`API error: ${response.status}`)
  }
  return response.json() as Promise<HealthResponse>
}
