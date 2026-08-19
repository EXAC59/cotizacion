import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { DEFAULT_ROLE_PERMISSIONS, seedUsers } from '@/data/rbac-defaults'
import { RbacContext, type RbacContextValue } from '@/context/rbac-context'
import { useAuth } from '@/hooks/useAuth'
import { canAccessModule, normalizeRolePermissions, userHasPermission } from '@/lib/permissions'
import {
  fetchRolePermissions,
  resetRolePermissionsApi,
  updateRolePermissions,
} from '@/lib/rbac-api'
import {
  createAdminUser,
  deleteAdminUser,
  listAdminUsers,
  updateAdminUser,
} from '@/lib/users-api'
import type { UserRole } from '@/types'
import type { RolePermissionMap, StoredUser } from '@/types/rbac'

const STORAGE_KEY = 'cotizacion_rbac'

type RbacStore = {
  users: StoredUser[]
  rolePermissions: RolePermissionMap
}

function normalizeFolioCode(raw?: string): string | undefined {
  if (!raw) return undefined
  const clean = raw.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 12)
  return clean.length >= 2 ? clean : undefined
}

function deriveFolioCodeFromName(name: string): string | undefined {
  const firstWord = name.trim().split(/\s+/)[0] ?? ''
  const fromFirstWord = normalizeFolioCode(firstWord)
  if (fromFirstWord) return fromFirstWord

  return normalizeFolioCode(name)
}

function defaultStore(): RbacStore {
  return {
    users: seedUsers(),
    rolePermissions: structuredClone(DEFAULT_ROLE_PERMISSIONS),
  }
}

/** Repara datos viejos en localStorage (falta admin, permisos corruptos, etc.). */
function normalizeStore(parsed: Partial<RbacStore>): RbacStore {
  const defaults = defaultStore()
  const adminSeed = defaults.users.find((u) => u.role === 'administrador')!

  const users =
    Array.isArray(parsed.users) && parsed.users.length > 0
      ? parsed.users.map((user) => ({
          ...user,
          folioCode: normalizeFolioCode(user.folioCode) ?? deriveFolioCodeFromName(user.name),
        }))
      : [...defaults.users]

  const adminIdx = users.findIndex(
    (u) => u.email.toLowerCase() === adminSeed.email.toLowerCase(),
  )
  if (adminIdx < 0) {
    users.push(adminSeed)
  } else {
    users[adminIdx] = {
      ...adminSeed,
      ...users[adminIdx],
      role: 'administrador',
      active: users[adminIdx].active ?? true,
      password: users[adminIdx].password || adminSeed.password,
    }
  }

  return {
    users,
    rolePermissions: normalizeRolePermissions(parsed.rolePermissions),
  }
}

function loadStore(): RbacStore {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) return normalizeStore(JSON.parse(raw) as Partial<RbacStore>)
  } catch {
    /* defaults */
  }
  return defaultStore()
}

export function RbacProvider({ children }: { children: ReactNode }) {
  const { isAuthenticated } = useAuth()
  const [store, setStore] = useState<RbacStore>(loadStore)
  const [permissionsLoading, setPermissionsLoading] = useState(true)
  const [permissionsSource, setPermissionsSource] = useState<'api' | 'local'>('local')
  const [usersSource, setUsersSource] = useState<'api' | 'local'>('local')
  const [usersLoading, setUsersLoading] = useState(false)

  const persist = useCallback((next: RbacStore) => {
    setStore(next)
    try {
      // File y flags temporales de subida no son serializables.
      const forStorage: RbacStore = {
        ...next,
        users: next.users.map(({ signatureFile: _f, removeSignature: _r, ...user }) => user),
      }
      localStorage.setItem(STORAGE_KEY, JSON.stringify(forStorage))
    } catch {
      /* ignore */
    }
  }, [])

  const refreshUsers = useCallback(async () => {
    if (!isAuthenticated) return
    setUsersLoading(true)
    try {
      const users = await listAdminUsers()
      setStore((prev) => {
        const next = { ...prev, users }
        try {
          localStorage.setItem(STORAGE_KEY, JSON.stringify(next))
        } catch {
          /* ignore */
        }
        return next
      })
      setUsersSource('api')
    } catch {
      setUsersSource('local')
    } finally {
      setUsersLoading(false)
    }
  }, [isAuthenticated])

  useEffect(() => {
    if (!isAuthenticated) {
      setPermissionsLoading(false)
      return
    }

    let cancelled = false
    setPermissionsLoading(true)

    fetchRolePermissions()
      .then((apiPermissions) => {
        if (cancelled || !apiPermissions) return

        setStore((prev) => {
          const next = {
            ...prev,
            rolePermissions: normalizeRolePermissions(apiPermissions),
          }
          try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(next))
          } catch {
            /* ignore */
          }
          return next
        })
        setPermissionsSource('api')
      })
      .catch(() => {
        if (!cancelled) {
          setPermissionsSource('local')
        }
      })
      .finally(() => {
        if (!cancelled) {
          setPermissionsLoading(false)
        }
      })

    void refreshUsers()

    return () => {
      cancelled = true
    }
  }, [isAuthenticated, refreshUsers])

  const saveUser = useCallback(
    async (user: StoredUser) => {
      const folioCode =
        normalizeFolioCode(user.folioCode) ?? deriveFolioCodeFromName(user.name) ?? 'USER'
      const isNew = !store.users.some((u) => u.id === user.id)

      try {
        if (!user.username?.trim()) {
          throw new Error('El nombre de usuario es obligatorio.')
        }
        if (isNew && user.password.trim() === '') {
          throw new Error('La contraseña es obligatoria para un usuario nuevo.')
        }

        const saved = isNew
          ? await createAdminUser({
              name: user.name,
              username: user.username.trim(),
              email: user.email.trim() || undefined,
              role: user.role,
              password: user.password.trim(),
              passwordConfirmation: (user.passwordConfirmation ?? user.password).trim(),
              folioCode,
              active: user.active,
              signatureFile: user.signatureFile ?? null,
            })
          : await updateAdminUser(user.id, {
              name: user.name,
              username: user.username.trim(),
              email: user.email.trim() || undefined,
              role: user.role,
              password: user.password.trim() !== '' ? user.password.trim() : undefined,
              passwordConfirmation:
                user.password.trim() !== ''
                  ? (user.passwordConfirmation ?? user.password).trim()
                  : undefined,
              folioCode,
              active: user.active,
              signatureFile: user.signatureFile ?? null,
              removeSignature: Boolean(user.removeSignature) && !user.signatureFile,
            })

        const users = [...store.users]
        const idx = users.findIndex((u) => u.id === user.id || u.id === saved.id)
        if (idx >= 0) users[idx] = saved
        else users.push(saved)
        persist({ ...store, users })
        setUsersSource('api')
      } catch (err) {
        throw err instanceof Error ? err : new Error('No se pudo guardar el usuario en el servidor.')
      }
    },
    [store, persist],
  )

  const deleteUser = useCallback(
    async (id: string) => {
      try {
        await deleteAdminUser(id)
        persist({ ...store, users: store.users.filter((u) => u.id !== id) })
        setUsersSource('api')
      } catch (err) {
        throw err instanceof Error ? err : new Error('No se pudo eliminar el usuario en el servidor.')
      }
    },
    [store, persist],
  )

  const setRolePermissions = useCallback(
    (role: UserRole, permissions: RolePermissionMap[UserRole]) => {
      persist({
        ...store,
        rolePermissions: normalizeRolePermissions({
          ...store.rolePermissions,
          [role]: permissions,
        }),
      })
    },
    [store, persist],
  )

  const replaceRolePermissions = useCallback(
    async (rolePermissions: RolePermissionMap) => {
      const normalized = normalizeRolePermissions(rolePermissions)

      try {
        const saved = await updateRolePermissions(normalized)
        persist({ ...store, rolePermissions: normalizeRolePermissions(saved) })
        setPermissionsSource('api')
      } catch {
        persist({ ...store, rolePermissions: normalized })
        setPermissionsSource('local')
        throw new Error('No se pudieron guardar los permisos en el servidor.')
      }
    },
    [store, persist],
  )

  const resetRolePermissions = useCallback(async () => {
    try {
      const saved = await resetRolePermissionsApi()
      persist({ ...store, rolePermissions: normalizeRolePermissions(saved) })
      setPermissionsSource('api')
    } catch {
      persist({
        ...store,
        rolePermissions: structuredClone(DEFAULT_ROLE_PERMISSIONS),
      })
      setPermissionsSource('local')
      throw new Error('No se pudieron restaurar los permisos en el servidor.')
    }
  }, [store, persist])

  const value = useMemo<RbacContextValue>(
    () => ({
      users: store.users,
      rolePermissions: store.rolePermissions,
      permissionsLoading,
      permissionsSource,
      usersSource,
      usersLoading,
      refreshUsers,
      saveUser,
      deleteUser,
      setRolePermissions,
      replaceRolePermissions,
      resetRolePermissions,
      can: (module, action, user?) => {
        if (!user) return false
        return userHasPermission(store.rolePermissions, user, module, action)
      },
      canModule: (module, user?) => {
        if (!user) return false
        return canAccessModule(store.rolePermissions, user, module)
      },
    }),
    [
      store,
      permissionsLoading,
      permissionsSource,
      usersSource,
      usersLoading,
      refreshUsers,
      saveUser,
      deleteUser,
      setRolePermissions,
      replaceRolePermissions,
      resetRolePermissions,
    ],
  )

  return <RbacContext.Provider value={value}>{children}</RbacContext.Provider>
}
