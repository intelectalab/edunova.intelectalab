<?php

use App\Modulos\SEG\Http\Middleware\IdentificarInstitucion;
use App\Modulos\SEG\Http\Middleware\VerificarPermiso;
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
        // EDUN-39 · Colegio activo (RLS) y permisos por ruta.
        $middleware->alias([
            'colegio' => IdentificarInstitucion::class,
            'permiso' => VerificarPermiso::class,
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
