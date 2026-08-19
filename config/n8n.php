<?php

return [

    /*
    |--------------------------------------------------------------------------
    | n8n — motor de automatización y lectura
    |--------------------------------------------------------------------------
    |
    | base_url: URL de la instancia n8n (UI y API)
    | webhook_url: path o URL completa del webhook que inicia el flujo de lectura
    | api_key: opcional, para llamadas autenticadas a la API de n8n
    |
    */

    'base_url' => env('N8N_BASE_URL', 'http://localhost:5678'),

    'webhook_url' => env('N8N_WEBHOOK_URL'),

    'comparator_webhook_url' => env('N8N_COMPARATOR_WEBHOOK_URL'),

    'api_key' => env('N8N_API_KEY'),

    'timeout' => (int) env('N8N_TIMEOUT', 30),

    'comparator_poll_timeout' => (int) env('COMPARATOR_POLL_TIMEOUT', 60),

    /** Segundos antes de fallback local si n8n no hace callback */
    'comparator_fallback_seconds' => (int) env('COMPARATOR_FALLBACK_SECONDS', 20),

    /** Secreto compartido para callbacks n8n → Laravel (header X-N8N-Webhook-Secret) */
    'webhook_secret' => env('N8N_WEBHOOK_SECRET'),

];
