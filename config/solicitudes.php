<?php

return [

    /**
     * Estatus propio de las solicitudes de cotización (independiente del pipeline n8n).
     * La columna `status` de quote_requests sigue siendo el estado técnico de lectura
     * (procesando/procesada/error); `workflow_status` es el flujo de negocio.
     */
    'workflow_statuses' => [
        'en_elaboracion',
        'pendiente_envio',
        'enviada',
    ],

    'workflow_labels' => [
        'en_elaboracion' => 'En elaboración',
        'pendiente_envio' => 'Lista / Terminada',
        'enviada' => 'Enviada',
    ],

    /** Estatus con que nace una solicitud. */
    'default_workflow_status' => 'en_elaboracion',

    /**
     * Transiciones del flujo:
     *  - crear → en_elaboracion
     *  - guardar líneas → el usuario elige en_elaboracion o pendiente_envio
     *  - crear cotización vinculada → enviada (pasa al apartado de Cotizaciones)
     */
    'lista_from_statuses' => ['en_elaboracion'],
    'enviada_from_statuses' => ['en_elaboracion', 'pendiente_envio'],

    /** Prefijo base del folio autogenerado (ej. SOL-2026-0001). */
    'folio_prefix' => env('SOLICITUD_FOLIO_PREFIX', 'SOL'),

    /** Dígitos del consecutivo anual (SOL-2026-0001 → 4). */
    'folio_sequence_pad' => (int) env('SOLICITUD_FOLIO_SEQUENCE_PAD', 4),
];
