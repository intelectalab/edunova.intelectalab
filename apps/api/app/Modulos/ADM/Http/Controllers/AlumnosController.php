<?php

namespace App\Modulos\ADM\Http\Controllers;

use App\Modulos\ADM\Models\Alumno;
use App\Modulos\ADM\Services\ImportacionAlumnos;
use App\Modulos\ADM\Services\RegistroAlumnos;
use App\Modulos\SEG\Services\AlcanceAcademico;
use App\Modulos\SEG\Services\Autorizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * EDUN-39 / EDUN-50 / EDUN-51 · Alumnos: lista (según lo que cada rol puede ver), ficha,
 * registro con apoderados y autorizados, e importación desde Excel. Se busca el alumno
 * dentro del colegio activo (el RLS ya lo limita): si es de otro colegio, simplemente no existe.
 */
class AlumnosController
{
    public function __construct(private RegistroAlumnos $registro) {}

    public function index(Request $request, AlcanceAcademico $alcance): JsonResponse
    {
        $f = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'anio_escolar_id' => ['nullable', 'integer'],
            'seccion_id' => ['nullable', 'integer'],
            'estado' => ['nullable', Rule::in(['activo', 'retirado', 'egresado'])],
        ]);
        $usuario = $request->user();
        $soloIds = $alcance->veTodoElColegio($usuario) ? null : $alcance->alumnosVisibles($usuario);

        return response()->json(['data' => $this->registro->listar($f, $soloIds)]);
    }

    public function show(int $alumno): JsonResponse
    {
        $registro = Alumno::query()->findOrFail($alumno);
        Gate::authorize('ver', $registro);

        return response()->json(['data' => $registro->only(['id', 'apellido_paterno', 'apellido_materno', 'nombres', 'estado'])]);
    }

    public function ficha(int $alumno): JsonResponse
    {
        Gate::authorize('ver', Alumno::query()->findOrFail($alumno));

        return response()->json($this->registro->ficha($alumno));
    }

    public function store(Request $request, Autorizacion $autorizacion): JsonResponse
    {
        $d = $this->validar($request);
        if (! empty($d['seccion_id']) && ! $autorizacion->tiene($request->user(), 'adm.matricula.crear')) {
            return response()->json(['message' => 'No tienes permiso para matricular.'], 403);
        }
        $id = $this->registro->guardar($d);

        return response()->json($this->registro->ficha($id) + ['invitaciones' => $this->registro->invitaciones], 201);
    }

    public function update(Request $request, int $alumno, Autorizacion $autorizacion): JsonResponse
    {
        Alumno::query()->findOrFail($alumno);
        $d = $this->validar($request, $alumno);
        if (! empty($d['seccion_id']) && ! $autorizacion->tiene($request->user(), 'adm.matricula.crear')) {
            return response()->json(['message' => 'No tienes permiso para matricular.'], 403);
        }
        $this->registro->guardar($d, $alumno);

        return response()->json($this->registro->ficha($alumno) + ['invitaciones' => $this->registro->invitaciones]);
    }

    public function plantilla(Request $request, ImportacionAlumnos $importacion): BinaryFileResponse
    {
        $anio = (int) $request->validate(['anio_escolar_id' => ['required', 'integer']])['anio_escolar_id'];

        return response()->download($importacion->plantilla($anio), 'plantilla-alumnos.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function vistaPrevia(Request $request, ImportacionAlumnos $importacion): JsonResponse
    {
        $d = $request->validate([
            'anio_escolar_id' => ['required', 'integer'],
            'archivo' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt'],
        ], [
            'archivo.required' => 'Elige el archivo de Excel.',
            'archivo.max' => 'El archivo debe pesar menos de 5 MB.',
            'archivo.extensions' => 'Sube la plantilla en Excel (.xlsx) o un CSV.',
        ]);

        return response()->json($importacion->vistaPrevia($d['archivo'], (int) $d['anio_escolar_id'], $request->user()->id));
    }

    public function confirmarImportacion(Request $request, ImportacionAlumnos $importacion): JsonResponse
    {
        $token = (string) $request->validate(['token' => ['required', 'string', 'size:32']])['token'];

        return response()->json($importacion->confirmar($token, $request->user()->id));
    }

    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'tipo_documento' => ['nullable', Rule::in(['DNI', 'CE', 'PASAPORTE', 'PTP', 'OTRO'])],
            'numero_documento' => ['nullable', 'string', 'max:15', Rule::when($request->input('tipo_documento', 'DNI') === 'DNI', 'digits:8')],
            'codigo_estudiante_siagie' => ['nullable', 'digits:14'],
            'apellido_paterno' => ['required', 'string', 'max:80'],
            'apellido_materno' => ['nullable', 'string', 'max:80'],
            'nombres' => ['required', 'string', 'max:120'],
            'sexo' => ['nullable', Rule::in(['H', 'M'])],
            'fecha_nacimiento' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1990-01-01'],
            'seccion_id' => ['nullable', 'integer'],
            'tipo_matricula' => ['nullable', Rule::in(['nuevo', 'ratificacion', 'traslado'])],
            'apoderados' => ['sometimes', 'array', 'max:6'],
            'apoderados.*.tipo_documento' => ['nullable', Rule::in(['DNI', 'CE', 'PASAPORTE', 'PTP', 'OTRO'])],
            'apoderados.*.numero_documento' => ['required', 'string', 'max:15'],
            'apoderados.*.apellidos' => ['required', 'string', 'max:160'],
            'apoderados.*.nombres' => ['required', 'string', 'max:120'],
            'apoderados.*.celular' => ['nullable', 'regex:/^\+?[0-9 ]{9,15}$/'],
            'apoderados.*.email' => ['nullable', 'email:rfc', 'max:150'],
            'apoderados.*.direccion' => ['nullable', 'string', 'max:250'],
            'apoderados.*.ocupacion' => ['nullable', 'string', 'max:80'],
            'apoderados.*.parentesco' => ['required', Rule::in(RegistroAlumnos::PARENTESCOS)],
            'apoderados.*.es_responsable_pago' => ['nullable', 'boolean'],
            'apoderados.*.puede_recoger' => ['nullable', 'boolean'],
            'apoderados.*.recibe_notificaciones' => ['nullable', 'boolean'],
            'autorizados' => ['sometimes', 'array', 'max:10'],
            'autorizados.*.nombres' => ['required', 'string', 'max:200'],
            'autorizados.*.numero_documento' => ['required', 'string', 'max:15'],
            'autorizados.*.parentesco' => ['required', 'string', 'max:40'],
            'autorizados.*.vigente_hasta' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'numero_documento.digits' => 'El DNI del alumno debe tener 8 dígitos.',
            'codigo_estudiante_siagie.digits' => 'El código SIAGIE debe tener 14 dígitos.',
            'apellido_paterno.required' => 'Escribe el apellido paterno.',
            'nombres.required' => 'Escribe los nombres.',
            'fecha_nacimiento.before' => 'La fecha de nacimiento no puede ser futura.',
            'apoderados.*.numero_documento.required' => 'Escribe el documento de cada apoderado.',
            'apoderados.*.apellidos.required' => 'Escribe los apellidos de cada apoderado.',
            'apoderados.*.nombres.required' => 'Escribe los nombres de cada apoderado.',
            'apoderados.*.parentesco.required' => 'Elige el parentesco de cada apoderado.',
            'apoderados.*.celular.regex' => 'El celular de un apoderado no es válido.',
            'apoderados.*.email.email' => 'El correo de un apoderado no es válido.',
            'autorizados.*.nombres.required' => 'Escribe el nombre de cada autorizado.',
            'autorizados.*.numero_documento.required' => 'Escribe el documento de cada autorizado.',
            'autorizados.*.parentesco.required' => 'Escribe el parentesco o relación de cada autorizado.',
        ]);
    }
}
