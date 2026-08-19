<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

if (! function_exists('spaBaseHref')) {
  function spaBaseHref(): string
  {
    $appBase = rtrim(request()->getBaseUrl(), '/');

    return ($appBase !== '' ? $appBase : '').'/spa/';
  }
}

if (! function_exists('serveSpaIndex')) {
  function serveSpaIndex(): Response
{
  $indexPath = public_path('spa/index.html');

  if (! File::exists($indexPath)) {
    return redirect('/')->with('error', 'Compila el frontend: cd frontend && npm run build');
  }

  $html = File::get($indexPath);
  $base = spaBaseHref();

  if (! str_contains($html, '<base ')) {
    $html = str_replace('<head>', '<head>'."\n    ".'<base href="'.$base.'">', $html);
  }

  return response($html, 200, [
    'Content-Type' => 'text/html; charset=UTF-8',
    'Cache-Control' => 'no-cache, no-store, must-revalidate',
    'Pragma' => 'no-cache',
    'Expires' => '0',
  ]);
  }
}

Route::get('/', function () {
  if (File::exists(public_path('spa/index.html'))) {
    return redirect(spaBaseHref());
  }

  return view('spa-setup');
});

/** Enlaces sin prefijo /spa (marcadores, API, etc.) → SPA en /spa/... */
foreach (['login', 'dashboard', 'solicitudes', 'cotizaciones', 'clientes', 'mayoristas', 'reportes', 'configuracion', 'admin'] as $spaPrefix) {
  Route::get($spaPrefix, function () use ($spaPrefix) {
    return redirect(rtrim(spaBaseHref(), '/').'/'.$spaPrefix);
  });

  Route::get("{$spaPrefix}/{path}", function (string $path) use ($spaPrefix) {
    return redirect(rtrim(spaBaseHref(), '/')."/{$spaPrefix}/{$path}");
  })->where('path', '.*');
}

Route::get('/spa/assets/{path}', function (string $path) {
  $file = public_path('spa/assets/'.str_replace('..', '', $path));

  if (! File::isFile($file)) {
    abort(404);
  }

  $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
    'js' => 'application/javascript',
    'css' => 'text/css',
    'svg' => 'image/svg+xml',
    'woff2' => 'font/woff2',
    default => File::mimeType($file) ?: 'application/octet-stream',
  };

  return response()->file($file, [
    'Content-Type' => $mime,
    'Cache-Control' => 'public, max-age=3600, must-revalidate',
  ]);
})->where('path', '.*');

Route::get('/spa/{path?}', serveSpaIndex(...))->where('path', '.*')->name('spa');
