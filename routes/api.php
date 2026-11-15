<?php

declare(strict_types=1);

use App\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Contrato público del microservicio. Las rutas se registran con el prefijo
| "api" desde bootstrap/app.php, dentro del grupo de middleware "api"
| (throttle:api + SubstituteBindings).
|
*/

Route::get('/regiones', [LocationController::class, 'getRegions'])
    ->name('api.regiones');

Route::get('/provincias/{idRegion}', [LocationController::class, 'getProvinces'])
    ->where('idRegion', '[0-9]{2}')
    ->name('api.provincias');

Route::get('/distritos/{idProvince}', [LocationController::class, 'getDistricts'])
    ->where('idProvince', '[0-9]{4}')
    ->name('api.distritos');

Route::get('/colegios/{ubigeo}', [LocationController::class, 'getSchools'])
    ->where('ubigeo', '[0-9]{6}')
    ->name('api.colegios');
