import { apiFetch } from '@/lib/api-fetch'
import { getApiBase } from '@/lib/app-paths'
import type { RolePermissionMap } from '@/types/rbac'

type PermissionsResponse = {
  available?: boolean
  rolePermissions?: RolePermissionMap
  message?: string
}

export async function fetchRolePermissions(): Promise<RolePermissionMap | null> {
  const response = await apiFetch(`${getApiBase()}/rbac/permissions`)
  const data = (await response.json()) as PermissionsResponse

  if (response.status === 503) {
    return null
  }

  if (!response.ok) {
    throw new Error(data.message || `Error al cargar permisos (${response.status})`)
  }

  if (!data.rolePermissions) {
    throw new Error('Respuesta de permisos inválida.')
  }

  return data.rolePermissions
}

export async function updateRolePermissions(
  rolePermissions: RolePermissionMap,
): Promise<RolePermissionMap> {
  const response = await apiFetch(`${getApiBase()}/rbac/permissions`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ rolePermissions }),
  })
  const data = (await response.json()) as PermissionsResponse

  if (!response.ok) {
    throw new Error(data.message || `Error al guardar permisos (${response.status})`)
  }

  if (!data.rolePermissions) {
    throw new Error('Respuesta de permisos inválida.')
  }

  return data.rolePermissions
}

export async function resetRolePermissionsApi(): Promise<RolePermissionMap> {
  const response = await apiFetch(`${getApiBase()}/rbac/permissions/reset`, {
    method: 'POST',
  })
  const data = (await response.json()) as PermissionsResponse

  if (!response.ok) {
    throw new Error(data.message || `Error al restaurar permisos (${response.status})`)
  }

  if (!data.rolePermissions) {
    throw new Error('Respuesta de permisos inválida.')
  }

  return data.rolePermissions
}
