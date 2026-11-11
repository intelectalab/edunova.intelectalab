<?php

namespace App\Modulos\MOV\Services;

use App\Models\User;
use App\Modulos\ADM\Services\Carnes;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    private function recorridoEnCurso(User $usuario, int $recorridoId): object
    {
        $r = DB::table('mov_recorridos')->find($recorridoId);
        abort_if(! $r, 404);
        $this->exigirRuta($usuario, (int) $r->mov_ruta_id);
        if ($r->estado !== 'en_curso') {
            throw ValidationException::withMessages(['recorrido' => ['El recorrido no está en curso.']]);
        }

        return $r;
    }

    /** @param list<array{latitud: float, longitud: float, velocidad_kmh?: float|null, precision_m?: int|null, registrado_at?: string|null}> $posiciones */
    public function posiciones(User $usuario, int $recorridoId, array $posiciones): int
    {
        $this->recorridoEnCurso($usuario, $recorridoId);
        $inst = ContextoInstitucion::requerido();
        foreach ($posiciones as $p) {
            DB::table('mov_posiciones')->insert(['institucion_id' => $inst, 'mov_recorrido_id' => $recorridoId, 'latitud' => round((float) $p['latitud'], 6), 'longitud' => round((float) $p['longitud'], 6),
                'velocidad_kmh' => $p['velocidad_kmh'] ?? null, 'precision_m' => isset($p['precision_m']) ? (int) $p['precision_m'] : null, 'registrado_at' => $p['registrado_at'] ?? now()]);
        }

        return count($posiciones);
    }

    public function evento(User $usuario, int $recorridoId, int $asignacionId, string $tipo, ?float $lat, ?float $lng): void
    {
        $r = $this->recorridoEnCurso($usuario, $recorridoId);
        $a = DB::table('mov_asignaciones')->where('id', $asignacionId)->where('mov_ruta_id', $r->mov_ruta_id)->first();
        abort_if(! $a, 404);
        DB::table('mov_eventos')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'mov_asignacion_id' => $asignacionId, 'mov_recorrido_id' => $recorridoId, 'tipo' => $tipo,
            'ocurrido_at' => now(), 'latitud' => $lat, 'longitud' => $lng, 'registrado_por' => $usuario->id, 'notificado_at' => now()]);
        $nombre = DB::table('alumnos')->where('id', $a->alumno_id)->value('nombres');
        $padres = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $a->alumno_id)->whereNotNull('p.user_id')->pluck('p.user_id')->all();
        $texto = ['subio' => "{$nombre} subió a la movilidad.", 'bajo' => "{$nombre} bajó de la movilidad.", 'no_subio' => "{$nombre} no subió a la movilidad."][$tipo];
        $this->notificador->enviar($padres, ['app'], 'movilidad', 'Movilidad escolar', $texto, ['pantalla' => 'inicio', 'id' => $a->alumno_id]);
    }

    public function finalizar(User $usuario, int $recorridoId): void
    {
        $this->recorridoEnCurso($usuario, $recorridoId);
        DB::table('mov_recorridos')->where('id', $recorridoId)->update(['estado' => 'finalizado', 'finalizado_at' => now()]);
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

    public function atender(int $alumnoId, string $motivo, string $accion, bool $derivado, ?string $retiradoPor, bool $avisar, int $usuarioId): int
    {
        abort_if(! DB::table('alumnos')->whereNull('eliminado_at')->where('id', $alumnoId)->exists(), 404);
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
