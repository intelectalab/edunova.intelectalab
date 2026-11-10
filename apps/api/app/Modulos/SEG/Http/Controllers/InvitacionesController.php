<?php

namespace App\Modulos\SEG\Http\Controllers;

use App\Modulos\SEG\Services\Invitaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** EDUN-36 · Enlace de invitación (sin sesión): ver a quién invita y crear la contraseña. */
class InvitacionesController
{
    public function __construct(private Invitaciones $invitaciones) {}

    public function mostrar(string $token): JsonResponse
    {
        $invitacion = $this->invitaciones->ver($token);
        if (! $invitacion) {
            return response()->json(['message' => 'El enlace ya se usó o venció. Pide una invitación nueva.'], 410);
        }

        return response()->json($invitacion);
    }

    public function aceptar(Request $request, string $token): JsonResponse
    {
        $datos = $request->validate([
            'password' => ['nullable', 'string', 'max:200', 'confirmed', Password::min(10)->letters()->numbers()],
        ], [
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
            'password.letters' => 'La contraseña debe tener al menos una letra.',
            'password.numbers' => 'La contraseña debe tener al menos un número.',
        ]);
        $this->invitaciones->aceptar($token, $datos['password'] ?? null);

        return response()->json(['message' => 'Listo. Ya puedes ingresar a Edunova.']);
    }
}
