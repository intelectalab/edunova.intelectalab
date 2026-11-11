<?php

namespace App\Modulos\CAL\Services;

use App\Models\User;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SEG\Services\Autorizacion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CAL · Registro de notas.
 *
 * - EDUN-91 · CAL-H01 Grilla por competencia con la escala del grado (literal AD/A/B/C o
 *   vigesimal 00–20), guardado celda por celda, vacíos e inválidos marcados y conclusión
 *   descriptiva obligatoria con C (exigencia del SIAGIE).
 * - EDUN-93 · CAL-H03 Actividades de evaluación por competencia y periodo con nota y comentario
 *   por alumno. Son insumo: la grilla muestra una sugerencia y el docente confirma la oficial.
 * - Con el periodo o la sección cerrados la BD rechaza cambios (trigger); aquí se traduce a un
 *   mensaje claro.
 */
class RegistroNotas
{
    public const LITERAL = ['AD' => 4, 'A' => 3, 'B' => 2, 'C' => 1];

    public function __construct(private Autorizacion $autorizacion, private Auditoria $auditoria) {}

    /** Cargas que el usuario califica (las suyas) o revisa (todas, con cal.notas.revisar). */
    public function cargas(User $usuario, int $anioId): array
    {
        $todas = $this->autorizacion->tiene($usuario, 'cal.notas.revisar');

        return DB::table('cargas_docentes as c')->join('secciones as s', 's.id', '=', 'c.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->join('areas_curriculares as a', 'a.id', '=', 'c.area_curricular_id')->join('personal as p', 'p.id', '=', 'c.personal_id')
            ->where('s.anio_escolar_id', $anioId)->when(! $todas, fn ($q) => $q->where('p.user_id', $usuario->id))
            ->orderBy('g.nivel')->orderBy('g.numero')->orderBy('s.nombre')->orderBy('a.orden')
            ->get(['c.id', 'c.seccion_id', DB::raw("g.nombre || ' ' || s.nombre as seccion"), 'a.nombre as area', 'g.escala_calificacion as escala', DB::raw("p.nombres || ' ' || p.apellidos as docente")])
            ->map(fn ($c) => (array) $c)->all();
    }

    public function periodos(int $anioId): array
    {
        return DB::table('periodos')->where('anio_escolar_id', $anioId)->orderBy('numero')->get(['id', 'numero', 'nombre', 'fecha_inicio', 'fecha_fin', 'fecha_limite_notas', 'estado'])->map(fn ($p) => (array) $p)->all();
    }

    public function carga(int $cargaId, User $usuario): object
    {
        $c = DB::table('cargas_docentes as c')->join('secciones as s', 's.id', '=', 'c.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->join('areas_curriculares as a', 'a.id', '=', 'c.area_curricular_id')->join('personal as p', 'p.id', '=', 'c.personal_id')
            ->where('c.id', $cargaId)->first(['c.id', 'c.seccion_id', 'c.area_curricular_id', 's.anio_escolar_id', 'g.escala_calificacion as escala', 'p.user_id',
                DB::raw("g.nombre || ' ' || s.nombre as seccion"), 'a.nombre as area']);
        abort_if(! $c, 404);
        abort_unless((int) $c->user_id === (int) $usuario->id || $this->autorizacion->tiene($usuario, 'cal.notas.revisar'), 403, 'Solo el docente del curso o la coordinación pueden ver estas notas.');

        return $c;
    }

    private function periodoDe(object $carga, int $periodoId): object
    {
        $p = DB::table('periodos')->where('id', $periodoId)->where('anio_escolar_id', $carga->anio_escolar_id)->first();
        if (! $p) {
            throw ValidationException::withMessages(['periodo_id' => ['Elige un periodo del año de la sección.']]);
        }

        return $p;
    }

    public static function valido(?string $valor, string $escala): bool
    {
        return $valor === null || ($escala === 'literal' ? isset(self::LITERAL[$valor]) : preg_match('/^(0[0-9]|1[0-9]|20)$/', $valor) === 1);
    }

    /** Alumnos matriculados de la sección, en orden alfabético. */
    private function alumnos(int $seccionId): Collection
    {
        return DB::table('matriculas as m')->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->where('m.seccion_id', $seccionId)->where('m.estado', 'matriculado')
            ->whereNull('a.eliminado_at')->orderBy('a.apellido_paterno')->orderBy('a.apellido_materno')->orderBy('a.nombres')
            ->get(['a.id', DB::raw("a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ', ' || a.nombres as nombre")]);
    }

    private function competencias(int $areaId): Collection
    {
        return DB::table('competencias')->where('area_curricular_id', $areaId)->where('vigente', true)->orderBy('orden')->orderBy('numero')
            ->get(['id', 'numero', 'nombre', 'nombre_corto']);
    }

    /** Sugerencia desde las actividades: promedio (en literal, con AD=4 … C=1) redondeado. */
    private function sugerencias(int $cargaId, int $periodoId, string $escala): array
    {
        $notas = DB::table('notas_actividad as n')->join('actividades_evaluacion as a', 'a.id', '=', 'n.actividad_evaluacion_id')
            ->where('a.carga_docente_id', $cargaId)->where('a.periodo_id', $periodoId)->get(['n.alumno_id', 'a.competencia_id', 'n.valor']);
        $porLiteral = array_flip(self::LITERAL);
        $r = [];
        foreach ($notas->groupBy(fn ($n) => $n->alumno_id.'-'.$n->competencia_id) as $clave => $grupo) {
            $vals = $grupo->map(fn ($n) => $escala === 'literal' ? (self::LITERAL[$n->valor] ?? null) : (is_numeric($n->valor) ? (int) $n->valor : null))->filter(fn ($v) => $v !== null);
            if ($vals->isNotEmpty()) {
                $prom = (int) round($vals->avg());
                $r[$clave] = $escala === 'literal' ? $porLiteral[max(1, min(4, $prom))] : str_pad((string) $prom, 2, '0', STR_PAD_LEFT);
            }
        }

        return $r;
    }

    public function grilla(int $cargaId, int $periodoId, User $usuario): array
    {
        $c = $this->carga($cargaId, $usuario);
        $p = $this->periodoDe($c, $periodoId);
        $alumnos = $this->alumnos((int) $c->seccion_id);
        $competencias = $this->competencias((int) $c->area_curricular_id);
        $notas = DB::table('calificaciones')->where('periodo_id', $periodoId)->whereIn('competencia_id', $competencias->pluck('id'))->whereIn('alumno_id', $alumnos->pluck('id'))
            ->get(['alumno_id', 'competencia_id', 'valor', 'conclusion_descriptiva', 'origen'])->keyBy(fn ($n) => $n->alumno_id.'-'.$n->competencia_id);
        $cierre = DB::table('cierres_periodo')->where('seccion_id', $c->seccion_id)->where('periodo_id', $periodoId)->value('estado') ?? 'registro';
        $sugerencias = $this->sugerencias($cargaId, $periodoId, $c->escala);

        $celdas = [];
        foreach ($alumnos as $a) {
            foreach ($competencias as $k) {
                $n = $notas->get("{$a->id}-{$k->id}");
                $celdas["{$a->id}-{$k->id}"] = ['valor' => $n?->valor, 'conclusion' => $n?->conclusion_descriptiva, 'origen' => $n?->origen, 'sugerencia' => $sugerencias["{$a->id}-{$k->id}"] ?? null];
            }
        }

        return [
            'carga' => (array) $c, 'periodo' => (array) $p, 'escala' => $c->escala, 'valores' => $c->escala === 'literal' ? array_keys(self::LITERAL) : null,
            'alumnos' => $alumnos->map(fn ($a) => (array) $a)->all(), 'competencias' => $competencias->map(fn ($k) => (array) $k)->all(),
            'celdas' => $celdas, 'cerrado' => $cierre === 'cerrado' || $p->estado === 'cerrado', 'estado_cierre' => $cierre, 'resumen' => self::resumen($celdas),
        ];
    }

    /** @param array<string, array{valor: ?string, conclusion: ?string}> $celdas */
    public static function resumen(array $celdas): array
    {
        $total = count($celdas);
        $llenas = count(array_filter($celdas, fn ($c) => $c['valor'] !== null && $c['valor'] !== ''));
        $cSin = count(array_filter($celdas, fn ($c) => $c['valor'] === 'C' && ! trim((string) $c['conclusion'])));

        return ['total' => $total, 'llenas' => $llenas, 'vacias' => $total - $llenas, 'c_sin_conclusion' => $cSin, 'completo' => $total > 0 && $llenas === $total && $cSin === 0];
    }

    /** Guarda una celda (guardado automático). Valor vacío borra la nota. */
    public function guardar(int $cargaId, int $periodoId, int $alumnoId, int $competenciaId, ?string $valor, ?string $conclusion, User $usuario): array
    {
        $c = $this->carga($cargaId, $usuario);
        $this->periodoDe($c, $periodoId);
        $valor = $valor === null || trim($valor) === '' ? null : mb_strtoupper(trim($valor));
        if ($valor !== null && $c->escala === 'vigesimal' && preg_match('/^\d$/', $valor)) {
            $valor = '0'.$valor;
        }
        if (! self::valido($valor, $c->escala)) {
            throw ValidationException::withMessages(['valor' => [$c->escala === 'literal' ? 'Usa AD, A, B o C.' : 'Usa una nota de 00 a 20 con dos dígitos.']]);
        }
        if (! DB::table('matriculas')->where('alumno_id', $alumnoId)->where('seccion_id', $c->seccion_id)->where('estado', 'matriculado')->exists()
            || ! DB::table('competencias')->where('id', $competenciaId)->where('area_curricular_id', $c->area_curricular_id)->exists()) {
            throw ValidationException::withMessages(['alumno_id' => ['El alumno o la competencia no corresponden a este curso.']]);
        }
        $conclusion = trim((string) $conclusion) ?: null;
        try {
            if ($valor === null && $conclusion === null) {
                DB::table('calificaciones')->where(['alumno_id' => $alumnoId, 'competencia_id' => $competenciaId, 'periodo_id' => $periodoId])->delete();
            } else {
                DB::table('calificaciones')->upsert([[
                    'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'seccion_id' => $c->seccion_id, 'competencia_id' => $competenciaId,
                    'periodo_id' => $periodoId, 'valor' => $valor, 'conclusion_descriptiva' => $conclusion, 'registrado_por' => $usuario->id, 'origen' => 'manual',
                ]], ['alumno_id', 'competencia_id', 'periodo_id'], ['valor', 'conclusion_descriptiva', 'registrado_por', 'origen', 'seccion_id']);
            }
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'periodo está cerrado')) {
                throw ValidationException::withMessages(['periodo_id' => ['El periodo está cerrado: las notas no se pueden modificar.']]);
            }
            throw $e;
        }
        $observacion = $valor === 'C' && ! $conclusion ? 'Con C la conclusión descriptiva es obligatoria (SIAGIE).' : null;

        return ['valor' => $valor, 'conclusion' => $conclusion, 'observacion' => $observacion];
    }

    // ── EDUN-93 · Actividades de evaluación

    public function actividades(int $cargaId, int $periodoId, User $usuario): array
    {
        $c = $this->carga($cargaId, $usuario);
        $this->periodoDe($c, $periodoId);
        $notas = DB::table('notas_actividad as n')->join('actividades_evaluacion as a', 'a.id', '=', 'n.actividad_evaluacion_id')
            ->where('a.carga_docente_id', $cargaId)->where('a.periodo_id', $periodoId)->get(['n.actividad_evaluacion_id', 'n.alumno_id', 'n.valor', 'n.comentario'])->groupBy('actividad_evaluacion_id');

        return [
            'escala' => $c->escala, 'alumnos' => $this->alumnos((int) $c->seccion_id)->map(fn ($a) => (array) $a)->all(),
            'competencias' => $this->competencias((int) $c->area_curricular_id)->map(fn ($k) => (array) $k)->all(),
            'actividades' => DB::table('actividades_evaluacion')->where('carga_docente_id', $cargaId)->where('periodo_id', $periodoId)->orderBy('fecha')->orderBy('id')->get()
                ->map(fn ($a) => (array) $a + ['notas' => ($notas[$a->id] ?? collect())->mapWithKeys(fn ($n) => [$n->alumno_id => ['valor' => $n->valor, 'comentario' => $n->comentario]])->all()])->all(),
        ];
    }

    public function crearActividad(int $cargaId, int $periodoId, int $competenciaId, string $nombre, ?string $fecha, User $usuario): int
    {
        $c = $this->carga($cargaId, $usuario);
        $p = $this->periodoDe($c, $periodoId);
        if (! DB::table('competencias')->where('id', $competenciaId)->where('area_curricular_id', $c->area_curricular_id)->exists()) {
            throw ValidationException::withMessages(['competencia_id' => ['Elige una competencia del área.']]);
        }
        if ($fecha && ($fecha < (string) $p->fecha_inicio || $fecha > (string) $p->fecha_fin)) {
            throw ValidationException::withMessages(['fecha' => ['La fecha debe estar dentro del periodo.']]);
        }

        return (int) DB::table('actividades_evaluacion')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'carga_docente_id' => $cargaId, 'periodo_id' => $periodoId, 'competencia_id' => $competenciaId,
            'nombre' => trim($nombre), 'fecha' => $fecha,
        ]);
    }

    public function eliminarActividad(int $actividadId, User $usuario): void
    {
        $a = DB::table('actividades_evaluacion')->find($actividadId);
        abort_if(! $a, 404);
        $this->carga((int) $a->carga_docente_id, $usuario);
        DB::transaction(function () use ($actividadId) {
            DB::table('notas_actividad')->where('actividad_evaluacion_id', $actividadId)->delete();
            DB::table('actividades_evaluacion')->where('id', $actividadId)->delete();
        });
    }

    /** @param array<int|string, array{valor?: ?string, comentario?: ?string}> $notas  alumno_id => nota */
    public function guardarNotasActividad(int $actividadId, array $notas, User $usuario): int
    {
        $a = DB::table('actividades_evaluacion')->find($actividadId);
        abort_if(! $a, 404);
        $c = $this->carga((int) $a->carga_docente_id, $usuario);
        $alumnos = $this->alumnos((int) $c->seccion_id)->pluck('id')->flip();
        $guardadas = 0;
        foreach ($notas as $alumnoId => $n) {
            $valor = isset($n['valor']) && trim((string) $n['valor']) !== '' ? mb_strtoupper(trim((string) $n['valor'])) : null;
            if (! isset($alumnos[(int) $alumnoId])) {
                continue;
            }
            if (! self::valido($valor, $c->escala)) {
                throw ValidationException::withMessages(["notas.$alumnoId.valor" => [$c->escala === 'literal' ? 'Usa AD, A, B o C.' : 'Usa una nota de 00 a 20.']]);
            }
            if ($valor === null) {
                DB::table('notas_actividad')->where('actividad_evaluacion_id', $actividadId)->where('alumno_id', $alumnoId)->delete();

                continue;
            }
            DB::table('notas_actividad')->upsert([[
                'institucion_id' => ContextoInstitucion::requerido(), 'actividad_evaluacion_id' => $actividadId, 'alumno_id' => (int) $alumnoId,
                'valor' => $valor, 'comentario' => isset($n['comentario']) ? mb_substr(trim((string) $n['comentario']), 0, 500) ?: null : null,
            ]], ['actividad_evaluacion_id', 'alumno_id'], ['valor', 'comentario']);
            $guardadas++;
        }

        return $guardadas;
    }
}
