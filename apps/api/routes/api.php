<?php

use App\Http\Controllers\SaludController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Todas las rutas quedan bajo /api/v1 (bootstrap/app.php, apiPrefix).

Route::get('/salud', SaludController::class);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
