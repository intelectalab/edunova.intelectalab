<?php

namespace App\Modulos\CAL\Services;

use App\Models\User;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SEG\Services\Autorizacion;
use App\Modulos\SEG\Services\NotificacionesPush;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CAL · Conducta, tutoría y alertas de riesgo.
 *
 * - EDUN-101 · CAL-H11 Incidencias y méritos con detalle; aviso al padre opcional.
 * - EDUN-102 · CAL-H12 Entrevistas con participantes y acuerdos; las confidenciales solo las ven
 *   quien las registró, psicología y dirección.
 * - EDUN-103 · CAL-H13 Alertas automáticas: C en 2 o más competencias del periodo, más de 3
 *   faltas en el mes y 3 o más entregas vencidas sin presentar; seguimiento con acción.
 */
class Tutoria
{
    public const GRAVEDAD = ['leve' => 'Leve', 'moderada' => 'Moderada', 'grave' => 'Grave'];

    public const MOTIVOS = ['notas_c' => 'C en 2 o más competencias', 'faltas_mes' => 'Más de 3 faltas en el mes', 'entregas' => 'Entregas pendientes en el aula'];

    public function __construct(private Autorizacion $autorizacion, private Auditoria $auditoria, private NotificacionesPush $push) {}

    private function veConfidencial(User $usuario): bool
    {
        return $this->autorizacion->tieneRol($usuario, 'psicologia', 'director', 'promotor');
    }

    /** Historial de un alumno: conducta y entrevistas (las confidenciales según quién mira). */
    public function historial(int $alumnoId, User $usuario): array
    {
        abort_if(! DB::table('alumnos')->where('id', $alumnoId)->exists(), 404);
        $ve = $this->veConfidencial($usuario);

        return [
            'conducta' => DB::table('incidencias_conducta as i')->leftJoin('users as u', 'u.id', '=', 'i.registrado_por')->where('i.alumno_id', $alumnoId)->orderByDesc('i.fecha')->orderByDesc('i.id')
                ->get(['i.id', 'i.fecha', 'i.tipo', 'i.gravedad', 'i.detalle', 'i.notificado_padre_at', DB::raw("u.nombres || ' ' || u.apellidos as registrado_por")])->map(fn ($i) => (array) $i)->all(),
            'entrevistas' => DB::table('entrevistas_tutoria as e')->leftJoin('users as u', 'u.id', '=', 'e.registrado_por')->where('e.alumno_id', $alumnoId)
                ->when(! $ve, fn ($q) => $q->where(fn ($w) => $w->where('e.confidencial', false)->orWhere('e.registrado_por', $usuario->id)))
                ->orderByDesc('e.fecha')->get(['e.id', 'e.fecha', 'e.participantes', 'e.acuerdos', 'e.confidencial', DB::raw("u.nombres || ' ' || u.apellidos as registrado_por")])->map(fn ($e) => (array) $e)->all(),
            'alertas' => DB::table('alertas_riesgo')->where('alumno_id', $alumnoId)->orderByDesc('id')->limit(20)->get()->map(fn ($a) => (array) $a)->all(),
        ];
    }

    public function registrarConducta(int $alumnoId, string $tipo, ?string $gravedad, string $detalle, string $fecha, bool $avisar, int $usuarioId): int
    {
        abort_if(! DB::table('alumnos')->whereNull('eliminado_at')->where('id', $alumnoId)->exists(), 404);
        if ($tipo === 'incidencia' && ! isset(self::GRAVEDAD[$gravedad ?? ''])) {
            throw ValidationException::withMessages(['gravedad' => ['Indica la gravedad de la incidencia.']]);
        }
        $id = (int) DB::table('incidencias_conducta')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'fecha' => $fecha, 'tipo' => $tipo,
            'gravedad' => $tipo === 'incidencia' ? $gravedad : null, 'detalle' => trim($detalle), 'registrado_por' => $usuarioId,
        ]);
        if ($avisar) {
            $padres = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $alumnoId)->whereNotNull('p.user_id')->pluck('p.user_id')->map(fn ($u) => (int) $u)->all();
            if ($padres) {
                $nombre = DB::table('alumnos')->where('id', $alumnoId)->value('nombres');
                $this->push->enviar($padres, 'comunicados', $tipo === 'merito' ? "Un reconocimiento para {$nombre}" : "Aviso de conducta de {$nombre}", mb_strimwidth(trim($detalle), 0, 160, '…'), ['pantalla' => 'inicio', 'id' => $alumnoId]);
            }
            DB::table('incidencias_conducta')->where('id', $id)->update(['notificado_padre_at' => now()]);
        }
        $this->auditoria->registrar($tipo === 'merito' ? 'merito_registrado' : 'incidencia_registrada', 'incidencias_conducta', $id, null, ['alumno_id' => $alumnoId, 'aviso_padre' => $avisar]);

        return $id;
    }

    public function registrarEntrevista(int $alumnoId, string $fecha, string $participantes, string $acuerdos, bool $confidencial, int $usuarioId): int
    {
        abort_if(! DB::table('alumnos')->whereNull('eliminado_at')->where('id', $alumnoId)->exists(), 404);

        return (int) DB::table('entrevistas_tutoria')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'fecha' => $fecha, 'participantes' => trim($participantes),
            'acuerdos' => trim($acuerdos), 'confidencial' => $confidencial, 'registrado_por' => $usuarioId,
        ]);
    }

    /** Evalúa las reglas y abre una alerta por alumno y motivo si no hay una abierta. */
    public function evaluarAlertas(?int $periodoId = null): int
    {
        $inst = ContextoInstitucion::requerido();
        $creadas = 0;
        $crear = function (int $alumno, string $motivo, string $detalle, ?int $periodo, string $nivel) use ($inst, &$creadas) {
            $existe = DB::table('alertas_riesgo')->where('alumno_id', $alumno)->where('motivo', $motivo)->where('estado', '<>', 'cerrada')
                ->where(fn ($q) => $periodo ? $q->where('periodo_id', $periodo) : $q->whereNull('periodo_id'))->exists();
            if (! $existe) {
                DB::table('alertas_riesgo')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'periodo_id' => $periodo, 'motivo' => $motivo, 'detalle' => $detalle, 'nivel' => $nivel]);
                $creadas++;
            }
        };
        $periodos = $periodoId ? [$periodoId] : DB::table('periodos')->where('estado', '<>', 'programado')->pluck('id')->all();
        foreach (DB::table('calificaciones')->whereIn('periodo_id', $periodos)->where('valor', 'C')->selectRaw('alumno_id, periodo_id, count(*) as n')->groupBy('alumno_id', 'periodo_id')->havingRaw('count(*) >= 2')->get() as $f) {
            $crear((int) $f->alumno_id, 'notas_c', "Tiene C en {$f->n} competencias del periodo.", (int) $f->periodo_id, $f->n >= 4 ? 'alta' : 'media');
        }
        $mes = now()->startOfMonth()->toDateString();
        foreach (DB::table('asistencias')->where('fecha', '>=', $mes)->where('estado', 'F')->whereNull('horario_sesion_id')->selectRaw('alumno_id, count(*) as n')->groupBy('alumno_id')->havingRaw('count(*) > 3')->get() as $f) {
            $crear((int) $f->alumno_id, 'faltas_mes', "{$f->n} faltas sin justificar en ".now()->locale('es')->translatedFormat('F').'.', null, $f->n >= 6 ? 'alta' : 'media');
        }
        $pendientes = DB::table('aula_tareas as t')->join('aula_unidades as u', 'u.id', '=', 't.aula_unidad_id')->join('aula_cursos as c', 'c.id', '=', 'u.aula_curso_id')
            ->join('cargas_docentes as cd', 'cd.id', '=', 'c.carga_docente_id')->join('matriculas as m', fn ($j) => $j->on('m.seccion_id', '=', 'cd.seccion_id')->where('m.estado', '=', 'matriculado'))
            ->whereNotNull('t.publicada_at')->where('t.vence_at', '<', now())
            ->whereNotExists(fn ($q) => $q->from('aula_entregas as e')->whereColumn('e.aula_tarea_id', 't.id')->whereColumn('e.alumno_id', 'm.alumno_id'))
            ->selectRaw('m.alumno_id, count(*) as n')->groupBy('m.alumno_id')->havingRaw('count(*) >= 3')->get();
        foreach ($pendientes as $f) {
            $crear((int) $f->alumno_id, 'entregas', "{$f->n} tareas vencidas sin entregar en el aula virtual.", null, 'media');
        }

        return $creadas;
    }

    public function alertas(User $usuario, ?string $estado): array
    {
        $propias = ! $this->autorizacion->tieneRol($usuario, 'coordinador', 'director', 'promotor', 'psicologia');
        $secciones = DB::table('secciones')->whereIn('tutor_personal_id', DB::table('personal')->where('user_id', $usuario->id)->select('id'))->pluck('id');

        return DB::table('alertas_riesgo as a')->join('alumnos as al', 'al.id', '=', 'a.alumno_id')
            ->leftJoin('matriculas as m', fn ($j) => $j->on('m.alumno_id', '=', 'a.alumno_id')->where('m.estado', '=', 'matriculado'))
            ->leftJoin('secciones as s', 's.id', '=', 'm.seccion_id')->leftJoin('grados as g', 'g.id', '=', 's.grado_id')
            ->when($propias, fn ($q) => $q->whereIn('m.seccion_id', $secciones))
            ->when($estado, fn ($q) => $estado === 'activas' ? $q->where('a.estado', '<>', 'cerrada') : $q->where('a.estado', $estado))
            ->orderByRaw("a.estado = 'cerrada'")->orderByRaw("a.nivel = 'alta' desc")->orderByDesc('a.id')->limit(300)
            ->get(['a.*', DB::raw("al.apellido_paterno || ' ' || coalesce(al.apellido_materno, '') || ', ' || al.nombres as alumno"), DB::raw("g.nombre || ' ' || s.nombre as seccion")])
            ->unique('id')->values()->map(fn ($a) => (array) $a + ['nombre_motivo' => self::MOTIVOS[$a->motivo] ?? $a->motivo])->all();
    }

    public function seguir(int $alertaId, string $estado, string $accion, int $usuarioId): void
    {
        $a = DB::table('alertas_riesgo')->find($alertaId);
        abort_if(! $a, 404);
        DB::table('alertas_riesgo')->where('id', $alertaId)->update([
            'estado' => $estado, 'accion' => trim(($a->accion ? $a->accion."\n" : '').now()->format('d/m/Y').': '.$accion), 'atendida_por' => $usuarioId,
            'cerrada_at' => $estado === 'cerrada' ? now() : null,
        ]);
        $this->auditoria->registrar('alerta_seguimiento', 'alertas_riesgo', $alertaId, ['estado' => $a->estado], ['estado' => $estado, 'accion' => $accion]);
    }
}
