<?php

namespace App\Modulos\ADM\Http\Controllers;

use App\Modulos\ADM\Services\Admision;
use App\Modulos\ADM\Services\RegistroAlumnos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** EDUN-53 · ADM-08 Admisión por etapas. */
class AdmisionController
{
    public function __construct(private Admision $admision) {}

    public function tablero(Request $request): JsonResponse
    {
        $anio = (int) $request->validate(['anio_escolar_id' => ['required', 'integer']])['anio_escolar_id'];

        return response()->json($this->admision->tablero($anio));
    }

    public function proceso(Request $request): JsonResponse
    {
        $d = $request->validate([
            'anio_escolar_id' => ['required', 'integer'], 'nombre' => ['required', 'string', 'max:80'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'], 'fecha_fin' => ['required', 'date_format:Y-m-d'],
        ], ['nombre.required' => 'Escribe el nombre del proceso (p. ej. Admisión 2027).']);

        return response()->json(['id' => $this->admision->crearProceso((int) $d['anio_escolar_id'], $d['nombre'], $d['fecha_inicio'], $d['fecha_fin'])], 201);
    }

    public function vacantes(Request $request): JsonResponse
    {
        $d = $request->validate(['anio_escolar_id' => ['required', 'integer'], 'grado_id' => ['required', 'integer'], 'cantidad' => ['required', 'integer', 'between:0,500']]);
        $this->admision->guardarVacantes((int) $d['anio_escolar_id'], (int) $d['grado_id'], (int) $d['cantidad']);

        return response()->json(['message' => 'Vacantes guardadas.']);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'admision_proceso_id' => ['required', 'integer'],
            'grado_id' => ['required', 'integer'],
            'apellidos' => ['required', 'string', 'max:160'],
            'nombres' => ['required', 'string', 'max:120'],
            'numero_documento' => ['nullable', 'digits:8'],
            'contacto_nombre' => ['required', 'string', 'max:160'],
            'contacto_celular' => ['required', 'regex:/^\+?[0-9 ]{9,15}$/'],
            'contacto_email' => ['nullable', 'email:rfc', 'max:150'],
            'origen' => ['nullable', 'string', 'max:30'],
        ], [
            'apellidos.required' => 'Escribe los apellidos del postulante.', 'nombres.required' => 'Escribe los nombres del postulante.',
            'contacto_nombre.required' => 'Escribe el nombre de la persona de contacto.', 'contacto_celular.required' => 'Escribe el celular de contacto.',
            'numero_documento.digits' => 'El DNI debe tener 8 dígitos.',
        ]);

        return response()->json(['id' => $this->admision->registrar($d, $request->user()->id)], 201);
    }

    public function etapa(Request $request, int $postulante): JsonResponse
    {
        $d = $request->validate(['etapa' => ['required', Rule::in(array_keys(Admision::ETAPAS))]]);
        $this->admision->moverEtapa($postulante, $d['etapa'], $request->user()->id);

        return response()->json(['message' => 'Etapa actualizada.']);
    }

    public function entrevista(Request $request, int $postulante): JsonResponse
    {
        $d = $request->validate(['fecha' => ['required', 'date_format:Y-m-d\TH:i'], 'lugar' => ['nullable', 'string', 'max:120']], ['fecha.required' => 'Elige la fecha y la hora.']);

        return response()->json($this->admision->agendarEntrevista($postulante, $d['fecha'], $d['lugar'] ?? null, $request->user()->id));
    }

    public function historia(int $postulante): JsonResponse
    {
        return response()->json(['data' => $this->admision->historia($postulante)]);
    }

    public function convertir(Request $request, int $postulante): JsonResponse
    {
        $d = $request->validate([
            'numero_documento' => ['required', 'digits:8'],
            'apellidos' => ['required', 'string', 'max:160'],
            'nombres' => ['required', 'string', 'max:120'],
            'parentesco' => ['required', Rule::in(RegistroAlumnos::PARENTESCOS)],
        ], ['numero_documento.required' => 'Escribe el DNI del apoderado.', 'numero_documento.digits' => 'El DNI del apoderado debe tener 8 dígitos.']);

        return response()->json(['alumno_id' => $this->admision->convertir($postulante, $d, $request->user()->id)]);
    }
}
