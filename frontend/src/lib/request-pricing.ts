import type { RequestLine, RequestPricingLine, Wholesaler } from '@/types'

const MOCK_COSTS: Record<string, number> = {
  'C9200L-24T-4G-E': 28500,
  'KVR16N11S8/16': 890,
  'U6-PLUS': 4200,
  'WD19': 3200,
  'LAT5540': 24500,
  'CAT6-305': 1850,
}

/** Simula precios/stock que compras envía a ventas. */
export function buildPricingLinesFromRequest(
  lines: RequestLine[],
  wholesalers: Wholesaler[],
): RequestPricingLine[] {
  const active = wholesalers.filter((w) => w.active)
  const fallback = active[0]

  return lines.map((line, index) => {
    const wholesaler = active[index % active.length] ?? fallback
    const cost = MOCK_COSTS[line.partNumber] ?? 1500 + index * 400

    return {
      ...line,
      cost,
      stock: Math.max(0, 12 - index * 2),
      wholesalerName: wholesaler?.name ?? 'Mayorista',
      warehouse: index % 2 === 0 ? 'CDMX' : 'MTY',
    }
  })
}
