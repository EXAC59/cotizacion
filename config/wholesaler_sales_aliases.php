<?php

/**
 * Alias de mayoristas visibles para personal de ventas.
 *
 * Ventas no debe ver el nombre comercial real (CT, CVA, Ingram, etc.).
 * En su lugar se muestra BODEGAxx.
 *
 * Convención fija (acordada):
 *   BODEGA01 = CT Internacional
 *   BODEGA02 = Grupo CVA
 *   BODEGA03 = Exel del Norte (Excel)
 *   BODEGA04 = Ingram Micro
 *   BODEGA05 = ASC / ASI
 *
 * Cuando se integre un mayorista nuevo: asignar el siguiente BODEGAxx libre
 * aquí y avisar al equipo. No reutilizar números.
 */
return [

    /** Roles que reciben el nombre alterno (no el real). */
    'roles' => ['ventas'],

    /**
     * code (wholesalers.code / config wholesalers.catalog) → alias UI.
     * Mantener estables: el personal de ventas memoriza el número.
     */
    'aliases' => [
        'CT' => 'BODEGA01',
        'CVA' => 'BODEGA02',
        'EXEL' => 'BODEGA03',
        'INGRAM' => 'BODEGA04',
        'ASC' => 'BODEGA05',
        // Reservados / próximos (activar al integrar; no cambiar número):
        'SYSCOM' => 'BODEGA06',
        'TEAM' => 'BODEGA07',
        'AZERTY' => 'BODEGA08',
        'EVERTEK' => 'BODEGA09',
        'CALCOM' => 'BODEGA10',
        'ROWAN' => 'BODEGA11',
        'EPCOM' => 'BODEGA12',
        'TVC' => 'BODEGA13',
        'TECHDATA' => 'BODEGA14',
        'AMAZON' => 'BODEGA15',
        'EBAY' => 'BODEGA16',
        'INTCOMEX' => 'BODEGA17',
        'TECNOSINERGIA' => 'BODEGA18',
        'INTTELEC' => 'BODEGA19',
        'PCH' => 'BODEGA20',
        'MALABS' => 'BODEGA21',
        'DC' => 'BODEGA22',
        'DAISYYEK' => 'BODEGA23',
        'INALARM' => 'BODEGA24',
    ],

    /** Prefijo si aparece un code aún no listado (pedir asignación formal). */
    'fallback_prefix' => 'BODEGA',
];
