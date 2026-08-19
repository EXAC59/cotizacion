import type { UserRole } from '@/types'

export const MODULE_IDS = [
  'dashboard',
  'solicitudes',
  'cotizaciones',
  'clientes',
  'mayoristas',
  'reportes',
  'configuracion',
  'admin',
] as const

export type ModuleId = (typeof MODULE_IDS)[number]

export const PERMISSION_ACTIONS = [
  'view',
  'create',
  'edit',
  'delete',
  'send',
  'approve',
  'edit_margin',
  'import',
  'manage',
] as const

export type PermissionAction = (typeof PERMISSION_ACTIONS)[number]

export const MODULE_LABELS: Record<ModuleId, string> = {
  dashboard: 'Dashboard',
  solicitudes: 'Solicitudes',
  cotizaciones: 'Cotizaciones',
  clientes: 'Clientes',
  mayoristas: 'Mayoristas',
  reportes: 'Reportes',
  configuracion: 'Configuración',
  admin: 'Administración',
}

export const ACTION_LABELS: Record<PermissionAction, string> = {
  view: 'Ver',
  create: 'Crear',
  edit: 'Editar',
  delete: 'Eliminar',
  send: 'Enviar',
  approve: 'Aprobar',
  edit_margin: 'Editar márgenes',
  import: 'Importar',
  manage: 'Gestionar',
}

/** Acciones válidas por módulo */
export const MODULE_ACTIONS: Record<ModuleId, PermissionAction[]> = {
  dashboard: ['view'],
  solicitudes: ['view', 'create', 'edit', 'delete'],
  cotizaciones: ['view', 'create', 'edit', 'delete', 'send', 'approve', 'edit_margin'],
  clientes: ['view', 'create', 'edit', 'delete', 'import'],
  mayoristas: ['view', 'edit'],
  reportes: ['view'],
  configuracion: ['view', 'edit'],
  admin: ['view', 'manage'],
}

export type RolePermissionMap = Record<UserRole, Record<ModuleId, PermissionAction[]>>

export interface StoredUser {
  id: string
  name: string
  username?: string
  email: string
  folioCode?: string
  role: UserRole
  password: string
  passwordConfirmation?: string
  active: boolean
  hasSignature?: boolean
  signatureUrl?: string
  /** Archivo local pendiente de subir (no persiste en localStorage). */
  signatureFile?: File | null
  removeSignature?: boolean
  createdAt: string
}
