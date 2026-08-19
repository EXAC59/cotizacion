import { quoteMatchesSearch } from '@/lib/quote-search'
import { normalizeQuoteStatus } from '@/lib/quote-status'
import type { Quote, QuoteStatus } from '@/types'

export function matchesQuoteListFilters(
  quote: Quote,
  search: string,
  status: QuoteStatus | '',
): boolean {
  if (status && normalizeQuoteStatus(quote.status) !== status) {
    return false
  }

  return quoteMatchesSearch(quote, search)
}
