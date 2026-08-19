<?php

return [
    'default_company_legal_name' => 'Expertos en Administración y Cómputo S.A. de C.V.',
    'default_company_rfc' => 'EAC-881212-MN7',

    // Bloque de firma del PDF.
    'default_signature_name' => 'ERICKA MARTINEZ REYES',
    'default_signature_email' => 'ericka.martinez@exactolp.com.mx',
    'default_signature_branch' => 'SUCURSAL MATRIZ',
    'signature_image' => 'company/firma.png', // relativo a storage/app/public
    /** Logo por defecto (storage/app/public). Si falta, se usa resources/branding/exacto-logo.png */
    'default_logo' => 'company/logo.png',

    'default_branches' => [
        [
            'label' => 'Matriz',
            'address' => 'Colima Esq. Guillermo Prieto #415 La Paz, B.C.S',
            'phone' => 'Tels. (612) 125 6111 / (612) 122 3742',
        ],
        [
            'label' => 'Suc. San José del Cabo',
            'address' => 'Blvd. Mauricio Castro, Plaza San José Local #7 San José del Cabo, B.C.S',
            'phone' => 'Tel. (624) 146 9362',
        ],
    ],

    'default_bank_accounts' => [
        [
            'bank' => 'BANAMEX',
            'account' => '962-32198',
            'clabe' => '002040096200321988',
            'currency' => 'M.N.',
        ],
        [
            'bank' => 'BANCOMER',
            'account' => '0454473981',
            'clabe' => '012040004544739810',
            'currency' => 'M.N.',
        ],
    ],

    'default_terms' => <<<'TXT'
* Precios cotizados en moneda nacional (MXN)
* Los precios pueden variar SIN PREVIO AVISO y están sujetos a existencia.
* Pedidos especiales se requiere un 50% de anticipo y no tienen cancelaciones ni devoluciones.
* Precios cotizados son validos 7 dias habiles a partir de la fecha de la cotización.
TXT,
];
