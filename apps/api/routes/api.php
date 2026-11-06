<?php

use App\Http\Controllers\SaludController;
use App\Modulos\Compartido\Correo\EventosCorreoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Todas las rutas quedan bajo /api/v1 (bootstrap/app.php, apiPrefix).

Route::get('/salud', SaludController::class);

// EDUN-208 · Rebotes y quejas del proveedor de correo (firmado con HMAC).
Route::post('/correo/eventos', EventosCorreoController::class)->middleware('throttle:60,1');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
