<?php

return [
    /** Días sin abrir ni actualizar una cotización en elaboración/lista para alertar. */
    'unanswered_quote_days' => (int) env('DASHBOARD_UNANSWERED_DAYS', 3),

    /** Ventana (días) para alertar cotizaciones próximas a vencer. */
    'expiring_quote_days' => (int) env('DASHBOARD_EXPIRING_DAYS', 7),

    /** Días hacia atrás para fallback de stock bajo desde ofertas de cotización. */
    'low_stock_offer_lookback_days' => (int) env('DASHBOARD_LOW_STOCK_LOOKBACK', 30),

    /** Días hacia atrás para errores de comparador en alertas. */
    'integration_error_lookback_days' => (int) env('DASHBOARD_INTEGRATION_ERROR_DAYS', 7),

    /** Minutos en «procesando» antes de alertar en dashboard / scheduler. */
    'stuck_request_minutes' => (int) env('DASHBOARD_STUCK_REQUEST_MINUTES', 15),
];
