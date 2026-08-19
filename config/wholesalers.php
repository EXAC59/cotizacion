<?php

/**
 * Catálogo de mayoristas — Objetivo 2 (integraciones).
 *
 * Cada entrada define el conector (api, xml, csv, ftp, scraping) y el prefijo
 * de variables de entorno para credenciales: {env_prefix}_BASE_URL, _API_KEY, etc.
 */
return [

    'catalog' => [
        [
            'code' => 'CT',
            'name' => 'CT Internacional',
            'integration' => 'api',
            'active' => true,
            'env_prefix' => 'WHOLESALER_CT',
        ],
        [
            'code' => 'EXEL',
            'name' => 'Exel del Norte',
            'integration' => 'csv',
            'active' => true,
            'env_prefix' => 'WHOLESALER_EXEL',
        ],
        [
            'code' => 'INGRAM',
            'name' => 'Ingram Micro',
            'integration' => 'api',
            'active' => true,
            'env_prefix' => 'WHOLESALER_INGRAM',
        ],
        [
            'code' => 'ASC',
            'name' => 'ASC Direct',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_ASC',
        ],
        [
            'code' => 'AZERTY',
            'name' => 'Azerty',
            'integration' => 'scraping',
            'active' => false,
            'env_prefix' => 'WHOLESALER_AZERTY',
        ],
        [
            'code' => 'EVERTEK',
            'name' => 'Evertek',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_EVERTEK',
        ],
        [
            'code' => 'CALCOM',
            'name' => 'Calcom',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_CALCOM',
        ],
        [
            'code' => 'CVA',
            'name' => 'Grupo CVA',
            'integration' => 'api',
            'active' => true,
            'env_prefix' => 'WHOLESALER_CVA',
        ],
        [
            'code' => 'ROWAN',
            'name' => 'Rowan Tech',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_ROWAN',
        ],
        [
            'code' => 'SYSCOM',
            'name' => 'Syscom',
            'integration' => 'api',
            'active' => true,
            'env_prefix' => 'WHOLESALER_SYSCOM',
        ],
        [
            'code' => 'EPCOM',
            'name' => 'Epcom',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_EPCOM',
        ],
        [
            'code' => 'TVC',
            'name' => 'TVC',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_TVC',
        ],
        [
            'code' => 'TECHDATA',
            'name' => 'Tech Data',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_TECHDATA',
        ],
        [
            'code' => 'AMAZON',
            'name' => 'Amazon',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_AMAZON',
        ],
        [
            'code' => 'EBAY',
            'name' => 'eBay',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_EBAY',
        ],
        [
            'code' => 'INTCOMEX',
            'name' => 'Intcomex',
            'integration' => 'xml',
            'active' => false,
            'env_prefix' => 'WHOLESALER_INTCOMEX',
        ],
        [
            'code' => 'TECNOSINERGIA',
            'name' => 'Tecnosinergia',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_TECNOSINERGIA',
        ],
        [
            'code' => 'INTTELEC',
            'name' => 'Inttelec',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_INTTELEC',
        ],
        [
            'code' => 'TEAM',
            'name' => 'Team',
            'integration' => 'scraping',
            'active' => true,
            'env_prefix' => 'WHOLESALER_TEAM',
        ],
        [
            'code' => 'PCH',
            'name' => 'PCH',
            'integration' => 'ftp',
            'active' => false,
            'env_prefix' => 'WHOLESALER_PCH',
        ],
        [
            'code' => 'MALABS',
            'name' => 'Malabs',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_MALABS',
        ],
        [
            'code' => 'DC',
            'name' => 'DC',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_DC',
        ],
        [
            'code' => 'DAISYYEK',
            'name' => 'Daisy Yek',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_DAISYYEK',
        ],
        [
            'code' => 'INALARM',
            'name' => 'Inalarm',
            'integration' => 'api',
            'active' => false,
            'env_prefix' => 'WHOLESALER_INALARM',
        ],
    ],

    'integration_labels' => [
        'api' => 'API REST',
        'xml' => 'XML',
        'csv' => 'CSV',
        'ftp' => 'FTP',
        'scraping' => 'Web Scraping',
    ],

];
