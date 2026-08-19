<?php

use App\Services\Wholesalers\LowStock\Sources\CtWatchedSkusLowStockSource;
use App\Services\Wholesalers\LowStock\Sources\CvaCatalogLowStockSource;

/**
 * Poller de stock bajo por mayorista.
 * Al integrar uno nuevo: implementar WholesalerLowStockSource y registrarlo en sources.
 */
return [
    /** Horas de vigencia de un snapshot para mostrarlo en dashboard/mayoristas. */
    'snapshot_ttl_hours' => (int) env('LOW_STOCK_SNAPSHOT_TTL_HOURS', 12),

    /** Tope de filas a persistir por mayorista en cada poll. */
    'per_wholesaler_limit' => (int) env('LOW_STOCK_PER_WHOLESALER_LIMIT', 100),

    /** Días hacia atrás para elegir SKUs a vigilar (CT y futuros sin catálogo). */
    'watched_sku_lookback_days' => (int) env('LOW_STOCK_WATCHED_SKU_DAYS', 30),

    /** Máximo de SKUs a consultar por API en cada poll (CT). */
    'watched_sku_limit' => (int) env('LOW_STOCK_WATCHED_SKU_LIMIT', 40),

    /** Pausa entre lookups API (microsegundos). */
    'api_lookup_usleep' => (int) env('LOW_STOCK_API_USLEEP', 150_000),

    /**
     * Fuentes registradas por code de mayorista.
     * Solo se ejecutan si el mayorista está active + configured.
     *
     * @var array<string, class-string<\App\Contracts\WholesalerLowStockSource>>
     */
    'sources' => [
        'CVA' => CvaCatalogLowStockSource::class,
        'CT' => CtWatchedSkusLowStockSource::class,
    ],
];
