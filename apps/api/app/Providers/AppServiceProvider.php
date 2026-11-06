<?php

namespace App\Providers;

use App\Models\User;
use App\Modulos\ADM\Models\Alumno;
use App\Modulos\ADM\Models\Seccion;
use App\Modulos\ADM\Policies\AlumnoPolicy;
use App\Modulos\ADM\Policies\SeccionPolicy;
use App\Modulos\SEG\Services\Autorizacion;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Una instancia por petición (o por job): guarda los permisos ya calculados.
        $this->app->scoped(Autorizacion::class);
    }

    public function boot(): void
    {
        // EDUN-209 · Límites de uso por IP (por cuenta, el ingreso ya bloquea tras 5 intentos).
        RateLimiter::for('ingreso', fn (Request $r) => Limit::perMinute(20)->by('ip:'.$r->ip())
            ->response(fn () => response()->json(['message' => 'Demasiadas solicitudes. Espera un minuto e inténtalo de nuevo.'], 429)));
        RateLimiter::for('formularios', fn (Request $r) => [
            Limit::perMinute(10)->by('ip:'.$r->ip()),
            Limit::perHour(60)->by('ip-hora:'.$r->ip()),
        ]);
        RateLimiter::for('publico', fn (Request $r) => Limit::perMinute(120)->by('ip:'.$r->ip()));

        // EDUN-39 · Policies de propiedad.
        Gate::policy(Alumno::class, AlumnoPolicy::class);
        Gate::policy(Seccion::class, SeccionPolicy::class);

        // Los permisos del catálogo (modulo.recurso.accion) funcionan con can() y Gate.
        Gate::before(function (User $usuario, string $habilidad) {
            if (substr_count($habilidad, '.') === 2) {
                return app(Autorizacion::class)->tiene($usuario, $habilidad) ?: null;
            }

            return null;
        });
    }
}
