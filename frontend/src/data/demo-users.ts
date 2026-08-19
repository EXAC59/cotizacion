import type { User, UserRole } from '@/types'

export type DemoAccount = User & { password: string }

/** Usuarios locales hasta conectar login con la API. */
export const DEMO_ACCOUNTS: DemoAccount[] = [
  {
    id: 'u-admin',
    name: 'Administrador del Sistema',
    username: 'admin',
    email: 'admin@cotizacion.test',
    folioCode: 'ADMIN',
    password: 'admin123',
    role: 'administrador',
  },
  {
    id: 'u-compras',
    name: 'Luis Ramírez',
    username: 'compras',
    email: 'compras@cotizacion.test',
    folioCode: 'LUIS',
    password: 'compras123',
    role: 'gerente_compras',
  },
  {
    id: 'u-ventas',
    name: 'María González',
    username: 'maria',
    email: 'maria@empresa.com',
    folioCode: 'MARIA',
    password: 'demo',
    role: 'ventas',
  },
]

export function findDemoAccount(login: string, password: string): User | null {
  const normalized = login.trim().toLowerCase()
  const account = DEMO_ACCOUNTS.find(
    (a) =>
      (a.username?.toLowerCase() === normalized || a.email.toLowerCase() === normalized) &&
      a.password === password,
  )
  if (!account) return null
  return {
    id: account.id,
    name: account.name,
    username: account.username,
    email: account.email,
    folioCode: account.folioCode,
    role: account.role,
    active: true,
  }
}

export function getDemoAccountsByRole(role?: UserRole): Omit<DemoAccount, 'password'>[] {
  const list = role ? DEMO_ACCOUNTS.filter((a) => a.role === role) : DEMO_ACCOUNTS
  return list.map(({ id, name, username, email, folioCode, role: r }) => ({
    id,
    name,
    username,
    email,
    folioCode,
    role: r,
  }))
}
