import { useContext } from 'react'
import { RbacContext } from '@/context/rbac-context'

export function useRbac() {
  const ctx = useContext(RbacContext)
  if (!ctx) throw new Error('useRbac debe usarse dentro de RbacProvider')
  return ctx
}
