---
name: comparador-precios
description: >-
  Comparador de precios y mayoristas (CVA, CT, EXEL, INGRAM, ASC): catálogos,
  conectores, pesos, warehouse, alias BODEGAxx. Usar cuando la tarea involucre
  el comparador, mayoristas, catálogos CT/CVA, preferencias de almacén, el
  endpoint /api/comparador o el módulo Mayoristas.
---

# Comparador de precios — mayoristas

## API

- `POST /api/comparador/disparar` — dispara comparación
- `GET /api/comparador/{id}` — ver job (`ComparisonJob`)
- `GET /api/mayoristas` y `/api/mayoristas/comparar` — listar / comparar
- `GET /api/mayoristas/ct/autocomplete?sku=&descripcion=` — catálogo CT (AND si ambos)
- `GET /api/mayoristas/cva/autocomplete` — autocomplete CVA
- Post `/api/mayoristas/{id}/consultar` — consultar un mayorista
- Permisos: `permission:mayoristas,view` (consulta) y `permission:cotizaciones,create|edit` (autocomplete)

## Integracions de mayoristas

| Code | Conector | Tipo |
|------|----------|------|
| CT | `CtInternacionalConnector` | FTP catálogo + índice local (`CtCatalogIndex`) |
| CVA | `CvaConnector` | API (`CvaCatalogIndex`) |
| EXEL | `CsvWholesalerConnector` | CSV |
| INGRAM | `ApiWholesalerConnector` / scraping | API/CSV |
| ASC | `ScrapingWholesalerConnector` | scraping/XML |

- Factory: `WholesalerConnectorFactory`; contrato `WholesalerConnector`.
- Config credenciales por `env_prefix` (`WHOLESALER_CT_*`, `WHOLESALER_CVA_*`) en `docker-compose.yml`.

## Reglas

1. **CT**: si vienen `sku` y `descripción` deben coincidir en **ambos** (`CtCatalogIndex::searchMatching`). Lookup con `candidateClaves`; si hay **≥3** claves, analizar todas. No inventar endpoint de búsqueda CT.
2. **Pesos** (suman 1.0): price 0.45, stock 0.25, warehouse 0.15, performance 0.10, preferred 0.05. `lead_day_penalty` 0.005, `import_penalty` 0.08.
3. **Warehouse**: prioridad desde `quote_comparator.warehouse_priority` (catálogo CT + hubs). Preferencias por usuario en `UserComparatorPreference` (incluye `preferred_warehouses`).
4. **Ofertas demo**: `WHOLESALER_DEMO_OFFERS=false` en producción.
5. **Alias ventas**: rol ventas solo ve `BODEGAxx` (`WholesalerSalesAliasService`), y `warehouse` sin ciudad. Admin/compras ven el nombre real.

## Archivos clave

- `app/Services/Wholesalers/` (WholesalerComparatorService, WholesalerConnectorFactory, ComparatorJobService, OfferNormalizer, WholesalerLookupService, WholesalerPerformanceService, CtCatalogIndex, CvaCatalogIndex, CtApiCatalogSupplement, LowStock/*)
- `app/Services/Wholesalers/Connectors/*`
- `app/Models/Wholesaler.php`, `ComparisonJob.php`, `ComparatorSetting.php`, `UserComparatorPreference.php`
- Configs: `wholesalers.php`, `ct_warehouses.php`, `cva_warehouses.php`, `quote_comparator.php`, `wholesaler_sales_aliases.php`, `ct_part_aliases.php`
- Front: `frontend/src/lib/wholesalers-api.ts`, `comparator-api.ts`, `wholesaler-display.ts`, `preferred-warehouses.ts`; `frontend/src/components/quotes/WholesalerCompare.tsx`, `ProductComparatorPanel.tsx`, `ProductLineComparator.tsx`, `CtSkuAutocompleteInput.tsx`

## Verificación

- Tests: `CvaConnectorTest`, `CtInternacionalConnectorTest`, `CtCatalogIndexTest`, `CvaCatalogIndexTest`, `CvaWarehouseDirectoryTest`, `CtWarehouseDirectoryTest`, `OfferNormalizerFreightTest`, `CsvWholesalerConnectorTest`, `ApiWholesalerConnectorTest`, `WholesalerComparatorTest`, `ComparadorJobTest`, `ComparatorSettingsTest`, `WholesalerLookupParallelTest`, `MayoristaApiTest`, `LowStockPollTest`, `WholesalerSalesAliasServiceTest`.