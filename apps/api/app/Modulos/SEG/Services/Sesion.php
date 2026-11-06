<?php

namespace App\Modulos\SEG\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * EDUN-35 / SEG-H01 · Ingreso con DNI o correo, elección de colegio y cierre de sesión.
 * El colegio elegido queda en el token; el middleware «colegio» lo fija en cada petición.
 */
class Sesion
{
    public const INTENTOS = 5;

    /** Mismo mensaje para usuario inexistente, contraseña errada o cuenta inactiva. */
    public const ERROR_CREDENCIALES = 'El documento, correo o contraseña no son correctos.';

    /** @return array{usuario: User, token: string} */
    public function ingresar(string $usuario, string $password, string $dispositivo, string $ip): array
    {
        $clave = 'ingreso:'.mb_strtolower(trim($usuario)).'|'.$ip;
        if (RateLimiter::tooManyAttempts($clave, self::INTENTOS)) {
            $minutos = (int) ceil(RateLimiter::availableIn($clave) / 60);
            throw ValidationException::withMessages(['usuario' => ["Demasiados intentos. Vuelve a intentarlo en {$minutos} min."]])->status(429);
        }

        $encontrado = $this->buscar($usuario);
        if (! $encontrado || ! $encontrado->activo || ! $encontrado->password || ! Hash::check($password, $encontrado->password)) {
            RateLimiter::hit($clave, 15 * 60);
            throw ValidationException::withMessages(['usuario' => [self::ERROR_CREDENCIALES]]);
        }
        RateLimiter::clear($clave);

        $encontrado->forceFill(['ultimo_acceso_at' => now()])->save();
        $token = $encontrado->createToken($dispositivo);

        // Con un solo colegio, queda elegido de una vez.
        $colegios = $this->colegios($encontrado);
        if (count($colegios) === 1) {
            $token->accessToken->forceFill(['institucion_id' => $colegios[0]['id']])->save();
        }

        return ['usuario' => $encontrado, 'token' => $token->plainTextToken];
    }

    /** DNI (u otro documento) si son solo dígitos; si no, correo. */
    private function buscar(string $usuario): ?User
    {
        $usuario = trim($usuario);
        if (str_contains($usuario, '@')) {
            return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($usuario)])->first();
        }

        return User::query()->where('numero_documento', $usuario)->orderBy('id')->first();
    }

    /**
     * Colegios del usuario con sus roles (fn_instituciones_de_usuario).
     *
     * @return list<array{id: int, nombre: string, slug: string, roles: list<string>}>
     */
    public function colegios(User $usuario): array
    {
        $filas = DB::select('select * from fn_instituciones_de_usuario(?) order by nombre', [$usuario->id]);

        return collect($filas)->groupBy('institucion_id')->map(fn ($g) => [
            'id' => (int) $g[0]->institucion_id,
            'nombre' => $g[0]->nombre,
            'slug' => $g[0]->slug,
            'roles' => $g->pluck('rol')->unique()->values()->all(),
        ])->values()->all();
    }

    /** @return list<string> */
    public function rolesPlataforma(User $usuario): array
    {
        return array_map(fn ($f) => $f->rol, DB::select('select rol from fn_roles_plataforma(?)', [$usuario->id]));
    }

    public function elegirColegio(User $usuario, PersonalAccessToken $token, int $institucionId): void
    {
        $permitidos = array_column($this->colegios($usuario), 'id');
        if (! in_array($institucionId, $permitidos, true)) {
            throw ValidationException::withMessages(['institucion_id' => ['No perteneces a ese colegio.']])->status(403);
        }
        $token->forceFill(['institucion_id' => $institucionId])->save();
    }
}
