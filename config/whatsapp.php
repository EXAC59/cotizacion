<?php

return [
    'access_token' => env('WHATSAPP_ACCESS_TOKEN', env('META_ACCESS_TOKEN')),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'waba_id' => env('WHATSAPP_WABA_ID'),
    'app_id' => env('WHATSAPP_APP_ID'),
    'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'es_MX'),
    'templates' => [
        'cotizacion_lista' => [
            'label' => 'Cotización lista',
            'body' => 'Hola {{1}}, la cotización {{2}} que solicitaste a {{3}} está lista. Adjuntamos el PDF con los productos, precios y condiciones. Puedes responder a este mensaje para resolver dudas.',
        ],
        'cotizacion_actualizada' => [
            'label' => 'Cotización actualizada',
            'body' => 'Hola {{1}}, adjuntamos la versión actualizada de tu cotización {{2}} de {{3}}. Revisa el PDF para consultar los cambios en productos, precios y condiciones.',
        ],
        'seguimiento_cotizacion' => [
            'label' => 'Seguimiento de cotización',
            'body' => 'Hola {{1}}, damos seguimiento a la cotización {{2}} que solicitaste a {{3}}. Adjuntamos el PDF para tu consulta. Si necesitas un ajuste o tienes dudas, responde a este mensaje.',
        ],
    ],
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
    'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
    /** Token que pegas en Meta → "Token de verificación" del webhook. */
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    /** App Secret de Meta (firma X-Hub-Signature-256 en POST del webhook). */
    'app_secret' => env('WHATSAPP_APP_SECRET', env('META_APP_SECRET')),
];
