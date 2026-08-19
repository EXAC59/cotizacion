<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Docling Serve — lectura PDF / Excel / Word + OCR (PP-OCR vía RapidOCR)
    |--------------------------------------------------------------------------
    |
    | base_url: instancia local docling-serve (Docker puerto 5001)
    | timeout: conversión puede tardar en la primera ejecución (descarga modelos)
    |
    */

    'base_url' => rtrim((string) env('DOCLING_BASE_URL', 'http://127.0.0.1:5001'), '/'),

    'timeout' => max(30, (int) env('DOCLING_TIMEOUT', 180)),

    /** OCR activo por defecto en PDF escaneados */
    'do_ocr' => filter_var(env('DOCLING_DO_OCR', true), FILTER_VALIDATE_BOOL),

    'ocr_langs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DOCLING_OCR_LANGS', 'es,en'))
    ))),

    'table_mode' => in_array(strtolower((string) env('DOCLING_TABLE_MODE', 'accurate')), ['fast'], true)
        ? 'fast'
        : 'accurate',

];
