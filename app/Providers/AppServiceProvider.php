<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $login = $request->input('username') ?? $request->input('email') ?? '';
            $login = is_string($login) ? strtolower(trim($login)) : '';

            return [
                Limit::perMinute(5)->by('login:account:'.hash('sha256', $login.'|'.$request->ip())),
                Limit::perMinute(30)->by('login:ip:'.$request->ip()),
            ];
        });
    }
}
