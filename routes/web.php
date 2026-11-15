<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| El servicio es API-only: no hay vistas ni frontend. La raíz devuelve
| metadatos útiles para verificar el despliegue y descubrir los endpoints.
|
*/

Route::get('/', function () {
    return response()->json([
        'service' => 'ESCALE Bridge API',
        'status' => 'ok',
        'php' => PHP_VERSION,
        'laravel' => app()->version(),
        'endpoints' => [
            'GET /api/regiones',
            'GET /api/provincias/{idRegion}',
            'GET /api/distritos/{idProvince}',
            'GET /api/colegios/{ubigeo}',
        ],
        'health' => '/up',
    ]);
})->name('home');
