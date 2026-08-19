<?php

/**
 * Mapeo manual modelo / numParte fabricante → clave CT.
 *
 * El FTP productos.json a veces omite artículos que sí existen en la web/API
 * (ej. Tinta HP 662: modelo CZ103AL → clave CARHPP2110).
 *
 * También se pueden añadir en .env:
 * WHOLESALER_CT_PART_ALIASES=CZ103AL:CARHPP2110,OTRO:CLAVECT
 */
return [
    // Tinta HP 662 Negro — confirmado en ctonline.mx (Clave CT / Modelo)
    'CZ103AL' => 'CARHPP2110',
];
