<?php

namespace App\Modulos\ADM\Http\Controllers;

use App\Modulos\ADM\Services\Personal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** EDUN-47 · ADM-04 Personal docente y administrativo. */
class PersonalController
{
    public function __construct(private Personal $personal) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['buscar' => ['nullable', 'string', 'max:100'], 'estado' => ['nullable', Rule::in(['activo', 'cesado'])]]);

        return response()->json(['data' => $this->personal->listar($f)]);
    }

    public function store(Request $request): JsonResponse
    {
        $id = $this->personal->guardar($this->validar($request));
        $invitacion = null;
        if ($request->boolean('invitar')) {
            $inv = $this->validarInvitacion($request);
            $invitacion = $this->personal->invitar($request->user(), $id, $inv['roles'], $inv['canal']);
        }

        return response()->json(['id' => $id, 'invitacion' => $invitacion], 201);
    }

    public function update(Request $request, int $persona): JsonResponse
    {
        $this->personal->guardar($this->validar($request), $persona);

        return response()->json(['id' => $persona]);
    }

    public function invitar(Request $request, int $persona): JsonResponse
    {
        $inv = $this->validarInvitacion($request);

        return response()->json($this->personal->invitar($request->user(), $persona, $inv['roles'], $inv['canal']));
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'tipo_documento' => ['nullable', Rule::in(['DNI', 'CE', 'PASAPORTE'])],
            'numero_documento' => ['required', 'string', 'max:15', Rule::when($request->input('tipo_documento', 'DNI') === 'DNI', 'digits:8')],
            'apellidos' => ['required', 'string', 'max:160'],
            'nombres' => ['required', 'string', 'max:120'],
            'cargo' => ['required', 'string', 'max:60'],
            'especialidad' => ['nullable', 'string', 'max:80'],
            'tipo_contrato' => ['nullable', Rule::in(['nombrado', 'plazo_fijo', 'indeterminado', 'locacion', 'practicas'])],
            'contrato_fin' => ['nullable', 'date_format:Y-m-d'],
            'colegiatura' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'celular' => ['nullable', 'regex:/^\+?[0-9 ]{9,15}$/'],
            'estado' => ['nullable', Rule::in(['activo', 'cesado'])],
        ], [
            'numero_documento.required' => 'Escribe el número de documento.',
            'numero_documento.digits' => 'El DNI debe tener 8 dígitos.',
            'apellidos.required' => 'Escribe los apellidos.',
            'nombres.required' => 'Escribe los nombres.',
            'cargo.required' => 'Escribe el cargo.',
            'email.email' => 'El correo no es válido.',
            'celular.regex' => 'El celular no es válido.',
        ]);
    }

    /** @return array{roles: list<string>, canal: string} */
    private function validarInvitacion(Request $request): array
    {
        return $request->validate([
            'roles' => ['required', 'array', 'min:1', 'max:6'],
            'roles.*' => ['string', 'max:30'],
            'canal' => ['required', Rule::in(['correo', 'whatsapp'])],
        ], ['roles.required' => 'Elige al menos un rol para su usuario.', 'canal.required' => 'Elige cómo enviar la invitación.']);
    }
}
