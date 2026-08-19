<?php

/**
 * Genera DOCUMENTACION_EXACTO_LARAVEL.docx en la raíz del proyecto.
 * Uso: php scripts/generate-documentacion-word.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$output = $root . DIRECTORY_SEPARATOR . 'DOCUMENTACION_EXACTO_LARAVEL.docx';

$sections = [
    [
        'title' => 'Documentación del proyecto EXACTO Laravel — Cotización',
        'level' => 0,
        'paragraphs' => [
            'Sistema: Gestión de solicitudes, comparador de precios y cotizaciones profesionales.',
            'Ruta del proyecto: c:\\laragon\\www\\cotizacion',
            'Fecha de documento: ' . date('d/m/Y'),
            'Stack: Laravel (PHP 8.3) + PostgreSQL + React 19 (SPA) + Sanctum + n8n + Docling.',
        ],
    ],
    [
        'title' => '1. Propósito del sistema',
        'level' => 1,
        'paragraphs' => [
            'El proyecto EXACTO Laravel (Cotización) es una aplicación empresarial B2B que cubre el ciclo comercial desde el requerimiento del cliente hasta la cotización formal en PDF formato EXACTO.',
            'Permite: cargar solicitudes (archivos PDF/Excel/Word o texto libre), interpretar líneas de productos automáticamente, comparar precios entre mayoristas, elaborar cotizaciones con márgenes e IVA, generar PDF, enviar por correo y monitorear KPIs en dashboard.',
        ],
    ],
    [
        'title' => '2. Arquitectura técnica',
        'level' => 1,
        'paragraphs' => [
            'Backend: Laravel con API REST bajo /api. Autenticación con Laravel Sanctum (sesión stateful + cookies).',
            'Base de datos: PostgreSQL en Laragon (SQLite en modo demo y tests).',
            'Frontend: React 19, TypeScript, Vite 8, Tailwind CSS 4, React Router 7.',
            'Despliegue SPA: npm run build en frontend/ genera archivos en public/spa/. Laravel sirve el SPA y redirige rutas amigables.',
            'Integraciones externas: n8n (lectura de documentos y comparador de precios), Docling Serve en Docker (conversión OCR local opcional).',
        ],
    ],
    [
        'title' => '3. Estructura de carpetas',
        'level' => 1,
        'paragraphs' => [
            'app/Http/Controllers/Api/ — Controladores REST (Auth, Cotizaciones, Solicitudes, Clientes, Mayoristas, Comparador, Dashboard, RBAC, Configuración).',
            'app/Services/ — Lógica de negocio: Quotes/, Wholesalers/, Analytics/, Rbac/, SolicitudLecturaService, N8nClient, DoclingClient.',
            'app/Models/ — 14 modelos Eloquent con UUID (Quote, QuoteRequest, Client, Wholesaler, etc.).',
            'frontend/src/pages/ — Pantallas del SPA por módulo.',
            'frontend/src/lib/ — Clientes API (quotes-api.ts, solicitudes-api.ts, comparator-api.ts).',
            'database/migrations/ — Esquema PostgreSQL (21+ migraciones).',
            'tests/ — PHPUnit: ~132 tests (Feature + Unit).',
            'docs/ — Documentación n8n, Docling, deploy.',
            '.cursor/rules/ — Reglas del agente Cursor del proyecto.',
        ],
    ],
    [
        'title' => '4. Módulos del menú (interfaz en español)',
        'level' => 1,
        'paragraphs' => [
            'Dashboard — KPIs, alertas, cotizaciones recientes, productos más cotizados.',
            'Solicitudes — Listado, nueva solicitud, detalle con líneas editables y comparador embebido.',
            'Cotizaciones — Listado con búsqueda, formulario con partidas, PDF, envío email, historial de estatus.',
            'Clientes — CRM básico, importación Excel, cotizaciones vinculadas.',
            'Mayoristas — Catálogo de proveedores e integraciones (API, XML, CSV, FTP).',
            'Reportes — Analítica por periodo.',
            'Configuración — Parámetros comerciales, comparador de precios, datos PDF EXACTO.',
            'Administración — Usuarios, roles y permisos RBAC.',
        ],
    ],
    [
        'title' => '5. API REST principal',
        'level' => 1,
        'paragraphs' => [
            'Autenticación: POST /api/login, POST /api/logout, GET /api/user.',
            'Cotizaciones: GET/POST /api/cotizaciones, GET proximo-folio, calcular, PDF, enviar, bloqueo/liberar.',
            'Solicitudes: GET/POST /api/solicitudes, lectura (archivo), lectura-texto, líneas, reprocesar, cotizaciones vinculadas.',
            'Comparador: POST /api/comparador/disparar, GET /api/comparador/{id}.',
            'Clientes, Mayoristas, Dashboard, Reportes, Configuración, RBAC — ver routes/api.php.',
            'Webhooks n8n (sin Sanctum): POST /api/n8n/lectura, /api/n8n/comparador, /api/solicitudes/interpretar.',
        ],
    ],
    [
        'title' => '6. Flujo de solicitudes',
        'level' => 1,
        'paragraphs' => [
            'Paso 1 — Cliente obligatorio: la solicitud queda vinculada a un cliente del CRM.',
            'Paso 2 — Contenido: archivo (PDF, .xlsx, .xls, .doc, .docx) o texto libre línea por línea.',
            'Paso 3 — Analizar documento: envía a n8n con fallback Docling local; devuelve request_id en estado procesando o procesada.',
            'Estados: Pendiente, Procesando, Procesada, Precios listos (ventas), Error.',
            'Detalle: tabla de líneas editable al instante; comparador de precios al seleccionar SKU; opción crear cotización vinculada.',
            'Reprocesar: disponible en estado Error con Docling local.',
        ],
    ],
    [
        'title' => '7. Flujo de cotizaciones',
        'level' => 1,
        'paragraphs' => [
            'Folio autogenerado: QuoteFolioGenerator produce COT-{año}-{secuencia} (ej. COT-2026-0001). Solo al crear; campo deshabilitado en UI.',
            'Endpoint GET /api/cotizaciones/proximo-folio para vista previa del folio.',
            'Partidas: cantidad, producto, número de parte, costo, margen %, precio venta, almacén (solo lectura desde comparador).',
            'Cálculo: QuoteProfitCalculator recalcula subtotal, IVA y total.',
            'Estatus comerciales (solo avance, no retroceso): En elaboración → Pendiente de envío → Enviada → Aceptada → Facturada.',
            'Historial de estatus: tabla quote_status_events; timeline en formulario de cotización.',
            'Bloqueo de edición: evita dos usuarios editando la misma cotización (HTTP 423).',
            'PDF formato EXACTO con logo, sucursales y datos bancarios configurables.',
            'Envío por correo: cola SendQuoteEmailJob.',
        ],
    ],
    [
        'title' => '8. Comparador de precios',
        'level' => 1,
        'paragraphs' => [
            'No tiene menú propio: se muestra embebido en Solicitudes y Cotizaciones al hacer clic en una línea con SKU.',
            'Ranking ponderado: precio, stock, almacén preferido, rendimiento del mayorista.',
            'Disparo vía n8n (COMPARATOR_WEBHOOK) con fallback local tras timeout configurable.',
            'Configuración en Configuración → Comparador de precios (pesos, almacenes, mayoristas preferidos).',
            'Preferencias por usuario: almacén preferido y aplicar automáticamente la mejor oferta.',
            'Modo demo: WHOLESALER_DEMO_OFFERS para pruebas sin API keys reales.',
        ],
    ],
    [
        'title' => '9. RBAC y roles',
        'level' => 1,
        'paragraphs' => [
            'Roles: Administrador, Gerente de Compras, Personal de Ventas, Gerencia, Solo lectura.',
            'Módulos con acciones view, create, edit, delete, send, approve, edit_margin, import, manage.',
            'Middleware permission:modulo,accion en rutas API.',
            'Pantalla Administración → Roles para matriz de permisos editable.',
            'Cuentas demo: maria@empresa.com / demo (ventas); admin@cotizacion.test / admin123 (admin).',
        ],
    ],
    [
        'title' => '10. Integraciones',
        'level' => 1,
        'paragraphs' => [
            'Sanctum: dominios stateful en SANCTUM_STATEFUL_DOMAINS; cookies para SPA en mismo origen Laragon.',
            'n8n: N8N_WEBHOOK_URL (lectura), N8N_COMPARATOR_WEBHOOK_URL (comparador), secreto X-N8N-Webhook-Secret.',
            'Docling: docker compose -f docker-compose.lectura.yml up -d; DOCLING_BASE_URL puerto 5001.',
            'Mayoristas configurables en config/wholesalers.php con credenciales por .env.',
        ],
    ],
    [
        'title' => '11. Trabajo realizado en el proyecto (detalle)',
        'level' => 1,
        'paragraphs' => [
            'Buscador flexible de cotizaciones: demo-001 encuentra COT-DEMO-0001 (QuoteSearchScope + quote-search.ts). Buscador en encabezado y en listado de cotizaciones.',
            'Validaciones UI cotizaciones: bloqueo de signo menos en cantidad, costo, margen y precio venta; columna Almacén solo lectura.',
            'Folio automático al crear cotización; tests actualizados (129+ tests pasando).',
            'Solicitud nueva: botón Analizar documento; vista previa de texto; ejemplos clicables; checkbox ir directo a cotización.',
            'Solicitud detalle: edición inline de líneas; guardar/cancelar manual; duplicar y reordenar líneas; cantidad sin negativos.',
            'Historial de cambios de estatus en cotizaciones (migración quote_status_events + UI timeline).',
            'Banner de folio visible en cotización nueva; banner de alertas de ventas en layout.',
            'Tablas responsive con scroll horizontal en móvil (table-scroll-mobile).',
            'Caché SPA optimizada: index.html no-cache; assets 1h en public/spa/assets/.htaccess y routes/web.php.',
            'Reglas Cursor: cotizacion-laravel, cotizacion-frontend, no-modificar-existente, gestión por bloques; AGENTS.md en raíz.',
            'Corrección de errores Intelephense en QuotePersistenceService (firmas explícitas query builder).',
        ],
    ],
    [
        'title' => '12. Base de datos — tablas principales',
        'level' => 1,
        'paragraphs' => [
            'users, roles, modules, permissions, role_permissions — usuarios y RBAC.',
            'clients — clientes con RFC, empresa, contacto.',
            'quote_requests, quote_request_lines — solicitudes y líneas detectadas.',
            'quotes, quote_lines, quote_line_offers — cotizaciones, partidas y ofertas de mayoristas.',
            'quote_status_events — historial de estatus de cotización.',
            'wholesalers, comparison_jobs, comparator_settings, user_comparator_preferences.',
            'app_settings — configuración comercial, logo PDF, sucursales EXACTO.',
        ],
    ],
    [
        'title' => '13. Comandos de desarrollo',
        'level' => 1,
        'paragraphs' => [
            'Tests: C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe vendor/bin/phpunit',
            'Migraciones: php artisan migrate --force',
            'Frontend dev: cd frontend && npm run dev (puerto 5173, proxy /api).',
            'Frontend build: cd frontend && npm run build → public/spa/',
            'Tras deploy SPA: recargar navegador con Ctrl+F5 (caché).',
            'Docling: docker compose -f docker-compose.lectura.yml up -d',
        ],
    ],
    [
        'title' => '14. Variables de entorno relevantes',
        'level' => 1,
        'paragraphs' => [
            'APP_DEMO_MODE, DB_* — base de datos.',
            'SANCTUM_STATEFUL_DOMAINS, FRONTEND_URL, VITE_API_URL — SPA y CORS.',
            'N8N_WEBHOOK_URL, N8N_COMPARATOR_WEBHOOK_URL, N8N_WEBHOOK_SECRET — n8n.',
            'DOCLING_BASE_URL, DOCLING_DO_OCR — Docling.',
            'QUOTE_FOLIO_PREFIX, QUOTE_LOCK_TTL_SECONDS — cotizaciones.',
            'WHOLESALER_DEMO_OFFERS, WHOLESALER_{CODE}_* — mayoristas.',
            'DASHBOARD_UNANSWERED_DAYS, DASHBOARD_EXPIRING_DAYS — alertas.',
        ],
    ],
    [
        'title' => '15. Tests automatizados',
        'level' => 1,
        'paragraphs' => [
            'Suite PHPUnit: ~132 tests, 37 archivos (Feature + Unit).',
            'Cobertura: auth, RBAC, cotizaciones, folio, búsqueda, bloqueo, PDF, email, solicitudes, lectura, comparador, dashboard, clientes, n8n interno.',
            'Entorno tests: SQLite en memoria, sin dependencia de PostgreSQL ni Docker.',
        ],
    ],
    [
        'title' => '16. Documentación adicional en el repositorio',
        'level' => 1,
        'paragraphs' => [
            'AGENTS.md — guía para agentes de IA (stack, comandos, API).',
            '.cursor/rules/ — reglas de desarrollo del proyecto.',
            'docs/ — workflows n8n, Docling, despliegue.',
            'Este archivo Word: DOCUMENTACION_EXACTO_LARAVEL.docx (regenerable con php scripts/generate-documentacion-word.php).',
        ],
    ],
];

function xmlEscape(string $text): string
{
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function headingTag(int $level): string
{
    return match ($level) {
        0 => 'Title',
        1 => 'Heading1',
        2 => 'Heading2',
        default => 'Heading3',
    };
}

$body = '';
foreach ($sections as $section) {
    $style = headingTag((int) $section['level']);
    $body .= '<w:p><w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>';
    $body .= '<w:r><w:t xml:space="preserve">' . xmlEscape($section['title']) . '</w:t></w:r></w:p>';

    foreach ($section['paragraphs'] as $paragraph) {
        $body .= '<w:p><w:r><w:t xml:space="preserve">' . xmlEscape($paragraph) . '</w:t></w:r></w:p>';
    }
}

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>' . $body
    . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
    . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/>'
    . '</w:sectPr></w:body></w:document>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
    . '</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    . '</Relationships>';

$docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/>'
    . '<w:rPr><w:b/><w:sz w:val="48"/><w:color w:val="1F2937"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/>'
    . '<w:rPr><w:b/><w:sz w:val="32"/><w:color w:val="4338CA"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/>'
    . '<w:rPr><w:b/><w:sz w:val="26"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Normal"><w:name w:val="Normal"/>'
    . '<w:rPr><w:sz w:val="22"/></w:rPr></w:style>'
    . '</w:styles>';

if (file_exists($output)) {
    unlink($output);
}

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "No se pudo crear el archivo Word.\n");
    exit(1);
}

$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rels);
$zip->addFromString('word/document.xml', $documentXml);
$zip->addFromString('word/_rels/document.xml.rels', $docRels);
$zip->addFromString('word/styles.xml', $styles);
$zip->close();

echo "Documento generado: {$output}\n";
echo 'Tamaño: ' . number_format(filesize($output)) . " bytes\n";
