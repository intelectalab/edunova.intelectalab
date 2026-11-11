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
        // EDUN-96 · Una falta en un día ya justificado (justificación aceptada) entra como J.
        if ($estado === 'F' && Justificaciones::cubre($alumnoId, $fecha)) {
            $estado = 'J';
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

    private function avisarCorreccion(int $alumnoId, string $estado, string $hora): void
    {
        $alumno = DB::table('alumnos')->where('id', $alumnoId)->value('nombres');
        $usuarios = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $alumnoId)->whereNotNull('p.user_id')->pluck('p.user_id')->map(fn ($u) => (int) $u)->all();
        if ($usuarios) {
            $this->push->enviar($usuarios, 'asistencia', "Corrección: {$alumno} sí asistió", "Se registró su llegada a las {$hora} con el carné".($estado === 'T' ? ' (tardanza).' : '.'), ['pantalla' => 'inicio', 'id' => $alumnoId]);
        }
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

    /**
     * EDUN-95 · Llegada por QR: P o T según la hora; si ya llegó antes, no se cambia.
     * EDUN-215 · Con $cuando (lectura hecha sin conexión) se usa la hora real del escaneo, no la de
     * llegada al servidor; si ya hay un registro más reciente (p. ej. lo corrigió el tutor), gana ese.
     */
    public function porQr(string $codigo, int $usuarioId, ?Carbon $cuando = null): array
    {
        $alumno = app(Carnes::class)->buscar($codigo);
        if (! $alumno || $alumno['estado'] !== 'activo') {
            throw ValidationException::withMessages(['codigo' => ['Carné no reconocido o alumno inactivo.']]);
        }
        $ahora = ($cuando ?? now())->copy()->timezone(config('app.timezone'));
        if ($ahora->greaterThan(now()->addMinutes(5)) || $ahora->lessThan(now()->subDays(7)->startOfDay())) {
            throw ValidationException::withMessages(['codigo' => ['La hora de la lectura no es válida (futura o de hace más de 7 días).']]);
        }
        $hoy = $ahora->toDateString();
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
        $config = json_decode(DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->value('configuracion') ?? '{}', true)['asistencia'] ?? [];
        $limite = Carbon::parse($hoy.' '.($config['hora_entrada'] ?? '08:00'), config('app.timezone'))->addMinutes((int) ($config['tolerancia_min'] ?? 0));
        $estado = $ahora->greaterThan($limite) ? 'T' : 'P';
        // Una falta («no lo vi») registrada después no borra una llegada comprobada con el carné:
        // la lectura gana y la falta pasa a P o T con la hora real (y se corrige el aviso).
        if ($previa && $previa->estado === 'F' && Carbon::parse($previa->registrado_at)->greaterThan($ahora)) {
            DB::table('asistencias')->where('id', $previa->id)->update(['estado' => $estado, 'hora_registro' => $ahora->format('H:i:s'), 'origen' => $cuando ? 'offline' : 'qr', 'registrado_por' => $usuarioId]);
            if ($previa->notificado_at) {
                $this->avisarCorreccion((int) $alumno['id'], $estado, $ahora->format('H:i'));
            }

            return ['alumno' => $nombre, 'seccion' => $alumno['seccion'], 'estado' => $estado, 'hora' => $ahora->format('H:i'), 'repetido' => false, 'corrigio_falta' => true];
        }
        if (! $this->registrar((int) $alumno['id'], (int) $seccion, $hoy, $estado, $ahora, $ahora->format('H:i:s'), $cuando ? 'offline' : 'qr', $usuarioId)) {
            return ['alumno' => $nombre, 'seccion' => $alumno['seccion'], 'estado' => $previa->estado, 'hora' => $ahora->format('H:i'), 'repetido' => true, 'conflicto' => true];
        }

        return ['alumno' => $nombre, 'seccion' => $alumno['seccion'], 'estado' => $estado, 'hora' => $ahora->format('H:i'), 'repetido' => false];
    }

    /**
     * EDUN-215 · Lecturas de QR hechas sin conexión, enviadas en bloque. Idempotente: reenviar el
     * mismo bloque no duplica (el alumno ya figura como llegado). Cada lectura responde por su id local.
     *
     * @param  list<array{id: string, codigo: string, leido_at: string}>  $lecturas
     */
    public function loteQr(array $lecturas, int $usuarioId): array
    {
        $resultados = [];
        foreach ($lecturas as $l) {
            try {
                $r = $this->porQr($l['codigo'], $usuarioId, Carbon::parse($l['leido_at']));
                $resultados[] = ['id' => $l['id'], 'ok' => true] + $r;
            } catch (ValidationException $e) {
                $resultados[] = ['id' => $l['id'], 'ok' => false, 'mensaje' => collect($e->errors())->flatten()->first()];
            }
        }

        return ['resultados' => $resultados];
    }

    /** Resumen de un alumno en un rango (para libretas y alertas). */
    public static function resumen(int $alumnoId, string $desde, string $hasta): array
    {
        $c = DB::table('asistencias')->where('alumno_id', $alumnoId)->whereBetween('fecha', [$desde, $hasta])->whereNull('horario_sesion_id')
            ->selectRaw('estado, count(*) as n')->groupBy('estado')->pluck('n', 'estado');

        return ['P' => (int) ($c['P'] ?? 0), 'T' => (int) ($c['T'] ?? 0), 'F' => (int) ($c['F'] ?? 0), 'J' => (int) ($c['J'] ?? 0)];
    }
}
