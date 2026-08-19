import { apiFetch } from '@/lib/api-fetch'
import { getApiBase } from '@/lib/app-paths'
import type { QuoteLine } from '@/types'
import { quoteTotals } from '@/lib/calculations'

export type BankAccount = {
  bank: string
  account?: string
  clabe?: string
  currency?: string
}

export type CommercialSettings = {
  defaultMarginPercent: number
  taxPercent: number
  quoteValidityDays: number
  unansweredQuoteDays?: number
  minStockAlert: number
  currencyCode: string
  appName?: string | null
  appTagline?: string | null
  companyName?: string | null
  companyLegalName?: string | null
  companyTagline?: string | null
  companyRfc?: string | null
  companyAddress?: string | null
  companyBranches?: string | null
  companyPhone?: string | null
  companyEmail?: string | null
  companyWebsite?: string | null
  quoteTerms?: string | null
  quoteSignatureName?: string | null
  quoteSignatureEmail?: string | null
  quoteFooterAddress?: string | null
  bankAccounts?: BankAccount[]
  logoUrl?: string | null
  updatedAt?: string
}

export type QuoteCalculationResult = {
  globalMarginPercent: number
  taxPercent: number
  lines: QuoteLine[]
  totals: ReturnType<typeof quoteTotals>
  subtotal: number
  taxAmount: number
  total: number
}

export async function getCommercialSettings(): Promise<CommercialSettings> {
  const response = await apiFetch(`${getApiBase()}/configuracion/comercial`)
  const data = (await response.json()) as CommercialSettings & { message?: string }
  if (!response.ok) {
    throw new Error(data.message || `Error al cargar configuración (${response.status})`)
  }
  return data
}

function emptyToNull(value: string | null | undefined): string | null | undefined {
  if (value === undefined) return undefined
  if (value === null) return null
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

function formatApiError(
  data: { message?: string; errors?: Record<string, string[] | string> },
  fallback: string,
): string {
  if (data.errors && typeof data.errors === 'object') {
    const parts = Object.values(data.errors).flatMap((v) => (Array.isArray(v) ? v : [v]))
    if (parts.length > 0) return parts.join(' ')
  }
  return data.message || fallback
}

export async function updateCommercialSettings(
  patch: Partial<CommercialSettings>,
): Promise<CommercialSettings> {
  const response = await apiFetch(`${getApiBase()}/configuracion/comercial`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({
      defaultMarginPercent: patch.defaultMarginPercent,
      taxPercent: patch.taxPercent,
      quoteValidityDays: patch.quoteValidityDays,
      unansweredQuoteDays: patch.unansweredQuoteDays,
      minStockAlert: patch.minStockAlert,
      currencyCode: patch.currencyCode,
      appName: patch.appName,
      appTagline: patch.appTagline,
      companyName: patch.companyName,
      companyLegalName: patch.companyLegalName,
      companyTagline: patch.companyTagline,
      companyRfc: patch.companyRfc,
      companyAddress: patch.companyAddress,
      companyBranches: patch.companyBranches,
      companyPhone: patch.companyPhone,
      companyEmail: emptyToNull(patch.companyEmail),
      companyWebsite: patch.companyWebsite,
      quoteTerms: patch.quoteTerms,
      quoteSignatureName: patch.quoteSignatureName,
      quoteSignatureEmail: emptyToNull(patch.quoteSignatureEmail),
      quoteFooterAddress: patch.quoteFooterAddress,
      bankAccounts: patch.bankAccounts,
    }),
  })
  const data = (await response.json()) as CommercialSettings & {
    message?: string
    errors?: Record<string, string[] | string>
  }
  if (!response.ok) {
    throw new Error(formatApiError(data, `Error al guardar configuración (${response.status})`))
  }
  return data
}

export async function uploadCompanyLogo(file: File): Promise<CommercialSettings> {
  const form = new FormData()
  form.append('logo', file)

  const response = await apiFetch(`${getApiBase()}/configuracion/comercial/logo`, {
    method: 'POST',
    body: form,
  })
  const data = (await response.json()) as CommercialSettings & {
    message?: string
    errors?: Record<string, string[] | string>
  }
  if (!response.ok) {
    throw new Error(formatApiError(data, `Error al subir logotipo (${response.status})`))
  }
  return data
}

export async function calculateQuotePricing(params: {
  globalMarginPercent?: number
  taxPercent?: number
  lines: QuoteLine[]
}): Promise<QuoteCalculationResult> {
  const response = await apiFetch(`${getApiBase()}/cotizaciones/calcular`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({
      globalMarginPercent: params.globalMarginPercent,
      taxPercent: params.taxPercent,
      lines: params.lines.map((line) => ({
        quantity: line.quantity,
        cost: line.cost,
        marginPercent: line.marginPercent,
        salePrice: line.salePrice,
        usesGlobalMargin: line.usesGlobalMargin ?? true,
      })),
    }),
  })
  const data = (await response.json()) as QuoteCalculationResult & { message?: string }
  if (!response.ok) {
    throw new Error(data.message || `Error al calcular cotización (${response.status})`)
  }
  return data
}
