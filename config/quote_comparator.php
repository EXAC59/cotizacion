<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pesos del comparador (deben sumar 1.0)
    | Precio, disponibilidad (stock) y proximidad de almacén.
    |--------------------------------------------------------------------------
    */
    'weights' => [
        'price' => 0.45,
        'stock' => 0.25,
        'warehouse' => 0.15,
        'performance' => 0.10,
        'preferred' => 0.05,
    ],

    /** Penalización por día de entrega (resta al score final). */
    'lead_day_penalty' => 0.005,

    /** Penalización cuando hay stock local pero la oferta es importación. */
    'import_penalty' => 0.08,

    /** Stock mínimo para considerar disponibilidad útil. */
    'min_stock_threshold' => 1,

    /**
     * Almacenes preferidos (orden de prioridad cuando no se indica uno).
     * Toda la República según catálogo CT (`config/ct_warehouses.php`).
     */
    'warehouse_priority' => (static function (): array {
        $catalog = require __DIR__.'/ct_warehouses.php';
        $hubs = ['CDMX', 'CMT', 'D2A', 'HMO', 'GDL', 'QRO', 'PUE', 'SLP', 'AGS', 'LEO', 'VER', 'MID', 'CUN', 'MTY'];
        $regions = [];
        foreach ($catalog as $row) {
            if (! is_array($row)) {
                continue;
            }
            $region = strtoupper((string) ($row['region'] ?? ''));
            if ($region !== '') {
                $regions[$region] = true;
            }
        }
        $ordered = [];
        foreach ($hubs as $hub) {
            if (isset($regions[$hub])) {
                $ordered[] = $hub;
                unset($regions[$hub]);
            }
        }
        $rest = array_keys($regions);
        sort($rest, SORT_STRING);

        return array_merge($ordered, $rest);
    })(),

    /**
     * Ofertas demo cuando las APIs de mayoristas no están configuradas.
     * En producción/VPS debe ser false (solo CT u otros con API real).
     */
    'demo_offers' => env('WHOLESALER_DEMO_OFFERS', false),

    /**
     * Limitar comparador a estos códigos de mayorista (ej. CT).
     * Vacío = todos los activos en catálogo.
     */
    'compare_wholesaler_codes' => array_values(array_filter(array_map(
        static fn (string $code): string => strtoupper(trim($code)),
        explode(',', (string) env('WHOLESALER_COMPARE_CODES', '')),
    ))),

    /** Costos base demo por número de parte (MXN). */
    'demo_base_costs' => [
        'C9200L-24T-4G-E' => 28500,
        'KVR16N11S8/16' => 890,
        'U6-PLUS' => 4200,
        'WD19' => 3200,
        'LAT5540' => 24500,
        'CAT6-305' => 1850,
    ],

    /** Variación de precio por índice de mayorista (simula diferencias entre distribuidores). */
    'demo_price_variants' => [1.00, 1.04, 0.97, 1.02, 1.06, 0.95],

    'demo_warehouses' => ['CDMX', 'MTY', 'GDL'],

];
