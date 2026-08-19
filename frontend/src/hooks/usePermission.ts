import { useAuth } from '@/hooks/useAuth'
import { useRbac } from '@/hooks/useRbac'
import {
  canApproveQuotes,
  canConfigIntegrations,
  canConsultInventory,
  canCreateQuotes,
  canEditCommercialSettings,
  canEditMargins,
  canImportClients,
  canManageClients,
  canManageUsers,
  canSendQuotes,
  canViewReports,
  canViewWholesalerIntegrationAlerts,
} from '@/lib/capabilities'
import { canViewDashboardExecutive } from '@/lib/permissions'
import type { ModuleId, PermissionAction } from '@/types/rbac'

export function usePermission() {
  const { user } = useAuth()
  const { can, canModule, rolePermissions } = useRbac()

  return {
    user,
    rolePermissions,
    can: (module: ModuleId, action: PermissionAction) => can(module, action, user),
    canModule: (module: ModuleId) => canModule(module, user),
    isAdmin: user?.role === 'administrador',
    canCreateQuotes: () => canCreateQuotes(rolePermissions, user),
    canEditMargins: () => canEditMargins(rolePermissions, user),
    canApproveQuotes: () => canApproveQuotes(rolePermissions, user),
    canSendQuotes: () => canSendQuotes(rolePermissions, user),
    canConsultInventory: () => canConsultInventory(rolePermissions, user),
    canManageClients: () => canManageClients(rolePermissions, user),
    canImportClients: () => canImportClients(rolePermissions, user),
    canViewReports: () => canViewReports(rolePermissions, user),
    canManageUsers: () => canManageUsers(rolePermissions, user),
    canConfigIntegrations: () => canConfigIntegrations(rolePermissions, user),
    canEditCommercialSettings: () => canEditCommercialSettings(user),
    canViewWholesalerIntegrationAlerts: () => canViewWholesalerIntegrationAlerts(user),
    canViewDashboardExecutive: () => canViewDashboardExecutive(rolePermissions, user),
  }
}
