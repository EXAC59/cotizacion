export type ViewerListScope = 'mine' | 'all'

export function defaultViewerListScope(role?: string | null): ViewerListScope {
  return role === 'ventas' || role === 'gerente_compras' ? 'mine' : 'all'
}

export function parseViewerListScope(value: string | null, role?: string | null): ViewerListScope {
  if (value === 'all' || value === 'team') {
    return 'all'
  }
  if (value === 'mine') {
    return 'mine'
  }
  return defaultViewerListScope(role)
}
