<?php

namespace App\Modulos\SEG\Http\Controllers;

use App\Modulos\SEG\Services\UsuariosColegio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** EDUN-36 · SEG-02 Usuarios del colegio activo. */
class UsuariosController
{
    public function __construct(private UsuariosColegio $usuarios) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'rol' => ['nullable', 'string', 'max:30'],
            'estado' => ['nullable', Rule::in(['activo', 'pendiente', 'inactivo'])],
        ]);

        return response()->json(['data' => $this->usuarios->listar($filtros), 'roles' => $this->usuarios->rolesAsignables($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombres' => ['required', 'string', 'max:120'],
            'apellidos' => ['required', 'string', 'max:160'],
            'tipo_documento' => ['nullable', Rule::in(['DNI', 'CE', 'PASAPORTE'])],
            'numero_documento' => ['nullable', 'string', 'max:15', Rule::when($request->input('tipo_documento', 'DNI') === 'DNI', 'digits:8')],
            'email' => ['nullable', 'required_if:canal,correo', 'email:rfc', 'max:150'],
            'celular' => ['nullable', 'required_if:canal,whatsapp', 'regex:/^\+?[0-9 ]{9,15}$/'],
            'roles' => ['required', 'array', 'min:1', 'max:6'],
            'roles.*' => ['string', 'max:30'],
            'canal' => ['required', Rule::in(['correo', 'whatsapp'])],
        ], [
            'nombres.required' => 'Escribe los nombres.',
            'apellidos.required' => 'Escribe los apellidos.',
            'numero_documento.digits' => 'El DNI debe tener 8 dígitos.',
            'email.required_if' => 'Escribe el correo para enviar la invitación.',
            'email.email' => 'El correo no es válido.',
            'celular.required_if' => 'Escribe el celular para invitar por WhatsApp.',
            'celular.regex' => 'El celular no es válido.',
            'roles.required' => 'Elige al menos un rol.',
            'roles.min' => 'Elige al menos un rol.',
        ]);

        return response()->json($this->usuarios->invitar($request->user(), $datos), 201);
    }

    public function roles(Request $request, int $usuario): JsonResponse
    {
        $datos = $request->validate(['roles' => ['required', 'array', 'min:1', 'max:6'], 'roles.*' => ['string', 'max:30']], ['roles.required' => 'Elige al menos un rol.', 'roles.min' => 'Elige al menos un rol.']);
        $this->usuarios->cambiarRoles($request->user(), $usuario, $datos['roles']);

        return response()->json(['message' => 'Roles actualizados.']);
    }

    public function estado(Request $request, int $usuario): JsonResponse
    {
        $datos = $request->validate(['activo' => ['required', 'boolean']]);
        $this->usuarios->cambiarEstado($request->user(), $usuario, (bool) $datos['activo']);

        return response()->json(['message' => $datos['activo'] ? 'Usuario reactivado.' : 'Usuario desactivado.']);
    }

    public function invitacion(Request $request, int $usuario): JsonResponse
    {
        $datos = $request->validate(['canal' => ['required', Rule::in(['correo', 'whatsapp'])]]);

        return response()->json($this->usuarios->reinvitar($request->user(), $usuario, $datos['canal']));
    }
}
