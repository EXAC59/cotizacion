<?php

return [

    'statuses' => [
        'solicitud_cotizaciones',
        'en_elaboracion',
        'pendiente_envio',
        'enviada',
        'modificacion',
        'aceptada',
        'facturada',
    ],

    'status_labels' => [
        'solicitud_cotizaciones' => 'Solicitud de cotizaciones',
        'en_elaboracion' => 'En elaboración',
        'pendiente_envio' => 'Lista / Terminada',
        'enviada' => 'Enviada',
        'modificacion' => 'Modificación cotización',
        'aceptada' => 'Aceptada',
        'facturada' => 'Facturada',
    ],

    /**
     * Flujo operativo automático (UI stepper 1–5).
     * aceptada/facturada quedan fuera del stepper.
     */
    'workflow_statuses' => [
        'solicitud_cotizaciones',
        'en_elaboracion',
        'pendiente_envio',
        'enviada',
        'modificacion',
    ],

    /** Al guardar cambios sobre enviada/aceptada/facturada → pasa a modificación (no al solo abrir). */
    'modificacion_from_statuses' => ['enviada', 'aceptada', 'facturada'],

    /** Al abrir/bloquear una cotización en estos estatus → vuelve a elaboración. */
    'elaboracion_from_statuses' => ['solicitud_cotizaciones', 'pendiente_envio'],

    /** Migración de estatus anteriores al flujo comercial actual. */
    'legacy_status_map' => [
        'pendiente' => 'en_elaboracion',
        'en_revision' => 'en_elaboracion',
        'rechazada' => 'en_elaboracion',
        'aprobada' => 'aceptada',
        'comprada' => 'facturada',
    ],

    /** Estatus que cuentan como venta cerrada (KPIs). */
    'won_statuses' => ['aceptada', 'facturada'],

    /** Al generar PDF / enviar correo desde estados previos. */
    'sent_from_statuses' => ['solicitud_cotizaciones', 'en_elaboracion', 'pendiente_envio', 'modificacion'],

    'default_status' => 'en_elaboracion',

    /** Al crear cotización vinculada a una solicitud. */
    'from_request_status' => 'solicitud_cotizaciones',

    /** Segundos sin heartbeat antes de liberar el bloqueo de edición. */
    'lock_ttl_seconds' => (int) env('QUOTE_LOCK_TTL_SECONDS', 120),

    /** Intervalo sugerido de heartbeat desde el frontend (segundos). */
    'lock_heartbeat_seconds' => (int) env('QUOTE_LOCK_HEARTBEAT_SECONDS', 30),

    /** Prefijo base del folio autogenerado (ej. COT-LUIS-0001). */
    'folio_prefix' => env('QUOTE_FOLIO_PREFIX', 'COT'),

    /** Dígitos del consecutivo anual (COT-2026-0001 → 4). */
    'folio_sequence_pad' => (int) env('QUOTE_FOLIO_SEQUENCE_PAD', 4),

];
