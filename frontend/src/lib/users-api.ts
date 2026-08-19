import { apiFetch } from '@/lib/api-fetch'
import { getApiBase } from '@/lib/app-paths'
import type { UserRole } from '@/types'
import type { StoredUser } from '@/types/rbac'

export type AdminUserApi = {
  id: string
  name: string
  email: string
  username?: string | null
  role: UserRole | null
  active: boolean
  folioCode?: string | null
  hasSignature?: boolean
  signatureUrl?: string | null
  createdAt?: string | null
}

export function mapAdminUserToStored(api: AdminUserApi): StoredUser {
  return {
    id: api.id,
    name: api.name,
    username: api.username ?? undefined,
    email: api.email,
    folioCode: api.folioCode ?? undefined,
    role: (api.role ?? 'ventas') as UserRole,
    password: '',
    active: api.active,
    hasSignature: Boolean(api.hasSignature),
    signatureUrl: api.signatureUrl ?? undefined,
    createdAt: api.createdAt ?? new Date().toISOString(),
  }
}

export async function listAdminUsers(): Promise<StoredUser[]> {
  const response = await apiFetch(`${getApiBase()}/admin/users`)
  const data = (await response.json()) as { data?: AdminUserApi[]; message?: string }

  if (!response.ok) {
    throw new Error(data.message || `Error al listar usuarios (${response.status})`)
  }

  return (data.data ?? []).map(mapAdminUserToStored)
}

type UserWritePayload = {
  name: string
  username: string
  email?: string
  role: UserRole
  password?: string
  password_confirmation?: string
  folioCode: string
  active: boolean
  signatureFile?: File | null
  removeSignature?: boolean
}

function validationMessage(data: {
  message?: string
  errors?: Record<string, string[] | string>
}): string {
  if (data.errors) {
    const first = Object.values(data.errors)[0]
    if (Array.isArray(first) && first[0]) return first[0]
    if (typeof first === 'string') return first
  }
  return data.message || 'Error al guardar usuario'
}

function toFormData(payload: UserWritePayload): FormData {
  const body = new FormData()
  body.append('name', payload.name)
  body.append('username', payload.username)
  if (payload.email) body.append('email', payload.email)
  body.append('role', payload.role)
  body.append('folioCode', payload.folioCode)
  body.append('active', payload.active ? '1' : '0')
  if (payload.password) {
    body.append('password', payload.password)
    body.append('password_confirmation', payload.password_confirmation ?? payload.password)
  }
  if (payload.removeSignature) {
    body.append('removeSignature', '1')
  }
  if (payload.signatureFile) {
    body.append('signature', payload.signatureFile)
  }
  return body
}

function toJsonBody(payload: UserWritePayload): Record<string, unknown> {
  const body: Record<string, unknown> = {
    name: payload.name,
    username: payload.username,
    email: payload.email || undefined,
    role: payload.role,
    folioCode: payload.folioCode,
    active: payload.active,
  }
  if (payload.password) {
    body.password = payload.password
    body.password_confirmation = payload.password_confirmation ?? payload.password
  }
  if (payload.removeSignature) {
    body.removeSignature = true
  }
  return body
}

function needsMultipart(payload: UserWritePayload): boolean {
  return Boolean(payload.signatureFile)
}

export async function createAdminUser(payload: {
  name: string
  username: string
  email?: string
  role: UserRole
  password: string
  passwordConfirmation: string
  folioCode: string
  active: boolean
  signatureFile?: File | null
}): Promise<StoredUser> {
  const write: UserWritePayload = {
    name: payload.name,
    username: payload.username,
    email: payload.email || undefined,
    role: payload.role,
    password: payload.password,
    password_confirmation: payload.passwordConfirmation,
    folioCode: payload.folioCode,
    active: payload.active,
    signatureFile: payload.signatureFile,
  }

  const multipart = needsMultipart(write)
  const response = await apiFetch(`${getApiBase()}/admin/users`, {
    method: 'POST',
    headers: multipart
      ? { Accept: 'application/json' }
      : { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: multipart ? toFormData(write) : JSON.stringify(toJsonBody(write)),
  })

  const data = (await response.json()) as AdminUserApi & {
    message?: string
    errors?: Record<string, string[] | string>
  }

  if (!response.ok) {
    throw new Error(validationMessage(data) || `Error al crear usuario (${response.status})`)
  }

  return mapAdminUserToStored(data)
}

export async function updateAdminUser(
  id: string,
  payload: {
    name: string
    username: string
    email?: string
    role: UserRole
    password?: string
    passwordConfirmation?: string
    folioCode: string
    active: boolean
    signatureFile?: File | null
    removeSignature?: boolean
  },
): Promise<StoredUser> {
  const write: UserWritePayload = {
    name: payload.name,
    username: payload.username,
    email: payload.email || undefined,
    role: payload.role,
    folioCode: payload.folioCode,
    active: payload.active,
    signatureFile: payload.signatureFile,
    removeSignature: payload.removeSignature,
  }
  if (payload.password) {
    write.password = payload.password
    write.password_confirmation = payload.passwordConfirmation ?? payload.password
  }

  // PHP no parsea multipart en PUT; usar POST + _method=PUT cuando hay archivo.
  const multipart = needsMultipart(write)
  if (multipart) {
    const form = toFormData(write)
    form.append('_method', 'PUT')
    const response = await apiFetch(`${getApiBase()}/admin/users/${id}`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
      body: form,
    })
    const data = (await response.json()) as AdminUserApi & {
      message?: string
      errors?: Record<string, string[] | string>
    }
    if (!response.ok) {
      throw new Error(validationMessage(data) || `Error al actualizar usuario (${response.status})`)
    }
    return mapAdminUserToStored(data)
  }

  const response = await apiFetch(`${getApiBase()}/admin/users/${id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(toJsonBody(write)),
  })

  const data = (await response.json()) as AdminUserApi & {
    message?: string
    errors?: Record<string, string[] | string>
  }

  if (!response.ok) {
    throw new Error(validationMessage(data) || `Error al actualizar usuario (${response.status})`)
  }

  return mapAdminUserToStored(data)
}

export async function deleteAdminUser(id: string): Promise<void> {
  const response = await apiFetch(`${getApiBase()}/admin/users/${id}`, {
    method: 'DELETE',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    const data = (await response.json().catch(() => ({}))) as { message?: string }
    throw new Error(data.message || `Error al eliminar usuario (${response.status})`)
  }
}
