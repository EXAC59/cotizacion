import { ProductComparatorPanel } from '@/components/quotes/ProductComparatorPanel'
import { useProductComparator } from '@/hooks/useProductComparator'
import type { ComparatorContext } from '@/hooks/useProductComparator'
import type { WholesalerOffer } from '@/types'

export function ProductLineComparator({
  partNumber,
  quantity = 1,
  preferredWarehouse,
  productLabel,
  context,
  enabled = true,
  autoApplyBest = false,
  variant = 'cards',
  selectedWholesalerId,
  onSelectOffer,
  onClearSelection,
  onBestApplied,
}: {
  partNumber: string
  quantity?: number
  preferredWarehouse?: string
  productLabel?: string
  context?: ComparatorContext
  enabled?: boolean
  autoApplyBest?: boolean
  variant?: 'cards' | 'table'
  selectedWholesalerId?: string
  onSelectOffer?: (offer: WholesalerOffer, allOffers?: WholesalerOffer[]) => void
  onClearSelection?: (offer: WholesalerOffer, allOffers?: WholesalerOffer[]) => void
  onBestApplied?: (offer: WholesalerOffer, allOffers?: WholesalerOffer[]) => void
}) {
  void autoApplyBest
  void onBestApplied
  const { result, loading, error, refresh } = useProductComparator({
    partNumber,
    quantity,
    preferredWarehouse,
    enabled,
    context,
  })

  const handleSelect = (offer: WholesalerOffer) => {
    if (result?.offers) {
      onSelectOffer?.(offer, result.offers)
    } else {
      onSelectOffer?.(offer)
    }
  }

  const handleClear = (offer: WholesalerOffer) => {
    if (result?.offers) {
      onClearSelection?.(offer, result.offers)
    } else {
      onClearSelection?.(offer)
    }
  }

  return (
    <ProductComparatorPanel
      partNumber={partNumber}
      productLabel={productLabel}
      comparison={result}
      loading={loading}
      error={error}
      variant={variant}
      selectedWholesalerId={selectedWholesalerId}
      onSelectOffer={onSelectOffer ? handleSelect : undefined}
      onClearSelection={onClearSelection ? handleClear : undefined}
      onRetry={refresh}
    />
  )
}
