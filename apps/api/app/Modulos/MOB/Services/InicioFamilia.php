<?php

namespace App\Modulos\MOB\Services;

use App\Models\User;
use App\Modulos\ADM\Services\Horarios;
use App\Modulos\AUL\Services\Tareas;
use App\Modulos\CAL\Services\Asistencia;
use App\Modulos\COM\Services\Comunicados;
use App\Modulos\PAG\Services\Cobros;
use App\Modulos\PLA\Services\ModulosColegio;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * S36 · App de la familia: lo que necesita la pantalla de inicio en una sola llamada (hijo por hijo:
 * pensión, asistencia de hoy y del mes, notas recientes, libreta, tareas y su comunicado importante),
 * más la asistencia del mes, el horario y la foto de cada hijo. Solo de sus hijos (o de él mismo, si es alumno).
 * Cada bloque aparece solo si el módulo está encendido en el colegio.
 */
class InicioFamilia
{
    public function __construct(
        private ModulosColegio $modulos,
        private Comunicados $comunicados,
        private Tareas $tareas,
        private Horarios $horarios,
    ) {}

    public function resumen(User $usuario): array
    {
        $ids = FamiliaController::alumnosDe((int) $usuario->id);
        $hoy = now()->toDateString();
        $activos = [
            'PAG' => $this->modulos->activo('PAG'),
            'CAL' => $this->modulos->activo('CAL'),
            'COM' => $this->modulos->activo('COM'),
            'AUL' => $this->modulos->activo('AUL'),
            'ADM' => $this->modulos->activo('ADM'),
        ];
        $tareas = $activos['AUL'] ? collect($this->tareas->deHijos($usuario)) : collect();
        $comunicados = $activos['COM'] ? collect($this->comunicados->deFamilia((int) $usuario->id)) : collect();

        $hijos = collect($this->alumnos($ids))->map(function (array $a) use ($hoy, $activos, $tareas, $comunicados) {
            $id = $a['id'];

            return $a + [
                'pension' => $activos['PAG'] ? $this->pension($id, $hoy) : null,
                'asistencia' => $activos['CAL'] ? $this->asistenciaHoy($id, $hoy) : null,
                'notas_recientes' => $activos['CAL'] ? $this->notasRecientes($id) : [],
                'libreta' => $activos['CAL'] ? $this->ultimaLibreta($id) : null,
                'tareas_pendientes' => $tareas->where('alumno_id', $id)->where('estado', 'pendiente')->count(),
                'comunicado' => self::importante($comunicados->where('alumno_id', $id)),
                'comunicados_sin_leer' => $comunicados->where('alumno_id', $id)->whereNull('leido_at')->count(),
            ];
        })->values()->all();

        return ['hijos' => $hijos, 'comunicados_sin_leer' => $comunicados->whereNull('leido_at')->count(), 'modulos' => array_keys(array_filter($activos))];
    }

    /**
     * El comunicado que va en el inicio de ese hijo: el primero que pide confirmación y falta confirmar;
     * si no, el primero sin leer. Un comunicado llega una vez por hijo (cada uno se confirma aparte).
     */
    private static function importante($lista): ?array
    {
        $c = $lista->first(fn ($c) => $c['requiere_confirmacion'] && ! $c['confirmado_at']) ?? $lista->firstWhere('leido_at', null);

        return $c ? ['id' => (int) $c['id'], 'titulo' => $c['titulo'], 'contenido' => mb_substr($c['contenido'], 0, 400),
            'enviado_at' => $c['enviado_at'], 'requiere_confirmacion' => (bool) $c['requiere_confirmacion'],
            'confirmado_at' => $c['confirmado_at'], 'leido_at' => $c['leido_at']] : null;
    }

    /** Asistencia del mes (registro de puerta o del día), con el resumen y el porcentaje. */
    public function asistenciaMes(int $alumnoId, string $mes): array
    {
        $desde = Carbon::createFromFormat('Y-m-d', "{$mes}-01")->startOfDay();
        $hasta = $desde->copy()->endOfMonth();
        $dias = DB::table('asistencias')->where('alumno_id', $alumnoId)->whereNull('horario_sesion_id')
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->orderBy('fecha')
            ->get(['fecha', 'estado', 'hora_registro'])
            ->map(fn ($d) => ['fecha' => (string) $d->fecha, 'estado' => $d->estado, 'hora' => $d->hora_registro ? substr((string) $d->hora_registro, 0, 5) : null])
            ->unique('fecha')->values()->all();
        $resumen = Asistencia::resumen($alumnoId, $desde->toDateString(), $hasta->toDateString());

        return ['mes' => $mes, 'dias' => $dias, 'resumen' => $resumen, 'porcentaje' => self::porcentaje($resumen)];
    }

    /** Horario de la sección en la que está matriculado (sin datos de gestión). */
    public function horario(int $alumnoId): ?array
    {
        $m = DB::table('matriculas as m')->join('anios_escolares as y', 'y.id', '=', 'm.anio_escolar_id')
            ->where('m.alumno_id', $alumnoId)->where('m.estado', 'matriculado')->whereNotNull('m.seccion_id')
            ->orderByDesc('y.anio')->first(['m.seccion_id', 'm.anio_escolar_id']);
        if (! $m) {
            return null;
        }
        $h = $this->horarios->ver((int) $m->anio_escolar_id, (int) $m->seccion_id, null);

        return [
            'titulo' => $h['titulo'],
            'dias' => $h['dias'],
            'bloques' => array_map(fn ($b) => ['id' => (int) $b['id'], 'orden' => (int) $b['orden'], 'hora_inicio' => substr((string) $b['hora_inicio'], 0, 5),
                'hora_fin' => substr((string) $b['hora_fin'], 0, 5), 'es_recreo' => (bool) $b['es_recreo']], $h['bloques']),
            'celdas' => array_map(fn ($c) => ['dia_semana' => (int) $c['dia_semana'], 'bloque_horario_id' => (int) $c['bloque_horario_id'],
                'area' => $c['area'], 'docente' => $c['docente'], 'ambiente' => $c['ambiente']], $h['celdas']),
        ];
    }

    /**
     * Notas de actividades del periodo por curso (insumos que el docente registra antes de la libreta).
     * Sin periodo: el que está en curso; si no hay, el último con notas (o el último que empezó).
     */
    public function notasPeriodo(int $alumnoId, ?int $periodoId): array
    {
        $anio = DB::table('matriculas as m')->join('anios_escolares as y', 'y.id', '=', 'm.anio_escolar_id')
            ->where('m.alumno_id', $alumnoId)->where('m.estado', 'matriculado')->orderByDesc('y.anio')->value('m.anio_escolar_id');
        if (! $anio) {
            return ['periodos' => [], 'periodo' => null, 'cursos' => []];
        }
        $periodos = DB::table('periodos')->where('anio_escolar_id', $anio)->orderBy('numero')->get(['id', 'nombre', 'fecha_inicio', 'fecha_fin']);
        $hoy = now()->toDateString();
        $conNotas = DB::table('notas_actividad as n')->join('actividades_evaluacion as e', 'e.id', '=', 'n.actividad_evaluacion_id')
            ->where('n.alumno_id', $alumnoId)->whereIn('e.periodo_id', $periodos->pluck('id'))->distinct()->pluck('e.periodo_id')->map(fn ($i) => (int) $i)->all();
        $periodo = $periodoId
            ? $periodos->firstWhere('id', $periodoId)
            : ($periodos->first(fn ($p) => $p->fecha_inicio <= $hoy && $p->fecha_fin >= $hoy)
                ?? $periodos->filter(fn ($p) => in_array((int) $p->id, $conNotas, true))->last()
                ?? $periodos->filter(fn ($p) => $p->fecha_inicio <= $hoy)->last()
                ?? $periodos->first());
        abort_if($periodoId && ! $periodo, 404);

        $cursos = $periodo ? DB::table('notas_actividad as n')->join('actividades_evaluacion as e', 'e.id', '=', 'n.actividad_evaluacion_id')
            ->join('cargas_docentes as c', 'c.id', '=', 'e.carga_docente_id')->join('areas_curriculares as ar', 'ar.id', '=', 'c.area_curricular_id')
            ->join('personal as p', 'p.id', '=', 'c.personal_id')
            ->where('n.alumno_id', $alumnoId)->where('e.periodo_id', $periodo->id)
            ->orderBy('ar.orden')->orderByRaw('coalesce(e.fecha, n.created_at::date)')
            ->get(['ar.nombre as area', DB::raw("p.nombres || ' ' || p.apellidos as docente"), 'e.nombre as actividad', 'n.valor', 'n.comentario', 'e.fecha'])
            ->groupBy('area')
            ->map(fn ($g, $area) => ['area' => $area, 'docente' => $g->first()->docente,
                'notas' => $g->map(fn ($n) => ['actividad' => $n->actividad, 'valor' => $n->valor, 'comentario' => $n->comentario, 'fecha' => $n->fecha ? (string) $n->fecha : null])->values()->all()])
            ->values()->all() : [];

        return [
            'periodos' => $periodos->map(fn ($p) => ['id' => (int) $p->id, 'nombre' => $p->nombre])->all(),
            'periodo' => $periodo ? ['id' => (int) $periodo->id, 'nombre' => $periodo->nombre] : null,
            'cursos' => $cursos,
        ];
    }

    /** Foto del alumno (contenido y tipo) o null si no tiene. La ve su propia familia. */
    public function foto(int $alumnoId): ?array
    {
        $archivo = DB::table('alumnos as a')->join('archivos as f', 'f.id', '=', 'a.foto_archivo_id')
            ->where('a.id', $alumnoId)->first(['f.disco', 'f.ruta', 'f.mime']);
        if (! $archivo || ! str_starts_with((string) $archivo->mime, 'image/')) {
            return null;
        }
        $contenido = Storage::disk($archivo->disco)->get($archivo->ruta);

        return $contenido === null ? null : ['contenido' => $contenido, 'mime' => $archivo->mime];
    }

    public static function porcentaje(array $r): ?int
    {
        $total = $r['P'] + $r['T'] + $r['F'] + $r['J'];

        return $total ? (int) round(($r['P'] + $r['T'] + $r['J']) * 100 / $total) : null;
    }

    /** Datos del hijo con su matrícula vigente (la matriculada más reciente; si no, la reservada). */
    private function alumnos(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return DB::table('alumnos as a')
            ->leftJoin('matriculas as m', fn ($j) => $j->on('m.alumno_id', '=', 'a.id')->whereIn('m.estado', ['matriculado', 'reservado']))
            ->leftJoin('anios_escolares as y', 'y.id', '=', 'm.anio_escolar_id')
            ->leftJoin('secciones as s', 's.id', '=', 'm.seccion_id')->leftJoin('grados as g', 'g.id', '=', 's.grado_id')
            ->whereIn('a.id', $ids)->whereNull('a.eliminado_at')
            ->orderBy('a.nombres')->orderByRaw("m.estado = 'matriculado' desc")->orderByDesc('y.anio')
            ->get(['a.id', 'a.nombres', 'a.apellido_paterno', 'a.apellido_materno', 'a.foto_archivo_id', 'm.estado as matricula', 'y.anio',
                DB::raw("g.nombre || ' ' || s.nombre as seccion")])
            ->unique('id')->values()
            ->map(fn ($a) => [
                'id' => (int) $a->id, 'nombres' => $a->nombres,
                'apellidos' => trim($a->apellido_paterno.' '.($a->apellido_materno ?? '')),
                'seccion' => $a->seccion, 'anio' => $a->anio ? (int) $a->anio : null, 'matricula' => $a->matricula,
                'tiene_foto' => (bool) $a->foto_archivo_id,
            ])->all();
    }

    private function pension(int $alumnoId, string $hoy): ?array
    {
        $pendientes = DB::table('cargos as c')->where('c.alumno_id', $alumnoId)->whereIn('c.estado', ['pendiente', 'parcial'])
            ->where('c.emision', '<=', $hoy)->orderBy('c.vencimiento')
            ->get(['c.id', 'c.descripcion', 'c.vencimiento', DB::raw(Cobros::saldoSql().' as saldo')]);
        $proximo = $pendientes->first();

        return [
            'deuda_vencida' => round((float) $pendientes->where('vencimiento', '<', $hoy)->sum('saldo'), 2),
            'proximo' => $proximo ? ['id' => (int) $proximo->id, 'descripcion' => $proximo->descripcion, 'vencimiento' => (string) $proximo->vencimiento,
                'saldo' => (float) $proximo->saldo, 'vencido' => $proximo->vencimiento < $hoy] : null,
        ];
    }

    private function asistenciaHoy(int $alumnoId, string $hoy): array
    {
        $registro = DB::table('asistencias')->where('alumno_id', $alumnoId)->where('fecha', $hoy)->whereNull('horario_sesion_id')
            ->orderBy('id')->first(['estado', 'hora_registro', 'origen']);
        $mes = Asistencia::resumen($alumnoId, now()->startOfMonth()->toDateString(), $hoy);

        return [
            'hoy' => $registro ? ['estado' => $registro->estado, 'hora' => $registro->hora_registro ? substr((string) $registro->hora_registro, 0, 5) : null, 'origen' => $registro->origen] : null,
            'porcentaje_mes' => self::porcentaje($mes),
        ];
    }

    /** Últimas notas de actividades (insumos de evaluación) con su curso y docente. */
    private function notasRecientes(int $alumnoId): array
    {
        return DB::table('notas_actividad as n')->join('actividades_evaluacion as e', 'e.id', '=', 'n.actividad_evaluacion_id')
            ->join('cargas_docentes as c', 'c.id', '=', 'e.carga_docente_id')->join('areas_curriculares as ar', 'ar.id', '=', 'c.area_curricular_id')
            ->join('personal as p', 'p.id', '=', 'c.personal_id')
            ->where('n.alumno_id', $alumnoId)->orderByRaw('coalesce(e.fecha, n.created_at::date) desc')->orderByDesc('n.id')->limit(3)
            ->get(['n.id', 'ar.nombre as area', 'e.nombre as actividad', 'n.valor', 'e.fecha', DB::raw("p.nombres || ' ' || p.apellidos as docente")])
            ->map(fn ($n) => ['id' => (int) $n->id, 'area' => $n->area, 'actividad' => $n->actividad, 'valor' => $n->valor,
                'fecha' => $n->fecha ? (string) $n->fecha : null, 'docente' => $n->docente])->all();
    }

    private function ultimaLibreta(int $alumnoId): ?array
    {
        $l = DB::table('libretas as l')->join('matriculas as m', 'm.id', '=', 'l.matricula_id')->join('periodos as p', 'p.id', '=', 'l.periodo_id')
            ->where('m.alumno_id', $alumnoId)->whereNotNull('l.publicada_at')->orderByDesc('l.publicada_at')->first(['l.id', 'p.nombre as periodo']);

        return $l ? ['id' => (int) $l->id, 'periodo' => $l->periodo] : null;
    }
}
