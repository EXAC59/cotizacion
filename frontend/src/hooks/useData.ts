import { useContext } from 'react'
import { DataContext } from '@/context/data-context'

export function useData() {
  const ctx = useContext(DataContext)
  if (!ctx) throw new Error('useData debe usarse dentro de DataProvider')
  return ctx
}
