<?php

namespace App\Modulos\MOV\Services;

use App\Models\User;
use App\Modulos\ADM\Services\Carnes;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\POR\Services\Salidas;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * EDUN-199 · MOB-H06 App del personal según rol (complementa la asistencia y el QR de S19).
 *
 * - Conductor y auxiliar de movilidad: sus rutas del día, iniciar y finalizar el recorrido, enviar
 *   la ubicación (solo se guarda con el recorrido en curso, por privacidad) y marcar quién subió o
 *   bajó, con aviso a la familia.
 * - Enfermería: atención rápida en tópico (alumno por QR del carné o nombre) con aviso al padre.
 */
class AppPersonal
{
    public function __construct(private Notificador $notificador) {}

    /** Rutas en las que el usuario es conductor o auxiliar, con el recorrido de hoy. */
    public function misRutas(User $usuario): array
    {
        $yo = DB::table('mov_conductores')->where('user_id', $usuario->id)->pluck('id');
        $hoy = now()->toDateString();

        return DB::table('mov_rutas as r')->leftJoin('mov_unidades as u', 'u.id', '=', 'r.mov_unidad_id')
            ->where(fn ($q) => $q->whereIn('r.conductor_id', $yo)->orWhereIn('r.auxiliar_id', $yo))->orderBy('r.nombre')
            ->get(['r.id', 'r.nombre', 'r.turno', 'u.placa'])
            ->map(fn ($r) => (array) $r + [
                'recorridos' => DB::table('mov_recorridos')->where('mov_ruta_id', $r->id)->where('fecha', $hoy)->get(['id', 'sentido', 'estado', 'iniciado_at', 'finalizado_at'])->map(fn ($x) => (array) $x)->all(),
                'alumnos' => DB::table('mov_asignaciones as a')->join('alumnos as al', 'al.id', '=', 'a.alumno_id')->join('mov_paraderos as p', 'p.id', '=', 'a.mov_paradero_id')
                    ->where('a.mov_ruta_id', $r->id)->where('a.activa', true)->orderBy('p.orden')
                    ->get(['a.id as asignacion_id', 'al.nombres', 'al.apellido_paterno', 'p.nombre as paradero', 'p.orden'])->map(fn ($x) => (array) $x)->all(),
            ])->all();
    }

    private function exigirRuta(User $usuario, int $rutaId): void
    {
        $yo = DB::table('mov_conductores')->where('user_id', $usuario->id)->pluck('id');
        abort_unless(DB::table('mov_rutas')->where('id', $rutaId)->where(fn ($q) => $q->whereIn('conductor_id', $yo)->orWhereIn('auxiliar_id', $yo))->exists(), 403, 'No estás asignado a esa ruta.');
    }

    public function iniciar(User $usuario, int $rutaId, string $sentido): int
    {
        $this->exigirRuta($usuario, $rutaId);
        $hoy = now()->toDateString();
        if (DB::table('mov_recorridos')->where('mov_ruta_id', $rutaId)->where('fecha', $hoy)->where('estado', 'en_curso')->exists()) {
            throw ValidationException::withMessages(['ruta' => ['Ya hay un recorrido en curso en esta ruta.']]);
        }
        DB::table('mov_recorridos')->upsert([[
            'institucion_id' => ContextoInstitucion::requerido(), 'mov_ruta_id' => $rutaId, 'fecha' => $hoy, 'sentido' => $sentido, 'estado' => 'en_curso',
            'iniciado_at' => now(), 'iniciado_por' => $usuario->id,
        ]], ['mov_ruta_id', 'fecha', 'sentido'], ['estado', 'iniciado_at', 'iniciado_por']);

        return (int) DB::table('mov_recorridos')->where('mov_ruta_id', $rutaId)->where('fecha', $hoy)->where('sentido', $sentido)->value('id');
    }

    /**
     * El recorrido debe estar en curso o, si llega algo registrado sin conexión (EDUN-163/165),
     * haber ocurrido mientras estuvo en curso: entre el inicio y el fin.
     */
    private function recorridoEnCurso(User $usuario, int $recorridoId, ?Carbon $cuando = null): object
    {
        $r = DB::table('mov_recorridos')->find($recorridoId);
        abort_if(! $r, 404);
        $this->exigirRuta($usuario, (int) $r->mov_ruta_id);
        $dentro = $cuando && $r->iniciado_at && $cuando->greaterThanOrEqualTo(Carbon::parse($r->iniciado_at)->subMinute())
            && $r->estado === 'finalizado' && $r->finalizado_at && $cuando->lessThanOrEqualTo(Carbon::parse($r->finalizado_at)->addMinute());
        if ($r->estado !== 'en_curso' && ! $dentro) {
            throw ValidationException::withMessages(['recorrido' => ['El recorrido no está en curso.']]);
        }
        if ($cuando && $cuando->greaterThan(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['ocurrido_at' => ['La hora no es válida (futura).']]);
        }

        return $r;
    }

    /** @param list<array{latitud: float, longitud: float, velocidad_kmh?: float|null, precision_m?: int|null, registrado_at?: string|null}> $posiciones */
    public function posiciones(User $usuario, int $recorridoId, array $posiciones): int
    {
        // Las guardadas sin señal llegan después, con su hora: se aceptan si caen dentro del recorrido.
        $primera = collect($posiciones)->pluck('registrado_at')->filter()->min();
        $r = $this->recorridoEnCurso($usuario, $recorridoId, $primera ? Carbon::parse($primera) : null);
        $inst = ContextoInstitucion::requerido();
        $inicio = $r->iniciado_at ? Carbon::parse($r->iniciado_at)->subMinute() : null;
        // Privacidad: ninguna posición después del fin del recorrido.
        $fin = $r->finalizado_at ? Carbon::parse($r->finalizado_at) : now()->addMinutes(5);
        $posiciones = array_values(array_filter($posiciones, function ($p) use ($inicio, $fin) {
            $t = isset($p['registrado_at']) ? Carbon::parse($p['registrado_at']) : now();

            return (! $inicio || $t->greaterThanOrEqualTo($inicio)) && $t->lessThanOrEqualTo($fin);
        }));
        // La última posición del recorrido la actualiza la base (tg_posicion_recorrido_en_curso).
        foreach ($posiciones as $p) {
            DB::table('mov_posiciones')->insert(['institucion_id' => $inst, 'mov_recorrido_id' => $recorridoId, 'latitud' => round((float) $p['latitud'], 6), 'longitud' => round((float) $p['longitud'], 6),
                'velocidad_kmh' => $p['velocidad_kmh'] ?? null, 'precision_m' => isset($p['precision_m']) ? (int) $p['precision_m'] : null, 'registrado_at' => $p['registrado_at'] ?? now()]);
        }

        if ($posiciones) {
            // Volvió la señal: la próxima pérdida vuelve a alertar; y aviso a las familias que están cerca (EDUN-166).
            DB::table('mov_recorridos')->where('id', $recorridoId)->whereNotNull('alerta_senal_at')->update(['alerta_senal_at' => null]);
            $ultima = collect($posiciones)->sortBy(fn ($p) => $p['registrado_at'] ?? now()->toIso8601String())->last();
            app(SeguimientoMovilidad::class)->avisarCercania($recorridoId, (float) $ultima['latitud'], (float) $ultima['longitud']);
        }

        return count($posiciones);
    }

    /** Subió / bajó / no subió. Con $cuando (registrado sin conexión) se guarda la hora real y no se duplica. */
    public function evento(User $usuario, int $recorridoId, int $asignacionId, string $tipo, ?float $lat, ?float $lng, ?Carbon $cuando = null): void
    {
        $r = $this->recorridoEnCurso($usuario, $recorridoId, $cuando);
        $a = DB::table('mov_asignaciones')->where('id', $asignacionId)->where('mov_ruta_id', $r->mov_ruta_id)->first();
        abort_if(! $a, 404, 'Ese alumno no está en esta ruta.');
        $ocurrido = $cuando ?? now();
        if ($cuando && DB::table('mov_eventos')->where('mov_asignacion_id', $asignacionId)->where('mov_recorrido_id', $recorridoId)->where('tipo', $tipo)->where('ocurrido_at', $ocurrido)->exists()) {
            return; // El mismo registro enviado dos veces.
        }
        DB::table('mov_eventos')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'mov_asignacion_id' => $asignacionId, 'mov_recorrido_id' => $recorridoId, 'tipo' => $tipo,
            'ocurrido_at' => $ocurrido, 'latitud' => $lat, 'longitud' => $lng, 'registrado_por' => $usuario->id, 'notificado_at' => now()]);
        $nombre = DB::table('alumnos')->where('id', $a->alumno_id)->value('nombres');
        $padres = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $a->alumno_id)->whereNotNull('p.user_id')->pluck('p.user_id')->all();
        $hora = $cuando ? ' a las '.$cuando->copy()->timezone(config('app.timezone'))->format('H:i').' (registrado sin señal)' : '';
        $texto = ['subio' => "{$nombre} subió a la movilidad{$hora}.", 'bajo' => "{$nombre} bajó de la movilidad{$hora}.", 'no_subio' => "{$nombre} no subió a la movilidad{$hora}."][$tipo];
        $this->notificador->enviar($padres, ['app'], 'movilidad', 'Movilidad escolar', $texto, ['pantalla' => 'inicio', 'id' => $a->alumno_id]);
    }

    /**
     * EDUN-163 · Marcas guardadas sin conexión, en orden; cada una responde por separado.
     *
     * @param  list<array{id: string, recorrido_id: int, asignacion_id: int, tipo: string, ocurrido_at: string, latitud?: float|null, longitud?: float|null}>  $eventos
     */
    public function eventosLote(User $usuario, array $eventos): array
    {
        $resultados = [];
        foreach ($eventos as $e) {
            try {
                DB::transaction(fn () => $this->evento($usuario, (int) $e['recorrido_id'], (int) $e['asignacion_id'], $e['tipo'], $e['latitud'] ?? null, $e['longitud'] ?? null, Carbon::parse($e['ocurrido_at'])));
                $resultados[] = ['id' => $e['id'], 'ok' => true];
            } catch (ValidationException $x) {
                $resultados[] = ['id' => $e['id'], 'ok' => false, 'mensaje' => collect($x->errors())->flatten()->first()];
            } catch (HttpException $x) {
                $resultados[] = ['id' => $e['id'], 'ok' => false, 'mensaje' => $x->getMessage() ?: 'No encontrado.'];
            }
        }

        return ['resultados' => $resultados];
    }

    /** Fin del recorrido; si se marcó sin señal, llega con la hora real. Repetirlo no falla. */
    public function finalizar(User $usuario, int $recorridoId, ?Carbon $cuando = null): void
    {
        $r = DB::table('mov_recorridos')->find($recorridoId);
        if ($r && $r->estado === 'finalizado') {
            $this->exigirRuta($usuario, (int) $r->mov_ruta_id);

            return;
        }
        $this->recorridoEnCurso($usuario, $recorridoId);
        $fin = $cuando && $cuando->lessThan(now()) && $r->iniciado_at && $cuando->greaterThan(Carbon::parse($r->iniciado_at)) ? $cuando : now();
        DB::table('mov_recorridos')->where('id', $recorridoId)->update(['estado' => 'finalizado', 'finalizado_at' => $fin]);
    }

    // ── Enfermería: atención rápida

    public function buscarAlumno(string $texto): array
    {
        if ($por = app(Carnes::class)->buscar($texto)) {
            return [$por];
        }
        $t = '%'.mb_strtolower(trim($texto)).'%';

        return DB::table('alumnos')->whereNull('eliminado_at')->whereRaw("lower(apellido_paterno || ' ' || coalesce(apellido_materno, '') || ' ' || nombres) like ?", [$t])
            ->orderBy('apellido_paterno')->limit(10)->get(['id', 'apellido_paterno', 'apellido_materno', 'nombres'])->map(fn ($a) => (array) $a)->all();
    }

    /**
     * EDUN-176 · Si se entrega al alumno (derivado), se verifica el documento de quien lo retira
     * contra los apoderados y autorizados, y queda la salida registrada (origen «topico»).
     */
    public function atender(int $alumnoId, string $motivo, string $accion, bool $derivado, ?string $retiroDocumento, bool $avisar, int $usuarioId): int
    {
        abort_if(! DB::table('alumnos')->whereNull('eliminado_at')->where('id', $alumnoId)->exists(), 404);
        $retiradoPor = null;
        if ($retiroDocumento !== null && trim($retiroDocumento) !== '') {
            $retiradoPor = app(Salidas::class)->registrar($alumnoId, $retiroDocumento, 'topico', 'Retiro desde tópico: '.trim($motivo), $usuarioId)['recoge'];
        }
        // Los datos de salud tienen RLS propio: solo se abren a quien tiene permiso de salud (lo exige la ruta).
        DB::select("select set_config('app.acceso_salud', 'on', true)");
        $id = (int) DB::table('salud.atenciones')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'motivo' => trim($motivo), 'accion' => trim($accion), 'derivado' => $derivado,
            'retirado_por' => $retiradoPor, 'registrado_por' => $usuarioId,
        ]);
        if ($avisar) {
            $nombre = DB::table('alumnos')->where('id', $alumnoId)->value('nombres');
            $padres = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $alumnoId)->whereNotNull('p.user_id')->pluck('p.user_id')->all();
            $this->notificador->enviar($padres, ['app'], 'comunicados', "{$nombre} fue atendido(a) en tópico", trim($motivo).'. '.($derivado ? 'Fue derivado(a); comunícate con el colegio.' : trim($accion)), ['pantalla' => 'inicio', 'id' => $alumnoId]);
            DB::table('salud.atenciones')->where('id', $id)->update(['padre_notificado_at' => now()]);
        }

        return $id;
    }
}
