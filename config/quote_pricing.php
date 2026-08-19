<?php

return [

    /** Margen de utilidad predeterminado (%) — Objetivo 4 */
    'default_margin_percent' => (float) env('QUOTE_DEFAULT_MARGIN', 30),

    /** IVA predeterminado (%) */
    'default_tax_percent' => (float) env('QUOTE_DEFAULT_TAX', 16),

    /** Vigencia predeterminada de cotizaciones (días) */
    'default_validity_days' => (int) env('QUOTE_DEFAULT_VALIDITY_DAYS', 15),

    /** Decimales para precios e importes */
    'price_decimals' => 4,

    /** Decimales para porcentajes de margen */
    'margin_decimals' => 2,

];
