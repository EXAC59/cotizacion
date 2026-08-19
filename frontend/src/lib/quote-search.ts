import type { Quote } from '@/types'

export function parseFolioSearchParts(
  raw: string,
): { text: string; number: number } | null {
  const normalized = raw.trim().toLowerCase().replace(/[^a-z0-9-]+/g, '-')
  const match = normalized.match(/^(.*?)(\d+)$/)
  if (!match) {
    return null
  }

  const text = extractFolioTextPart(match[1])
  const number = Number.parseInt(match[2], 10)

  if (!text && number === 0) {
    return null
  }

  return { text, number }
}

function extractFolioTextPart(beforeNumber: string): string {
  const trimmed = beforeNumber.replace(/^-+|-+$/g, '')
  if (!trimmed) {
    return ''
  }

  const segments = trimmed
    .split('-')
    .filter((segment) => /[a-z]/.test(segment))

  if (segments.length === 0) {
    return ''
  }

  return segments[segments.length - 1] ?? ''
}

export function paddedFolioSuffixes(number: number, maxPad = 6): string[] {
  const base = String(number)
  const suffixes: string[] = []

  for (let pad = base.length; pad <= maxPad; pad++) {
    suffixes.push(base.padStart(pad, '0'))
  }

  return [...new Set(suffixes)]
}

export function quoteMatchesSearch(quote: Quote, search: string): boolean {
  const term = search.trim().toLowerCase()
  if (!term) {
    return true
  }

  const folio = quote.folio.toLowerCase()
  const clientName = quote.clientName?.toLowerCase() ?? ''

  if (folio.includes(term) || clientName.includes(term)) {
    return true
  }

  const parts = parseFolioSearchParts(search)
  if (!parts?.text) {
    return false
  }

  if (!folio.includes(parts.text)) {
    return false
  }

  return paddedFolioSuffixes(parts.number).some(
    (suffix) => folio.includes(`-${suffix}`) || folio.endsWith(suffix),
  )
}
