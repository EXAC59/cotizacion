<?php

use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ClienteImportController;
use App\Http\Controllers\Api\ComparadorController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\CotizacionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MarcaController;
use App\Http\Controllers\Api\MayoristaController;
use App\Http\Controllers\Api\N8nWebhookController;
use App\Http\Controllers\Api\RbacController;
use App\Http\Controllers\Api\ReportesController;
use App\Http\Controllers\Api\SolicitudController;
use App\Http\Controllers\Api\SolicitudLecturaController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);
/** Branding público (login / landing) — solo nombre, subtítulo y logo. */
Route::get('/configuracion/branding', [ConfiguracionController::class, 'branding'])
    ->middleware('throttle:60,1');
Route::post('/login', [AuthController::class, 'login']);

/** Webhook Meta WhatsApp Cloud API (público; verificación por token). */
Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive'])
    ->middleware(['whatsapp.signature', 'throttle:120,1']);

Route::prefix('n8n')->middleware('n8n.webhook')->group(function () {
    Route::post('/lectura', [N8nWebhookController::class, 'recibirLectura']);
    Route::post('/comparador', [N8nWebhookController::class, 'recibirComparador']);
    /** Catálogo/consultas usadas por el workflow Comparador (sin Sanctum). */
    Route::get('/mayoristas', [MayoristaController::class, 'index']);
    Route::post('/mayoristas/{id}/consultar', [MayoristaController::class, 'consultarForWholesaler']);
});

/** Endpoints internos llamados por n8n (sin sesión Sanctum). */
Route::middleware('n8n.webhook')->group(function () {
    Route::post('/solicitudes/interpretar', [SolicitudController::class, 'interpretar']);
    Route::post('/marcas/resolver', [MarcaController::class, 'resolver']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    Route::get('/rbac/permissions', [RbacController::class, 'permissions']);
    Route::put('/rbac/permissions', [RbacController::class, 'updatePermissions'])
        ->middleware('permission:admin,manage');
    Route::post('/rbac/permissions/reset', [RbacController::class, 'resetPermissions'])
        ->middleware('permission:admin,manage');

    Route::get('/admin/users', [AdminUserController::class, 'index'])
        ->middleware('permission:admin,manage');
    Route::post('/admin/users', [AdminUserController::class, 'store'])
        ->middleware('permission:admin,manage');
    Route::get('/admin/users/{id}/signature', [AdminUserController::class, 'signature'])
        ->middleware('permission:admin,manage');
    Route::put('/admin/users/{id}', [AdminUserController::class, 'update'])
        ->middleware('permission:admin,manage');
    Route::delete('/admin/users/{id}', [AdminUserController::class, 'destroy'])
        ->middleware('permission:admin,manage');

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard,view');

    Route::get('/reportes', [ReportesController::class, 'index'])
        ->middleware('permission:reportes,view');

    Route::get('/clientes/import/plantilla', [ClienteImportController::class, 'plantilla'])
        ->middleware('permission:clientes,import');
    Route::post('/clientes/import', [ClienteImportController::class, 'import'])
        ->middleware('permission:clientes,import');
    Route::get('/clientes', [ClienteController::class, 'index'])
        ->middleware('permission:clientes,view');
    Route::post('/clientes', [ClienteController::class, 'store'])
        ->middleware('permission:clientes,create');
    Route::get('/clientes/{id}/cotizaciones', [ClienteController::class, 'cotizaciones'])
        ->middleware('permission:clientes,view');
    Route::get('/clientes/{id}', [ClienteController::class, 'show'])
        ->middleware('permission:clientes,view');
    Route::put('/clientes/{id}', [ClienteController::class, 'update'])
        ->middleware('permission:clientes,edit');
    Route::delete('/clientes/{id}', [ClienteController::class, 'destroy'])
        ->middleware('permission:clientes,delete');

    Route::get('/mayoristas', [MayoristaController::class, 'index'])
        ->middleware('permission:mayoristas,view');
    Route::get('/mayoristas/stock-bajo', [MayoristaController::class, 'stockBajo'])
        ->middleware('permission:mayoristas,view');
    Route::get('/mayoristas/ct/autocomplete', [MayoristaController::class, 'autocompleteCt'])
        ->middleware('permission:cotizaciones,create|edit');
    Route::get('/mayoristas/cva/autocomplete', [MayoristaController::class, 'autocompleteCva'])
        ->middleware('permission:cotizaciones,create|edit');
    Route::post('/mayoristas/consultar', [MayoristaController::class, 'consultar'])
        ->middleware('permission:mayoristas,view');
    Route::post('/mayoristas/{id}/consultar', [MayoristaController::class, 'consultarForWholesaler'])
        ->middleware('permission:mayoristas,view');
    Route::post('/mayoristas/comparar', [MayoristaController::class, 'comparar'])
        ->middleware('permission:mayoristas,view');
    Route::post('/mayoristas/comparar-lote', [MayoristaController::class, 'compararLote'])
        ->middleware('permission:mayoristas,view');
    Route::get('/mayoristas/{id}', [MayoristaController::class, 'show'])
        ->middleware('permission:mayoristas,view');
    Route::patch('/mayoristas/{id}', [MayoristaController::class, 'update'])
        ->middleware('permission:mayoristas,edit');

    Route::post('/comparador/disparar', [ComparadorController::class, 'disparar'])
        ->middleware('permission:mayoristas,view');
    Route::get('/comparador/{id}', [ComparadorController::class, 'show'])
        ->middleware('permission:mayoristas,view');

    Route::get('/cotizaciones', [CotizacionController::class, 'index'])
        ->middleware('permission:cotizaciones,view');
    Route::get('/cotizaciones/proximo-folio', [CotizacionController::class, 'proximoFolio'])
        ->middleware('permission:cotizaciones,create');
    Route::post('/cotizaciones/{id}/bloqueo', [CotizacionController::class, 'bloquear'])
        ->middleware('permission:cotizaciones,create');
    Route::delete('/cotizaciones/{id}/bloqueo', [CotizacionController::class, 'liberar'])
        ->middleware('permission:cotizaciones,create');
    Route::get('/cotizaciones/{id}/pdf', [CotizacionController::class, 'pdf'])
        ->middleware('permission:cotizaciones,view');
    Route::post('/cotizaciones/{id}/enviar', [CotizacionController::class, 'enviar'])
        ->middleware('permission:cotizaciones,send');
    Route::post('/cotizaciones/{id}/notas-internas', [CotizacionController::class, 'agregarNotaInterna'])
        ->middleware('permission:cotizaciones,create|edit');
    Route::get('/cotizaciones/{id}', [CotizacionController::class, 'show'])
        ->middleware('permission:cotizaciones,view');
    Route::post('/cotizaciones', [CotizacionController::class, 'store'])
        ->middleware('permission:cotizaciones,create');
    Route::post('/cotizaciones/calcular', [CotizacionController::class, 'calcular'])
        ->middleware('permission:cotizaciones,create');

    Route::get('/configuracion/comercial', [ConfiguracionController::class, 'comercial'])
        ->middleware('permission:configuracion,view');
    Route::patch('/configuracion/comercial', [ConfiguracionController::class, 'updateComercial'])
        ->middleware('commercial.settings');
    Route::post('/configuracion/comercial/logo', [ConfiguracionController::class, 'uploadLogo'])
        ->middleware('commercial.settings');
    Route::get('/configuracion/comparador', [ConfiguracionController::class, 'comparador'])
        ->middleware('permission:configuracion,view');
    Route::patch('/configuracion/comparador', [ConfiguracionController::class, 'updateComparador'])
        ->middleware('permission:configuracion,edit');
    Route::get('/configuracion/comparador/preferencias', [ConfiguracionController::class, 'comparadorPreferencias'])
        ->middleware('permission:mayoristas,view');
    Route::patch('/configuracion/comparador/preferencias', [ConfiguracionController::class, 'updateComparadorPreferencias'])
        ->middleware('permission:mayoristas,view');

    Route::get('/solicitudes', [SolicitudController::class, 'index'])
        ->middleware('permission:solicitudes,view');
    Route::post('/solicitudes', [SolicitudController::class, 'store'])
        ->middleware('permission:solicitudes,create');
    Route::post('/solicitudes/lectura', [SolicitudLecturaController::class, 'procesar'])
        ->middleware('permission:solicitudes,create');
    Route::post('/solicitudes/lectura-texto', [SolicitudLecturaController::class, 'procesarTexto'])
        ->middleware('permission:solicitudes,create');
    Route::put('/solicitudes/{id}/lineas', [SolicitudController::class, 'updateLineas'])
        ->middleware('permission:solicitudes,edit');
    Route::get('/solicitudes/{id}/cotizaciones', [SolicitudController::class, 'cotizaciones'])
        ->middleware('permission:solicitudes,view');
    Route::post('/solicitudes/{id}/reprocesar', [SolicitudLecturaController::class, 'reprocesar'])
        ->middleware('permission:solicitudes,edit');
    Route::get('/solicitudes/{id}', [SolicitudController::class, 'show'])
        ->middleware('permission:solicitudes,view');
});
