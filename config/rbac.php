<?php

return [
    'roles' => [
        'administrador',
        'gerente_compras',
        'ventas',
    ],

    'modules' => [
        'dashboard',
        'solicitudes',
        'cotizaciones',
        'clientes',
        'mayoristas',
        'reportes',
        'configuracion',
        'admin',
    ],

    'module_actions' => [
        'dashboard' => ['view'],
        'solicitudes' => ['view', 'create', 'edit', 'delete'],
        'cotizaciones' => ['view', 'create', 'edit', 'delete', 'send', 'approve', 'edit_margin'],
        'clientes' => ['view', 'create', 'edit', 'delete', 'import'],
        'mayoristas' => ['view', 'edit'],
        'reportes' => ['view'],
        'configuracion' => ['view', 'edit'],
        'admin' => ['view', 'manage'],
    ],

    'default_role_permissions' => [
        'administrador' => 'all',
        'gerente_compras' => [
            'dashboard' => ['view'],
            'solicitudes' => ['view', 'create', 'edit'],
            'cotizaciones' => ['view', 'create', 'edit', 'send', 'approve', 'edit_margin'],
            'clientes' => ['view', 'create', 'edit', 'import'],
            'mayoristas' => ['view'],
            'reportes' => ['view'],
            'configuracion' => ['view'],
            'admin' => [],
        ],
        'ventas' => [
            'dashboard' => [],
            'solicitudes' => ['view', 'create', 'edit'],
            'cotizaciones' => ['view', 'create', 'edit', 'send'],
            'clientes' => ['view', 'create', 'edit'],
            'mayoristas' => [],
            'reportes' => [],
            'configuracion' => ['view'],
            'admin' => [],
        ],
    ],
];
