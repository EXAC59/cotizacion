import { quoteMatchesSearch } from '@/lib/quote-search'
import { normalizeQuoteStatus } from '@/lib/quote-status'
import type { Quote, QuoteStatus } from '@/types'

export function matchesQuoteListFilters(
  quote: Quote,
  search: string,
  status: QuoteStatus | '',
  from?: string,
  to?: string,
): boolean {
  if (status && normalizeQuoteStatus(quote.status) !== status) {
    return false
  }

  if (from || to) {
    const created = quote.createdAt.slice(0, 10)
    if (from && created < from) return false
    if (to && created > to) return false
  }

  return quoteMatchesSearch(quote, search)
}
