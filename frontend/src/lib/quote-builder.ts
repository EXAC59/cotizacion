import { recalcLine } from '@/lib/calculations'
import type { QuoteLine, QuoteRequest, RequestLine, RequestPricingLine } from '@/types'

/**
 * Construye las partidas de una cotización a partir de una solicitud.
 * Copia cantidad/producto/no. parte/mayorista y toma el costo del precio de
 * compras (si existe) o del costo de referencia de la línea; aplica el margen global.
 */
export function buildLinesFromRequest(req: QuoteRequest, globalMargin: number): QuoteLine[] {
  const source: (RequestPricingLine | NonNullable<QuoteRequest['lines']>[number])[] =
    req.pricingLines?.length ? req.pricingLines : (req.lines ?? [])
  if (!source.length) return []

  return source.map((l) => {
    const priced = l as RequestPricingLine & RequestLine
    const cost =
      ('cost' in priced && priced.cost > 0 ? priced.cost : 0) ||
      (l.referenceCost && l.referenceCost > 0 ? l.referenceCost : 0)
    const warehouse = priced.warehouse ?? l.warehouse ?? 'CDMX'

    return recalcLine(
      {
        id: l.id,
        quantity: l.quantity,
        product: l.product,
        partNumber: l.partNumber,
        cost,
        marginPercent: globalMargin,
        salePrice: 0,
        amount: 0,
        warehouse,
        usesGlobalMargin: true,
        selectedWholesalerId: l.selectedWholesalerId,
      },
      globalMargin,
    )
  })
}
