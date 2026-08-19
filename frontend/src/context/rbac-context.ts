import { createContext } from 'react'
import type { User, UserRole } from '@/types'
import type { ModuleId, PermissionAction, RolePermissionMap, StoredUser } from '@/types/rbac'

export interface RbacContextValue {
  users: StoredUser[]
  rolePermissions: RolePermissionMap
  permissionsLoading: boolean
  permissionsSource: 'api' | 'local'
  usersSource: 'api' | 'local'
  usersLoading: boolean
  refreshUsers: () => Promise<void>
  saveUser: (user: StoredUser) => Promise<void>
  deleteUser: (id: string) => Promise<void>
  setRolePermissions: (role: UserRole, permissions: RolePermissionMap[UserRole]) => void
  replaceRolePermissions: (permissions: RolePermissionMap) => Promise<void>
  resetRolePermissions: () => Promise<void>
  can: (module: ModuleId, action: PermissionAction, user?: User | null) => boolean
  canModule: (module: ModuleId, user?: User | null) => boolean
}

export const RbacContext = createContext<RbacContextValue | null>(null)
