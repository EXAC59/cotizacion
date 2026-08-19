import { createContext } from 'react'
import type { AppBranding } from '@/lib/branding-api'
import { DEFAULT_APP_BRANDING } from '@/lib/branding-api'

export type BrandingContextValue = {
  branding: AppBranding
  loading: boolean
  refreshBranding: () => Promise<void>
}

export const BrandingContext = createContext<BrandingContextValue>({
  branding: DEFAULT_APP_BRANDING,
  loading: false,
  refreshBranding: async () => {},
})
