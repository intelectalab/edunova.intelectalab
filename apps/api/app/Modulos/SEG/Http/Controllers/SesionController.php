<?php

namespace App\Modulos\SEG\Http\Controllers;

use App\Models\User;
use App\Modulos\SEG\Services\Sesion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** EDUN-35 · /api/v1/auth/*: ingresar, elegir colegio, quién soy y salir. */
class SesionController
{
    public function __construct(private Sesion $sesion) {}

    public function ingresar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'usuario' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
            'dispositivo' => ['nullable', 'string', 'max:60'],
        ], [
            'usuario.required' => 'Escribe tu DNI o correo.',
            'password.required' => 'Escribe tu contraseña.',
        ]);

        $r = $this->sesion->ingresar($datos['usuario'], $datos['password'], $datos['dispositivo'] ?? 'web', (string) $request->ip());
        $token = PersonalAccessToken::findToken($r['token']);

        return response()->json(['token' => $r['token']] + $this->estado($r['usuario'], $token));
    }

    public function elegirColegio(Request $request): JsonResponse
    {
        $datos = $request->validate(['institucion_id' => ['required', 'integer']], ['institucion_id.required' => 'Elige un colegio.']);
        $token = $request->user()->currentAccessToken();
        $this->sesion->elegirColegio($request->user(), $token, (int) $datos['institucion_id']);

        return response()->json($this->estado($request->user(), $token->refresh()));
    }

    public function yo(Request $request): JsonResponse
    {
        return response()->json($this->estado($request->user(), $request->user()->currentAccessToken()));
    }

    public function salir(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    private function estado(User $usuario, ?PersonalAccessToken $token): array
    {
        $colegios = $this->sesion->colegios($usuario);
        $activo = $token?->institucion_id ? collect($colegios)->firstWhere('id', (int) $token->institucion_id) : null;

        return [
            'usuario' => [
                'id' => $usuario->id,
                'nombres' => $usuario->nombres,
                'apellidos' => $usuario->apellidos,
                'email' => $usuario->email,
            ],
            'colegios' => $colegios,
            'colegio_activo' => $activo,
            'debe_elegir_colegio' => $activo === null && count($colegios) > 1,
            'roles_plataforma' => $this->sesion->rolesPlataforma($usuario),
        ];
    }
}
