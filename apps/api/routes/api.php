<?php

use App\Http\Controllers\SaludController;
use App\Modulos\ADM\Http\Controllers\AlumnosController;
use App\Modulos\Compartido\Correo\EventosCorreoController;
use App\Modulos\SEG\Http\Controllers\SesionController;
use App\Modulos\SIT\Http\Controllers\EditorCmsController;
use App\Modulos\SIT\Http\Controllers\LandingController;
use App\Modulos\SIT\Http\Controllers\MediosCmsController;
use App\Modulos\SIT\Http\Controllers\SesionCmsController;
use Illuminate\Support\Facades\Route;

// Todas las rutas quedan bajo /api/v1 (bootstrap/app.php, apiPrefix).

Route::get('/salud', SaludController::class);

// EDUN-208 · Rebotes y quejas del proveedor de correo (firmado con HMAC).
Route::post('/correo/eventos', EventosCorreoController::class)->middleware('throttle:60,1');

// EDUN-35 · Sesión: ingreso con DNI o correo, elección de colegio, quién soy y salir.
Route::prefix('auth')->group(function () {
    Route::post('/ingresar', [SesionController::class, 'ingresar'])->middleware('throttle:20,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/colegio', [SesionController::class, 'elegirColegio']);
        Route::get('/yo', [SesionController::class, 'yo']);
        Route::post('/salir', [SesionController::class, 'salir']);
    });
});

// Rutas del colegio: sesión, colegio activo (RLS) y permiso por ruta (EDUN-39).
Route::middleware(['auth:sanctum', 'colegio'])->group(function () {
    Route::get('/alumnos', [AlumnosController::class, 'index'])->middleware('permiso:adm.alumnos.ver');
    // Sin permiso de ruta: la policy deja pasar al personal con adm.alumnos.ver y a la familia por propiedad.
    Route::get('/alumnos/{alumno}', [AlumnosController::class, 'show'])->whereNumber('alumno');
});

// EDUN-226 · Panel CMS: solo el superadmin del SaaS. Los colegios no tienen CMS.
Route::middleware(['auth:sanctum', 'cms'])->prefix('cms')->group(function () {
    Route::get('/sesion', SesionCmsController::class);
    // EDUN-233 · Borrador y publicación.
    Route::get('/estado', [EditorCmsController::class, 'estado']);
    Route::get('/secciones/{clave}', [EditorCmsController::class, 'mostrar']);
    Route::put('/secciones/{clave}', [EditorCmsController::class, 'guardar']);
    Route::post('/publicar', [EditorCmsController::class, 'publicar']);
    // EDUN-237 · Biblioteca de medios.
    Route::get('/medios', [MediosCmsController::class, 'index']);
    Route::post('/medios', [MediosCmsController::class, 'store'])->middleware('throttle:30,1');
    Route::patch('/medios/{medio}', [MediosCmsController::class, 'update'])->whereNumber('medio');
});

// Sitio público (sin sesión): solo contenido publicado.
Route::get('/sitio/landing', LandingController::class)->middleware('throttle:120,1');
