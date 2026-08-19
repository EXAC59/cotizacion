import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { BrandingContext } from '@/context/branding-context'
import { useAuth } from '@/hooks/useAuth'
import {
  DEFAULT_APP_BRANDING,
  getAppBranding,
  getInitialBranding,
  readCachedBranding,
  type AppBranding,
} from '@/lib/branding-api'

export function BrandingProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const [branding, setBranding] = useState<AppBranding>(() => getInitialBranding())
  const [loading, setLoading] = useState(false)

  const refreshBranding = useCallback(async () => {
    setLoading(true)
    try {
      const next = await getAppBranding()
      setBranding(next)
      document.title = next.appName
    } catch {
      const cached = readCachedBranding()
      setBranding(cached ?? DEFAULT_APP_BRANDING)
      if (cached) document.title = cached.appName
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void refreshBranding()
  }, [refreshBranding, user?.id])

  const value = useMemo(
    () => ({ branding, loading, refreshBranding }),
    [branding, loading, refreshBranding],
  )

  return <BrandingContext.Provider value={value}>{children}</BrandingContext.Provider>
}
