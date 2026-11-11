<?php

namespace App\Modulos\CAL\Services;

use App\Models\User;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SEG\Services\Autorizacion;
use App\Modulos\SEG\Services\NotificacionesPush;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-96 · CAL-H06 Justificar inasistencia.
 *
 * La familia la pide desde la app (o la web) con el motivo y la foto del sustento, para uno o
 * varios días (hasta 31, de los últimos 30 días o de los próximos 7). El tutor de la sección
 * —o coordinación y dirección— la acepta o la rechaza: al aceptarla, las faltas (F) de esos días
 * pasan a justificadas (J) y la familia recibe el aviso. Los días que todavía no tienen
 * asistencia se justifican solos cuando se registre la falta.
 */
class Justificaciones
{
    public const DIAS_ATRAS = 30;

    public const DIAS_ADELANTE = 7;

    public function __construct(private AlmacenArchivos $almacen, private Autorizacion $autorizacion, private Auditoria $auditoria, private NotificacionesPush $push) {}

    public function solicitar(int $alumnoId, string $desde, string $hasta, string $motivo, ?UploadedFile $sustento, User $usuario): array
    {
        $hoy = now()->startOfDay();
        $d = Carbon::parse($desde);
        $h = Carbon::parse($hasta);
        if ($d->lessThan($hoy->copy()->subDays(self::DIAS_ATRAS)) || $h->greaterThan($hoy->copy()->addDays(self::DIAS_ADELANTE))) {
            throw ValidationException::withMessages(['fecha_desde' => ['Se justifican días de los últimos '.self::DIAS_ATRAS.' días o de los próximos '.self::DIAS_ADELANTE.'.']]);
        }
        if (DB::table('justificaciones_inasistencia')->where('alumno_id', $alumnoId)->where('estado', 'pendiente')
            ->where('fecha_desde', '<=', $hasta)->where('fecha_hasta', '>=', $desde)->exists()) {
            throw ValidationException::withMessages(['fecha_desde' => ['Ya hay una justificación pendiente para esos días.']]);
        }
        $archivo = $sustento ? $this->almacen->guardar($sustento, $usuario->id, true) : null;
        $id = (int) DB::table('justificaciones_inasistencia')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'fecha_desde' => $desde, 'fecha_hasta' => $hasta,
            'motivo' => trim($motivo), 'sustento_archivo_id' => $archivo?->id, 'solicitado_por' => $usuario->id,
        ]);
        $this->auditoria->registrar('justificacion_solicitada', 'justificaciones_inasistencia', $id, null, ['alumno_id' => $alumnoId, 'desde' => $desde, 'hasta' => $hasta]);
        $this->avisarTutor($alumnoId, $id);

        return $this->detalle($id);
    }

    /** Las de los hijos del usuario (vista de la familia). */
    public function deFamilia(array $hijos): array
    {
        return $this->consulta()->whereIn('j.alumno_id', $hijos ?: [0])->orderByDesc('j.id')->limit(50)->get()->map(fn ($j) => $this->fila($j))->all();
    }

    /** Bandeja del tutor: sus secciones; coordinación y dirección ven todas. */
    public function bandeja(User $usuario, ?string $estado): array
    {
        return $this->consulta()
            ->when($estado, fn ($q, $v) => $q->where('j.estado', $v))
            ->when(! $this->veTodas($usuario), fn ($q) => $q->whereIn('m.seccion_id', $this->seccionesTutor($usuario)))
            ->orderByRaw("j.estado = 'pendiente' desc")->orderByDesc('j.id')->limit(200)->get()->map(fn ($j) => $this->fila($j))->all();
    }

    public function resolver(int $id, bool $aceptar, ?string $respuesta, User $usuario): array
    {
        $j = DB::table('justificaciones_inasistencia')->where('id', $id)->lockForUpdate()->first();
        abort_if(! $j, 404);
        $j->seccion_id = DB::table('matriculas')->where('alumno_id', $j->alumno_id)->where('estado', 'matriculado')->orderByDesc('anio_escolar_id')->value('seccion_id');
        abort_unless($this->veTodas($usuario) || in_array((int) $j->seccion_id, $this->seccionesTutor($usuario), true), 403, 'Solo el tutor de la sección, coordinación o dirección resuelven esta justificación.');
        if ($j->estado !== 'pendiente') {
            throw ValidationException::withMessages(['justificacion' => ['Esa justificación ya fue resuelta.']]);
        }
        if (! $aceptar && trim((string) $respuesta) === '') {
            throw ValidationException::withMessages(['respuesta' => ['Explica a la familia por qué se rechaza.']]);
        }
        $dias = $aceptar ? DB::table('asistencias')->where('alumno_id', $j->alumno_id)->whereBetween('fecha', [$j->fecha_desde, $j->fecha_hasta])
            ->where('estado', 'F')->update(['estado' => 'J', 'registrado_por' => $usuario->id]) : 0;
        DB::table('justificaciones_inasistencia')->where('id', $id)->update([
            'estado' => $aceptar ? 'aceptada' : 'rechazada', 'respuesta' => $respuesta !== null ? trim($respuesta) : null,
            'resuelto_por' => $usuario->id, 'resuelto_at' => now(), 'dias_justificados' => $dias,
        ]);
        $this->auditoria->registrar($aceptar ? 'justificacion_aceptada' : 'justificacion_rechazada', 'justificaciones_inasistencia', $id, ['estado' => 'pendiente'], ['estado' => $aceptar ? 'aceptada' : 'rechazada', 'dias' => $dias]);
        $nombre = DB::table('alumnos')->where('id', $j->alumno_id)->value('nombres');
        $this->push->enviar([(int) $j->solicitado_por], 'asistencia', $aceptar ? "Justificación aceptada: {$nombre}" : "Justificación rechazada: {$nombre}",
            $aceptar ? 'Las faltas de esos días quedaron justificadas.' : trim((string) $respuesta), ['pantalla' => 'inicio', 'id' => (int) $j->alumno_id]);

        return $this->detalle($id);
    }

    /** Al registrar una falta, si hay una justificación aceptada que cubre ese día, entra como J. */
    public static function cubre(int $alumnoId, string $fecha): bool
    {
        return DB::table('justificaciones_inasistencia')->where('alumno_id', $alumnoId)->where('estado', 'aceptada')
            ->where('fecha_desde', '<=', $fecha)->where('fecha_hasta', '>=', $fecha)->exists();
    }

    public function urlSustento(int $id, User $usuario, array $hijos): string
    {
        $j = DB::table('justificaciones_inasistencia as j')
            ->leftJoin('matriculas as m', fn ($x) => $x->on('m.alumno_id', '=', 'j.alumno_id')->where('m.estado', '=', 'matriculado'))
            ->where('j.id', $id)->first(['j.alumno_id', 'j.sustento_archivo_id', 'm.seccion_id']);
        abort_if(! $j || ! $j->sustento_archivo_id, 404);
        $permitido = in_array((int) $j->alumno_id, $hijos, true)
            || ($this->autorizacion->tiene($usuario, 'cal.asistencia.justificar') && ($this->veTodas($usuario) || in_array((int) $j->seccion_id, $this->seccionesTutor($usuario), true)));
        abort_unless($permitido, 404);

        return $this->almacen->urlTemporal(Archivo::query()->findOrFail($j->sustento_archivo_id));
    }

    private function detalle(int $id): array
    {
        return $this->fila($this->consulta()->where('j.id', $id)->first());
    }

    private function consulta()
    {
        return DB::table('justificaciones_inasistencia as j')->join('alumnos as a', 'a.id', '=', 'j.alumno_id')
            ->leftJoin('matriculas as m', fn ($x) => $x->on('m.alumno_id', '=', 'j.alumno_id')->where('m.estado', '=', 'matriculado'))
            ->leftJoin('secciones as s', 's.id', '=', 'm.seccion_id')->leftJoin('grados as g', 'g.id', '=', 's.grado_id')
            ->select(['j.id', 'j.alumno_id', 'j.fecha_desde', 'j.fecha_hasta', 'j.motivo', 'j.estado', 'j.respuesta', 'j.created_at', 'j.resuelto_at', 'j.dias_justificados',
                DB::raw('j.sustento_archivo_id is not null as tiene_sustento'), DB::raw("a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ', ' || a.nombres as alumno"),
                DB::raw("g.nombre || ' ' || s.nombre as seccion"),
                DB::raw("(select count(*) from asistencias x where x.alumno_id = j.alumno_id and x.fecha between j.fecha_desde and j.fecha_hasta and x.estado = 'F') as faltas")]);
    }

    private function fila(object $j): array
    {
        return array_merge((array) $j, ['tiene_sustento' => (bool) $j->tiene_sustento, 'faltas' => (int) $j->faltas, 'alumno' => preg_replace('/\s+/', ' ', (string) $j->alumno)]);
    }

    private function veTodas(User $usuario): bool
    {
        return $this->autorizacion->tieneRol($usuario, 'coordinador', 'director', 'promotor', 'auxiliar');
    }

    /** @return list<int> */
    private function seccionesTutor(User $usuario): array
    {
        return DB::table('secciones')->whereIn('tutor_personal_id', DB::table('personal')->where('user_id', $usuario->id)->select('id'))->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function avisarTutor(int $alumnoId, int $id): void
    {
        $tutor = DB::table('matriculas as m')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('personal as p', 'p.id', '=', 's.tutor_personal_id')
            ->where('m.alumno_id', $alumnoId)->where('m.estado', 'matriculado')->whereNotNull('p.user_id')->value('p.user_id');
        if ($tutor) {
            $nombre = DB::table('alumnos')->where('id', $alumnoId)->value('nombres');
            $this->push->enviar([(int) $tutor], 'asistencia', 'Nueva justificación por revisar', "La familia de {$nombre} envió una justificación de inasistencia.", ['pantalla' => 'inicio', 'id' => $id]);
        }
    }
}
