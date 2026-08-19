import type { RequestLine } from '@/types'

const CATEGORY_WORDS = new Set([
  'monitor',
  'teclado',
  'switch',
  'cable',
  'router',
  'impresora',
  'toner',
  'tóner',
  'memoria',
  'mouse',
  'ratón',
  'raton',
  'disco',
  'laptop',
  'servidor',
  'fuente',
  'audifonos',
  'audífonos',
  'webcam',
  'bocina',
  'scanner',
  'escaner',
  'tablet',
  'proyector',
  'ups',
  'rack',
  'cartucho',
  'tinta',
])

/** Vista previa local de texto libre antes de enviar a la API. */
export function parseRequestLinesFromText(text: string): RequestLine[] {
  return text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line, index) => {
      const match = line.match(/^(\d+)\s+(.+)$/)
      const quantity = match ? Number(match[1]) : 1
      const rest = match ? match[2].trim() : line
      const partNumber = guessSkuFromText(rest) ?? `SKU-${index + 1}`
      const product = rest.trim() || rest

      return {
        id: `pl${index + 1}`,
        quantity,
        product,
        partNumber,
        brand: guessBrand(product, partNumber),
        description: product,
        unit: 'pza',
      }
    })
}

/** Snapshot de auditoría / raw_text al guardar partidas capturadas en tabla. */
export function linesToMarkdownTable(lines: RequestLine[]): string {
  const rows = lines
    .filter(
      (l) =>
        l.quantity > 0 &&
        l.product.trim().length >= 2 &&
        l.partNumber.trim() !== '',
    )
    .map(
      (l) =>
        `| ${l.quantity} | ${l.product.trim()} | ${l.partNumber.trim()} | ${(l.brand || 'Genérico').trim()} |`,
    )

  if (rows.length === 0) return ''

  return [
    '| CANTIDAD | PRODUCTO | NO.PARTE | MARCA |',
    '| --- | --- | --- | --- |',
    ...rows,
  ].join('\n')
}

export function usableFreeTextLines(lines: RequestLine[]): RequestLine[] {
  return lines.filter(
    (l) =>
      l.quantity > 0 &&
      l.product.trim().length >= 5 &&
      l.partNumber.trim() !== '' &&
      !/^LINE-\d+$/i.test(l.partNumber.trim()),
  )
}

function emptyFreeTextLine(): RequestLine {
  return {
    id: `tl-${crypto.randomUUID()}`,
    quantity: 1,
    product: '',
    partNumber: '',
    brand: '',
    description: '',
    unit: 'pza',
  }
}

export function createEmptyFreeTextLines(count = 1): RequestLine[] {
  return Array.from({ length: Math.max(1, count) }, () => emptyFreeTextLine())
}

function guessSkuFromText(text: string): string | null {
  const matches = text.match(/\b([A-Z0-9][A-Z0-9\-+/]{2,})\b/gi)
  if (!matches) return null

  let best: string | null = null
  let bestScore = -1

  for (const raw of matches) {
    const candidate = raw.trim()
    if (!candidate) continue

    const lower = candidate.toLowerCase()
    if (CATEGORY_WORDS.has(lower) || !isLikelySkuToken(candidate)) continue

    let score = 0
    if (/\d/.test(candidate)) score += 12
    if (/[-/]/.test(candidate)) score += 6
    if (candidate.length >= 6) score += 3
    if (candidate.length >= 4) score += 1

    if (score > bestScore) {
      bestScore = score
      best = candidate
    }
  }

  return best
}

const SPEC_WORDS = new Set([
  'pulgadas',
  'pulg',
  'metros',
  'metro',
  'piezas',
  'pieza',
  'unidad',
  'unidades',
  'watts',
  'watt',
  'meses',
  'dias',
  'días',
  'anos',
  'años',
])

function isLikelySkuToken(text: string): boolean {
  if (!/^[A-Z0-9][A-Z0-9\-+/]{3,}$/i.test(text)) return false
  if (SPEC_WORDS.has(text.toLowerCase())) return false
  return /\d/.test(text) || /[-/]/.test(text)
}

function guessBrand(product: string, sku: string): string {
  const p = `${product} ${sku}`.toLowerCase()
  if (p.includes('cisco')) return 'Cisco'
  if (p.includes('logitech')) return 'Logitech'
  if (p.includes('kingston')) return 'Kingston'
  if (p.includes('ubiquiti') || sku.includes('U6')) return 'Ubiquiti'
  if (p.includes('dell')) return 'Dell'
  if (p.includes('hp ')) return 'HP'
  return 'Genérico'
}
