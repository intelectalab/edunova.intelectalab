<?php

namespace App\Modulos\SEG\Http\Controllers;

use App\Modulos\ADM\Services\Carnes;
use App\Modulos\ADM\Services\CondicionesEconomicas;
use App\Modulos\ADM\Services\Matriculas;
use App\Modulos\ADM\Services\Ratificacion;
use App\Modulos\SEG\Services\Consentimientos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/me/* · Lo que ve la familia (y el alumno) en la app: sus hijos, los consentimientos
 * por aceptar o revocar (EDUN-41), las condiciones económicas (EDUN-54) y el carné (EDUN-59).
 * El acceso es por propiedad: el apoderado solo ve a los alumnos vinculados a él.
 */
class FamiliaController
{
    public function __construct(private Consentimientos $consentimientos) {}

    /** @return list<int> alumnos del usuario: sus hijos (apoderado) o él mismo (alumno) */
    public static function alumnosDe(int $usuarioId): array
    {
        return DB::table('alumno_apoderado as aa')->join('apoderados as ap', 'ap.id', '=', 'aa.apoderado_id')->where('ap.user_id', $usuarioId)
            ->pluck('aa.alumno_id')->merge(DB::table('alumnos')->where('user_id', $usuarioId)->pluck('id'))
            ->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

    public function familia(Request $request, CondicionesEconomicas $condiciones): JsonResponse
    {
        $ids = self::alumnosDe($request->user()->id);
        $estado = $this->consentimientos->estado($ids);
        $hijos = DB::table('alumnos as a')
            ->leftJoin('matriculas as m', fn ($j) => $j->on('m.alumno_id', '=', 'a.id')->whereIn('m.estado', ['matriculado', 'reservado']))
            ->leftJoin('secciones as s', 's.id', '=', 'm.seccion_id')->leftJoin('grados as g', 'g.id', '=', 's.grado_id')
            ->whereIn('a.id', $ids)->whereNull('a.eliminado_at')->orderBy('a.nombres')
            ->get(['a.id', 'a.nombres', 'a.apellido_paterno', 'a.apellido_materno', DB::raw("g.nombre || ' ' || s.nombre as seccion")])
            ->unique('id')->values()
            ->map(fn ($a) => (array) $a + ['consentimientos' => $estado[$a->id]])->all();

        $anio = DB::table('anios_escolares')->orderByRaw("estado = 'abierto' desc")->orderByDesc('anio')->first(['id', 'anio']);
        $vigentes = $anio ? $condiciones->vigente((int) $anio->id) : null;

        return response()->json([
            'hijos' => $hijos,
            'textos' => $this->consentimientos->textos(),
            'finalidades' => Consentimientos::FINALIDADES,
            'condiciones' => $vigentes ? ['anio' => $anio->anio, 'version' => $vigentes->version, 'contenido' => $vigentes->contenido] : null,
        ]);
    }

    public function consentir(Request $request): JsonResponse
    {
        $d = $request->validate([
            'alumno_id' => ['required', 'integer'],
            'finalidad' => ['required', Rule::in(array_keys(Consentimientos::FINALIDADES))],
            'otorgado' => ['required', 'boolean'],
        ]);
        abort_unless(in_array((int) $d['alumno_id'], self::alumnosDe($request->user()->id), true), 404);
        $this->consentimientos->registrar((int) $d['alumno_id'], $d['finalidad'], (bool) $d['otorgado'], $request->user()->id, 'app');

        return response()->json(['consentimientos' => $this->consentimientos->estado([(int) $d['alumno_id']])[(int) $d['alumno_id']]]);
    }

    public function carne(Request $request, int $alumno, Carnes $carnes): JsonResponse
    {
        abort_unless(in_array($alumno, self::alumnosDe($request->user()->id), true), 404);

        return response()->json($carnes->datos($alumno));
    }

    /** EDUN-55 · Ratificación de matrícula desde la app. */
    public function ratificacion(Request $request, Ratificacion $ratificacion): JsonResponse
    {
        return response()->json($ratificacion->opciones((int) $request->user()->id));
    }

    public function ratificar(Request $request, Ratificacion $ratificacion): JsonResponse
    {
        $d = $request->validate([
            'alumno_id' => ['required', 'integer'], 'acepta_condiciones' => ['accepted'],
            'celular' => ['nullable', 'regex:/^9\d{8}$/'], 'email' => ['nullable', 'email', 'max:150'], 'direccion' => ['nullable', 'string', 'max:250'],
        ], ['acepta_condiciones.accepted' => 'Debes aceptar las condiciones económicas para ratificar.', 'celular.regex' => 'El celular tiene 9 dígitos y empieza con 9.']);

        return response()->json($ratificacion->ratificar((int) $request->user()->id, (int) $d['alumno_id'], $d, (string) $request->ip()), 201);
    }

    public function constancia(Request $request, int $matricula, Matriculas $matriculas): Response
    {
        $m = DB::table('matriculas')->find($matricula);
        abort_unless($m && $m->estado === 'matriculado' && in_array((int) $m->alumno_id, self::alumnosDe((int) $request->user()->id), true), 404);

        return response($matriculas->constancia($matricula), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"constancia-matricula-{$matricula}.pdf\""]);
    }
}
