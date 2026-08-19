import { quoteTotals } from '@/lib/calculations'
import { formatCurrency, formatPercent } from '@/lib/format'
import type { QuoteLine } from '@/types'

export function QuoteTotalsPanel({
  lines,
  taxPercent,
  showProfit = true,
}: {
  lines: QuoteLine[]
  taxPercent: number
  /** Si false, oculta la fila Utilidad (p. ej. rol ventas). */
  showProfit?: boolean
}) {
  const t = quoteTotals(lines, taxPercent)

  return (
    <dl className="space-y-2 text-sm">
      <div className="flex justify-between">
        <dt className="text-slate-500">Subtotal</dt>
        <dd className="font-medium">{formatCurrency(t.subtotal)}</dd>
      </div>
      <div className="flex justify-between">
        <dt className="text-slate-500">Costo total</dt>
        <dd>{formatCurrency(t.totalCost)}</dd>
      </div>
      {showProfit && (
        <div className="flex justify-between text-emerald-700">
          <dt>Utilidad</dt>
          <dd className="font-medium">
            {formatCurrency(t.totalProfit)} ({formatPercent(t.profitPercent)})
          </dd>
        </div>
      )}
      <div className="flex justify-between">
        <dt className="text-slate-500">IVA ({taxPercent}%)</dt>
        <dd>{formatCurrency(t.tax)}</dd>
      </div>
      <div className="flex justify-between border-t border-slate-200 pt-2 text-base">
        <dt className="font-semibold text-slate-900">Total</dt>
        <dd className="font-bold text-slate-900">{formatCurrency(t.total)}</dd>
      </div>
    </dl>
  )
}
