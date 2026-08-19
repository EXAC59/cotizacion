export function buildQuoteSearchParams(
  current: URLSearchParams,
  term: string,
): URLSearchParams {
  const next = new URLSearchParams(current)
  const trimmed = term.trim()

  if (trimmed) {
    next.set('q', trimmed)
  } else {
    next.delete('q')
  }

  return next
}

export function isQuotesListPath(pathname: string): boolean {
  return pathname === '/cotizaciones' || pathname === '/cotizaciones/'
}
