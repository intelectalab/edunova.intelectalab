<?php

use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\ContarErrores;
use App\Modulos\PLA\Http\Middleware\AccesoPlataforma;
use App\Modulos\PLA\Http\Middleware\VerificarModulo;
use App\Modulos\SEG\Http\Middleware\IdentificarInstitucion;
use App\Modulos\SEG\Http\Middleware\VerificarPermiso;
use App\Modulos\SIT\Http\Middleware\AccesoCms;
use App\Modulos\SIT\Http\Middleware\VisitanteSitio;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // EDUN-209 · Cabeceras de seguridad en todas las respuestas de la API.
        $middleware->api(append: [CabecerasSeguridad::class]);
        // EDUN-206 · Cuenta los 5xx para las alertas del monitoreo.
        $middleware->api(prepend: [ContarErrores::class]);

        // EDUN-39 · Colegio activo (RLS) y permisos por ruta.
        $middleware->alias([
            'colegio' => IdentificarInstitucion::class,
            'permiso' => VerificarPermiso::class,
            // EDUN-25 · Módulo encendido para el colegio (403 si está apagado).
            'modulo' => VerificarModulo::class,
            // Consola interna de Edunova: staff con segundo factor.
            'plataforma' => AccesoPlataforma::class,
            // EDUN-226 · Panel CMS: solo superadmin; fija app.rol_cms y app.usuario_id.
            'cms' => AccesoCms::class,
            // Sprint 6 · IP y navegador del visitante en los formularios del sitio.
            'visitante' => VisitanteSitio::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/v1/*') || $request->expectsJson(),
        );

        // Mensajes en español para la API (docs/13-convenciones.md).
        $exceptions->render(fn (AuthorizationException|AccessDeniedHttpException $e, Request $request) => $request->is('api/v1/*')
            ? response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403)
            : null);
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->is('api/v1/*')
            ? response()->json(['message' => 'No encontramos lo que buscas.'], 404)
            : null);
    })->create();
