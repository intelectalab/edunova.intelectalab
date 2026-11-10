<?php

namespace App\Modulos\ADM\Http\Controllers;

use App\Modulos\ADM\Models\Alumno;
use App\Modulos\ADM\Services\Carnes;
use App\Modulos\ADM\Services\CondicionesEconomicas;
use App\Modulos\ADM\Services\FichaAlumno;
use App\Modulos\ADM\Services\Matriculas;
use App\Modulos\ADM\Services\RegistroAlumnos;
use App\Modulos\SEG\Services\ActivacionFamilias;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SEG\Services\Consentimientos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Sprint 14 · Ficha completa (EDUN-52), condiciones económicas (EDUN-54), matrícula en
 * ventanilla y constancia (EDUN-56), retiro o traslado (EDUN-57), cambio de sección (EDUN-58),
 * carné con QR (EDUN-59), consentimientos en secretaría (EDUN-41) y activar padres (EDUN-37).
 */
class MatriculasController
{
    public function __construct(private Matriculas $matriculas) {}

    public function expediente(Request $request, int $alumno, FichaAlumno $ficha): JsonResponse
    {
        // El docente solo ve a los alumnos de sus secciones (policy de propiedad, EDUN-39).
        Gate::authorize('ver', Alumno::query()->findOrFail($alumno));

        return response()->json($ficha->completa($alumno, $request->user()));
    }

    public function condiciones(Request $request, CondicionesEconomicas $condiciones): JsonResponse
    {
        $anio = (int) $request->validate(['anio_escolar_id' => ['required', 'integer']])['anio_escolar_id'];

        return response()->json(['data' => $condiciones->versiones($anio)]);
    }

    public function publicarCondiciones(Request $request, CondicionesEconomicas $condiciones): JsonResponse
    {
        $d = $request->validate(
            ['anio_escolar_id' => ['required', 'integer'], 'contenido' => ['required', 'string', 'min:50', 'max:20000']],
            ['contenido.required' => 'Escribe las condiciones económicas.', 'contenido.min' => 'El texto es muy corto para unas condiciones económicas.'],
        );

        return response()->json(['data' => $condiciones->publicar((int) $d['anio_escolar_id'], $d['contenido'])], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'alumno_id' => ['required', 'integer'],
            'seccion_id' => ['required', 'integer'],
            'apoderado_id' => ['required', 'integer'],
            'acepta_condiciones' => ['accepted'],
            'tipo' => ['nullable', Rule::in(['nuevo', 'ratificacion', 'traslado'])],
        ], [
            'seccion_id.required' => 'Elige la sección.',
            'apoderado_id.required' => 'Elige al apoderado que firma.',
            'acepta_condiciones.accepted' => 'El apoderado debe aceptar las condiciones económicas.',
        ]);
        $r = $this->matriculas->matricularEnVentanilla((int) $d['alumno_id'], (int) $d['seccion_id'], (int) $d['apoderado_id'], $d['tipo'] ?? 'ratificacion', (string) $request->ip());

        return response()->json($r, 201);
    }

    public function constancia(int $matricula): Response
    {
        return response($this->matriculas->constancia($matricula), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="constancia-matricula.pdf"',
        ]);
    }

    public function retiro(Request $request, int $matricula): JsonResponse
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(['retiro', 'traslado'])],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'max:500'],
            'ie_destino' => ['nullable', 'string', 'max:200'],
        ], ['motivo.required' => 'Escribe el motivo.', 'fecha.required' => 'Elige la fecha.']);

        return response()->json($this->matriculas->retirar($matricula, $d['tipo'], $d['fecha'], $d['motivo'], $d['ie_destino'] ?? null, $request->user()->id));
    }

    public function cambioSeccion(Request $request, int $matricula): JsonResponse
    {
        $d = $request->validate([
            'seccion_id' => ['required', 'integer'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ], ['seccion_id.required' => 'Elige la sección de destino.']);

        return response()->json($this->matriculas->cambiarSeccion($matricula, (int) $d['seccion_id'], $d['fecha'], $d['motivo'] ?? null, $request->user()->id));
    }

    public function pendientesSiagie(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->matriculas->pendientesSiagie($request->boolean('todos'))]);
    }

    public function atenderPendiente(Request $request, int $pendiente): JsonResponse
    {
        $this->matriculas->atenderPendiente($pendiente, $request->user()->id);

        return response()->json(['message' => 'Marcado como registrado en el SIAGIE.']);
    }

    public function invitarApoderado(Request $request, int $alumno, int $apoderado, ActivacionFamilias $familias): JsonResponse
    {
        abort_unless(DB::table('alumno_apoderado')->where('alumno_id', $alumno)->where('apoderado_id', $apoderado)->exists(), 404);
        $canal = $request->validate(['canal' => ['required', Rule::in(['correo', 'whatsapp'])]])['canal'];
        $p = DB::table('apoderados')->find($apoderado);
        if ($canal === 'correo' ? ! $p->email : ! $p->celular) {
            return response()->json(['message' => $canal === 'correo' ? 'El apoderado no tiene correo.' : 'El apoderado no tiene celular.'], 422);
        }

        return response()->json($familias->activar($apoderado, $request->user(), $canal));
    }

    public function consentimientos(Request $request, Consentimientos $consentimientos): JsonResponse
    {
        $seccion = $request->validate(['seccion_id' => ['nullable', 'integer']])['seccion_id'] ?? null;
        $alumnos = app(RegistroAlumnos::class)->listar(['seccion_id' => $seccion]);
        $estado = $consentimientos->estado(array_column($alumnos, 'id'));

        return response()->json([
            'textos' => $consentimientos->textos(),
            'finalidades' => Consentimientos::FINALIDADES,
            'data' => array_map(fn ($a) => [
                'id' => $a['id'], 'alumno' => implode(' ', array_filter([$a['apellido_paterno'], $a['apellido_materno']])).', '.$a['nombres'], 'seccion' => $a['seccion'],
                'consentimientos' => $estado[$a['id']],
            ], $alumnos),
        ]);
    }

    public function publicarTexto(Request $request, Consentimientos $consentimientos): JsonResponse
    {
        $d = $request->validate([
            'finalidad' => ['required', Rule::in(array_keys(Consentimientos::FINALIDADES))],
            'version' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/'],
            'contenido' => ['required', 'string', 'min:50', 'max:20000'],
        ], ['version.required' => 'Escribe el número de versión (p. ej. 2027.1).', 'contenido.min' => 'El texto es muy corto.']);

        return response()->json(['textos' => $consentimientos->publicarTexto($d['finalidad'], $d['version'], $d['contenido'], $request->user()->id)], 201);
    }

    public function registrarConsentimiento(Request $request, Consentimientos $consentimientos): JsonResponse
    {
        $d = $request->validate([
            'alumno_id' => ['required', 'integer'],
            'finalidad' => ['required', Rule::in(array_keys(Consentimientos::FINALIDADES))],
            'otorgado' => ['required', 'boolean'],
        ]);
        abort_unless(DB::table('alumnos')->where('id', $d['alumno_id'])->exists(), 404);
        $consentimientos->registrar((int) $d['alumno_id'], $d['finalidad'], (bool) $d['otorgado'], $request->user()->id, 'secretaria');

        return response()->json(['consentimientos' => $consentimientos->estado([(int) $d['alumno_id']])[(int) $d['alumno_id']]]);
    }

    public function foto(Request $request, int $alumno, AlmacenArchivos $almacen, Auditoria $auditoria): JsonResponse
    {
        abort_unless(DB::table('alumnos')->where('id', $alumno)->exists(), 404);
        $d = $request->validate(
            ['foto' => ['required', 'file', 'max:3072', 'mimes:jpg,jpeg,png,webp']],
            ['foto.required' => 'Elige la foto.', 'foto.mimes' => 'La foto debe ser JPG, PNG o WEBP.', 'foto.max' => 'La foto debe pesar menos de 3 MB.'],
        );
        $archivo = $almacen->guardar($d['foto'], $request->user()->id, confidencial: true);
        DB::table('alumnos')->where('id', $alumno)->update(['foto_archivo_id' => $archivo->id]);
        $auditoria->registrar('foto_alumno', 'alumnos', $alumno, null, ['foto_archivo_id' => $archivo->id]);

        return response()->json(['message' => 'Foto guardada.', 'imagen_autorizada' => app(Consentimientos::class)->imagenAutorizada($alumno)]);
    }

    public function carne(int $alumno, Carnes $carnes): JsonResponse
    {
        abort_unless(DB::table('alumnos')->where('id', $alumno)->exists(), 404);

        return response()->json($carnes->datos($alumno));
    }

    public function regenerarCarne(int $alumno, Carnes $carnes): JsonResponse
    {
        abort_unless(DB::table('alumnos')->where('id', $alumno)->exists(), 404);
        $carnes->regenerar($alumno);

        return response()->json($carnes->datos($alumno));
    }

    public function carnesPdf(Request $request, Carnes $carnes): Response
    {
        $d = $request->validate(['seccion_id' => ['nullable', 'required_without:alumno_id', 'integer'], 'alumno_id' => ['nullable', 'integer']]);
        $ids = isset($d['alumno_id'])
            ? DB::table('alumnos')->where('id', $d['alumno_id'])->pluck('id')->all()
            : DB::table('matriculas as m')->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->where('m.seccion_id', $d['seccion_id'])->where('m.estado', 'matriculado')
                ->orderBy('a.apellido_paterno')->orderBy('a.nombres')->pluck('a.id')->all();
        abort_if($ids === [], 404);

        return response($carnes->pdf(array_map('intval', $ids)), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="carnes.pdf"']);
    }

    public function buscarCarne(string $codigo, Carnes $carnes): JsonResponse
    {
        $alumno = $carnes->buscar($codigo);
        abort_if(! $alumno, 404);

        return response()->json($alumno);
    }
}
