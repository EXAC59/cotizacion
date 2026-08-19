import { createContext } from 'react'

import type { User } from '@/types'



export interface AuthContextValue {

  user: User | null

  isAuthenticated: boolean

  authLoading: boolean

  login: (username: string, password: string) => Promise<User | null>

  logout: () => Promise<void>

  clearSession: () => void

}



export const AuthContext = createContext<AuthContextValue | null>(null)

