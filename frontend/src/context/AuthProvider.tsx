import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'

import { AuthContext } from '@/context/auth-context'

import { fetchCurrentUser, loginRequest, logoutRequest } from '@/lib/auth-api'
import { setAuthToken } from '@/lib/auth-token'

import { setUnauthorizedHandler } from '@/lib/api-fetch'

import type { User } from '@/types'



export function AuthProvider({ children }: { children: ReactNode }) {

  const [user, setUser] = useState<User | null>(null)

  const [authLoading, setAuthLoading] = useState(true)

  const userRef = useRef<User | null>(null)

  useEffect(() => {
    userRef.current = user
  }, [user])

  const clearSession = useCallback(() => {
    setAuthToken(null)
    setUser(null)
  }, [])

  useEffect(() => {
    setUnauthorizedHandler(() => {
      if (userRef.current) {
        clearSession()
      }
    })

    let cancelled = false

    fetchCurrentUser()
      .then((sessionUser) => {
        if (!cancelled) {
          setUser(sessionUser)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setAuthLoading(false)
        }
      })

    return () => {
      cancelled = true
      setUnauthorizedHandler(null)
    }
  }, [clearSession])

  useEffect(() => {
    if (!user) return

    const keepAliveMs = 30 * 60 * 1000
    const timer = window.setInterval(() => {
      void fetchCurrentUser().then((sessionUser) => {
        if (!sessionUser) {
          clearSession()
        }
      })
    }, keepAliveMs)

    return () => window.clearInterval(timer)
  }, [user, clearSession])



  const login = useCallback(async (username: string, password: string): Promise<User | null> => {
    const logged = await loginRequest(username, password)

    if (!logged) {
      return null
    }

    const sessionUser = await fetchCurrentUser()
    const user = sessionUser ?? logged
    setUser(user)

    return user
  }, [])



  const logout = useCallback(async () => {

    try {

      await logoutRequest()

    } catch {

      /* ignore network errors on logout */

    } finally {

      clearSession()

    }

  }, [clearSession])



  const value = useMemo(

    () => ({

      user,

      isAuthenticated: !!user,

      authLoading,

      login,

      logout,

      clearSession,

    }),

    [user, authLoading, login, logout, clearSession],

  )



  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>

}

