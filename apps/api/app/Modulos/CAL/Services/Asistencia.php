<?php

namespace App\Modulos\CAL\Services;

use App\Models\User;
use App\Modulos\ADM\Services\Carnes;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Autorizacion;
use App\Modulos\SEG\Services\NotificacionesPush;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CAL · Asistencia diaria.
 *
 * - EDUN-94 · CAL-H04 Desde el celular: todos presentes por defecto, T o F en un toque y lotes
 *   registrados sin conexión que se sincronizan luego (gana el registro más reciente).
 * - EDUN-95 · CAL-H05 Por QR del carné en la puerta: hora de llegada y tardanza automática
 *   según la hora de entrada del colegio y su tolerancia.
 * - Regla 6 · Tardanza o falta avisa al padre el mismo día (una vez por día).
 */
class Asistencia
{
    public const ESTADOS = ['P' => 'Presente', 'T' => 'Tardanza', 'F' => 'Falta', 'J' => 'Justificada'];

    public function __construct(private Autorizacion $autorizacion, private NotificacionesPush $push) {}

    /** Secciones que el usuario puede tomar: tutor o docente de la sección; auxiliar y dirección, todas. */
    public function secciones(User $usuario, int $anioId): array
    {
        $todas = $this->autorizacion->tieneRol($usuario, 'auxiliar', 'coordinador', 'director', 'promotor');

        return DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->where('s.anio_escolar_id', $anioId)
            ->when(! $todas, fn ($q) => $q->where(fn ($w) => $w->whereIn('s.tutor_personal_id', DB::table('personal')->where('user_id', $usuario->id)->select('id'))
                ->orWhereIn('s.id', DB::table('cargas_docentes as c')->join('personal as p', 'p.id', '=', 'c.personal_id')->where('p.user_id', $usuario->id)->select('c.seccion_id'))))
            ->orderBy('g.nivel')->orderBy('g.numero')->orderBy('s.nombre')
            ->get(['s.id', DB::raw("g.nombre || ' ' || s.nombre as nombre")])->map(fn ($s) => (array) $s)->all();
    }

    /** Lista del día: los que no tienen registro aparecen como presentes (sin guardar aún). */
    public function lista(int $seccionId, string $fecha): array
    {
        $alumnos = DB::table('matriculas as m')->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->where('m.seccion_id', $seccionId)->where('m.estado', 'matriculado')
            ->whereNull('a.eliminado_at')->orderBy('a.apellido_paterno')->orderBy('a.nombres')
            ->get(['a.id', DB::raw("a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ', ' || a.nombres as nombre")]);
        $hechos = DB::table('asistencias')->where('seccion_id', $seccionId)->where('fecha', $fecha)->whereNull('horario_sesion_id')->get()->keyBy('alumno_id');

        return [
            'fecha' => $fecha, 'tomada' => $hechos->isNotEmpty(),
            'alumnos' => $alumnos->map(fn ($a) => ['id' => $a->id, 'nombre' => $a->nombre, 'estado' => $hechos[$a->id]->estado ?? 'P',
                'hora' => $hechos[$a->id]->hora_registro ?? null, 'origen' => $hechos[$a->id]->origen ?? null])->all(),
        ];
    }

    /**
     * Guarda un lote (también los que llegan de la cola sin conexión). Un registro más antiguo
     * que el guardado no lo reemplaza.
     *
     * @param  list<array{alumno_id: int, estado: string, registrado_at?: string|null, hora?: string|null}>  $registros
     * @return array{guardados: int, ignorados: int}
     */
    public function guardarLote(int $seccionId, string $fecha, array $registros, string $origen, int $usuarioId): array
    {
        $matriculados = DB::table('matriculas')->where('seccion_id', $seccionId)->where('estado', 'matriculado')->pluck('alumno_id')->flip();
        if ($fecha > now()->toDateString()) {
            throw ValidationException::withMessages(['fecha' => ['No se registra asistencia de días futuros.']]);
        }
        $guardados = 0;
        $ignorados = 0;
        foreach ($registros as $r) {
            if (! isset($matriculados[(int) $r['alumno_id']]) || ! isset(self::ESTADOS[$r['estado']])) {
                $ignorados++;

                continue;
            }
            $cuando = isset($r['registrado_at']) ? Carbon::parse($r['registrado_at'])->timezone(config('app.timezone')) : now();
            if ($this->registrar((int) $r['alumno_id'], $seccionId, $fecha, $r['estado'], $cuando, $r['hora'] ?? null, $origen, $usuarioId)) {
                $guardados++;
            } else {
                $ignorados++;
            }
        }

        return ['guardados' => $guardados, 'ignorados' => $ignorados];
    }

    private function registrar(int $alumnoId, int $seccionId, string $fecha, string $estado, Carbon $cuando, ?string $hora, string $origen, int $usuarioId): bool
    {
        $actual = DB::table('asistencias')->where('alumno_id', $alumnoId)->where('fecha', $fecha)->whereNull('horario_sesion_id')->first();
        if ($actual && Carbon::parse($actual->registrado_at)->greaterThan($cuando)) {
            return false; // Conflicto: ya hay un registro más reciente.
        }
        $fila = ['estado' => $estado, 'hora_registro' => $hora ?? ($actual->hora_registro ?? null), 'registrado_por' => $usuarioId, 'origen' => $origen, 'registrado_at' => $cuando];
        if ($actual) {
            DB::table('asistencias')->where('id', $actual->id)->update($fila);
        } else {
            DB::table('asistencias')->insert($fila + ['institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'seccion_id' => $seccionId, 'fecha' => $fecha]);
        }
        if (in_array($estado, ['T', 'F'], true) && $fecha === now()->toDateString() && ! ($actual->notificado_at ?? null)) {
            $this->avisarPadres($alumnoId, $estado);
            DB::table('asistencias')->where('alumno_id', $alumnoId)->where('fecha', $fecha)->whereNull('horario_sesion_id')->update(['notificado_at' => now()]);
        }

        return true;
    }

    private function avisarPadres(int $alumnoId, string $estado): void
    {
        $alumno = DB::table('alumnos')->where('id', $alumnoId)->value('nombres');
        $usuarios = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $alumnoId)->whereNotNull('p.user_id')->pluck('p.user_id')->map(fn ($u) => (int) $u)->all();
        if ($usuarios) {
            $this->push->enviar($usuarios, 'asistencia', $estado === 'T' ? "{$alumno} llegó tarde" : "{$alumno} no asistió hoy",
                $estado === 'T' ? 'Se registró una tardanza hoy.' : 'Se registró una falta hoy. Si corresponde, puedes justificarla.', ['pantalla' => 'inicio', 'id' => $alumnoId]);
        }
    }

    /** EDUN-95 · Llegada por QR: P o T según la hora; si ya llegó antes, no se cambia. */
    public function porQr(string $codigo, int $usuarioId): array
    {
        $alumno = app(Carnes::class)->buscar($codigo);
        if (! $alumno || $alumno['estado'] !== 'activo') {
            throw ValidationException::withMessages(['codigo' => ['Carné no reconocido o alumno inactivo.']]);
        }
        $hoy = now()->toDateString();
        $seccion = DB::table('matriculas as m')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('anios_escolares as y', 'y.id', '=', 'm.anio_escolar_id')
            ->where('m.alumno_id', $alumno['id'])->where('m.estado', 'matriculado')->orderByDesc('y.anio')->value('m.seccion_id');
        if (! $seccion) {
            throw ValidationException::withMessages(['codigo' => ['El alumno no tiene matrícula vigente.']]);
        }
        $previa = DB::table('asistencias')->where('alumno_id', $alumno['id'])->where('fecha', $hoy)->whereNull('horario_sesion_id')->first();
        $nombre = trim("{$alumno['nombres']} {$alumno['apellido_paterno']}");
        if ($previa && in_array($previa->estado, ['P', 'T'], true) && $previa->hora_registro) {
            return ['alumno' => $nombre, 'seccion' => $alumno['seccion'], 'estado' => $previa->estado, 'hora' => substr((string) $previa->hora_registro, 0, 5), 'repetido' => true];
        }
        $ahora = now();
        $config = json_decode(DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->value('configuracion') ?? '{}', true)['asistencia'] ?? [];
        $limite = Carbon::parse($hoy.' '.($config['hora_entrada'] ?? '08:00'), config('app.timezone'))->addMinutes((int) ($config['tolerancia_min'] ?? 0));
        $estado = $ahora->greaterThan($limite) ? 'T' : 'P';
        $this->registrar((int) $alumno['id'], (int) $seccion, $hoy, $estado, $ahora, $ahora->format('H:i:s'), 'qr', $usuarioId);

        return ['alumno' => $nombre, 'seccion' => $alumno['seccion'], 'estado' => $estado, 'hora' => $ahora->format('H:i'), 'repetido' => false];
    }

    /** Resumen de un alumno en un rango (para libretas y alertas). */
    public static function resumen(int $alumnoId, string $desde, string $hasta): array
    {
        $c = DB::table('asistencias')->where('alumno_id', $alumnoId)->whereBetween('fecha', [$desde, $hasta])->whereNull('horario_sesion_id')
            ->selectRaw('estado, count(*) as n')->groupBy('estado')->pluck('n', 'estado');

        return ['P' => (int) ($c['P'] ?? 0), 'T' => (int) ($c['T'] ?? 0), 'F' => (int) ($c['F'] ?? 0), 'J' => (int) ($c['J'] ?? 0)];
    }
}
