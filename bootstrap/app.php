<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->statefulApi();

        $middleware->validateCsrfTokens(except: [
            'api/login',
            'api/n8n/*',
            'api/whatsapp/*',
        ]);

        $middleware->replaceInGroup(
            'api',
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        );

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'n8n.webhook' => \App\Http\Middleware\VerifyN8nWebhookSecret::class,
            'whatsapp.signature' => \App\Http\Middleware\VerifyWhatsAppSignature::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'commercial.settings' => \App\Http\Middleware\CheckCommercialSettings::class,
        ]);

        // SPA en /spa/login; API sin sesión debe responder 401 JSON (no redirect a route('login')).
        $middleware->redirectGuestsTo(function (Request $request): ?string {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return '/spa/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 401);
            }
        });

        $exceptions->render(function (\App\Exceptions\QuoteLockedException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json($e->payload(), 423);
            }
        });
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('solicitud:alertar-atascadas')->everyFiveMinutes();
        // FTP CT incompleto: refrescar UPC→clave de productos solo-API.
        $schedule->command('wholesalers:ct-catalog-gaps --sync-upc --upc-limit=300')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->runInBackground();
        // Catálogo CVA: precios/stock cada 4h (descripciones opcionales vía lista_precios).
        $schedule->command('wholesalers:sync-cva-catalog --batch=LG --skip-descriptions')
            ->everyFourHours()
            ->withoutOverlapping(180)
            ->runInBackground();
        $schedule->command('wholesalers:sync-cva-catalog --batch=LG')
            ->dailyAt('04:15')
            ->withoutOverlapping(240)
            ->runInBackground();
        // Stock bajo: refresca snapshots de mayoristas con fuente registrada (CVA catálogo, CT SKUs vigilados…).
        $schedule->command('wholesalers:poll-low-stock')
            ->everyTwoHours()
            ->withoutOverlapping(90)
            ->runInBackground();
    })
    ->create();
