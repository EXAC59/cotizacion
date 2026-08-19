import type { QuoteLine } from '@/types'

export const DEFAULT_MARGIN = 30
export const DEFAULT_TAX = 16

const PRICE_DECIMALS = 4
const MARGIN_DECIMALS = 2

function roundPrice(value: number): number {
  const factor = 10 ** PRICE_DECIMALS
  return Math.round(value * factor) / factor
}

function roundMargin(value: number): number {
  const factor = 10 ** MARGIN_DECIMALS
  return Math.round(value * factor) / factor
}

export function salePriceFromCost(cost: number, marginPercent: number): number {
  if (cost <= 0) return 0
  return roundPrice(cost * (1 + marginPercent / 100))
}

export function marginFromCostAndSalePrice(cost: number, salePrice: number): number {
  if (cost <= 0 || salePrice <= 0) return 0
  return roundMargin(((salePrice - cost) / cost) * 100)
}

export function lineAmount(quantity: number, salePrice: number): number {
  return roundPrice(quantity * salePrice)
}

export function lineProfit(quantity: number, cost: number, salePrice: number): number {
  return roundPrice(quantity * (salePrice - cost))
}

export function lineProfitForLine(line: QuoteLine): number {
  return lineProfit(line.quantity, line.cost, line.salePrice)
}

export function recalcLine(line: QuoteLine, globalMargin?: number): QuoteLine {
  const margin = line.marginPercent ?? globalMargin ?? DEFAULT_MARGIN
  const salePrice = salePriceFromCost(line.cost, margin)
  return {
    ...line,
    marginPercent: margin,
    salePrice,
    amount: lineAmount(line.quantity, salePrice),
  }
}

/** Ajusta margen % a partir de un precio de venta manual. */
export function recalcLineFromSalePrice(line: QuoteLine, salePrice: number): QuoteLine {
  const price = Math.max(0, salePrice)
  const margin = marginFromCostAndSalePrice(line.cost, price)
  return {
    ...line,
    marginPercent: margin,
    salePrice: roundPrice(price),
    amount: lineAmount(line.quantity, price),
    usesGlobalMargin: false,
  }
}

/** Sincroniza partidas que siguen el margen global cuando cambia el % global. */
export function syncGlobalMarginLines(lines: QuoteLine[], globalMargin: number): QuoteLine[] {
  return lines.map((line) => {
    if (line.usesGlobalMargin === false) return line
    return recalcLine({ ...line, marginPercent: globalMargin, usesGlobalMargin: true }, globalMargin)
  })
}

/** Aplica margen global a todas las partidas o solo a las que lo usan. */
export function applyGlobalMarginToLines(
  lines: QuoteLine[],
  globalMargin: number,
  mode: 'all' | 'globalOnly' = 'all',
): QuoteLine[] {
  return lines.map((line) => {
    if (mode === 'globalOnly' && line.usesGlobalMargin === false) {
      return recalcLine(line)
    }
    return recalcLine(
      { ...line, marginPercent: globalMargin, usesGlobalMargin: true },
      globalMargin,
    )
  })
}

export function quoteTotals(
  lines: QuoteLine[],
  taxPercent: number,
): {
  subtotal: number
  tax: number
  total: number
  totalCost: number
  totalProfit: number
  profitPercent: number
} {
  const subtotal = roundPrice(lines.reduce((s, l) => s + l.amount, 0))
  const totalCost = roundPrice(lines.reduce((s, l) => s + l.quantity * l.cost, 0))
  const totalProfit = roundPrice(subtotal - totalCost)
  const tax = roundPrice(subtotal * (taxPercent / 100))
  const total = roundPrice(subtotal + tax)
  const profitPercent = totalCost > 0 ? roundMargin((totalProfit / totalCost) * 100) : 0
  return { subtotal, tax, total, totalCost, totalProfit, profitPercent }
}
