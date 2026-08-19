<?php

return [
    'access_token' => env('WHATSAPP_ACCESS_TOKEN', env('META_ACCESS_TOKEN')),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'waba_id' => env('WHATSAPP_WABA_ID'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
    'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
    /** Token que pegas en Meta → "Token de verificación" del webhook. */
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    /** App Secret de Meta (firma X-Hub-Signature-256 en POST del webhook). */
    'app_secret' => env('WHATSAPP_APP_SECRET', env('META_APP_SECRET')),
];
