import type { AppSettings, InventoryCatalogItem, LowStockAlert } from '@/types'

export function computeLowStockAlerts(
  catalog: InventoryCatalogItem[],
  settings: AppSettings,
): LowStockAlert[] {
  return catalog
    .filter((item) => item.stock < settings.minStockAlert)
    .map((item) => ({
      partNumber: item.partNumber,
      product: item.product,
      stock: item.stock,
      warehouse: item.warehouse,
      minimum: settings.minStockAlert,
    }))
    .sort((a, b) => a.stock - b.stock)
}
