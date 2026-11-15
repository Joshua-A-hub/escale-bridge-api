<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Registra los servicios de la aplicación.
     */
    public function register(): void
    {
        //
    }

    /**
     * Arranca los servicios de la aplicación.
     */
    public function boot(): void
    {
        // Límite histórico de la API: 60 peticiones por minuto por usuario/IP.
        // Se define acá porque Laravel 13 ya no usa RouteServiceProvider.
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
