<?php

namespace App\Modulos\MOV\Services;

use App\Models\User;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-164 / 166 / 167 · Seguimiento de la movilidad.
 *
 * - Familia (MOV-H06): solo la ruta de su hijo y solo durante el recorrido: última posición,
 *   distancia y llegada estimada al paradero, enlace al mapa del teléfono y bitácora del día;
 *   fuera del recorrido, la hora de la última subida o bajada. Aviso al acercarse (500 m),
 *   una vez por recorrido y paradero.
 * - Monitor (MOV-H07): recorridos en curso con la última señal, el retraso frente a la hora
 *   estimada del siguiente paradero y la alerta por más de 3 minutos sin señal.
 * - Documentos (MOV-H04): SOAT, autorización, revisión técnica y licencias por vencer (30 días).
 *
 * Mapas: se abren en la app de mapas del teléfono o en OpenStreetMap; no se incrusta un mapa con
 * clave de pago.
 */
class SeguimientoMovilidad
{
    public const RADIO_AVISO_M = 500;

    public const MINUTOS_SIN_SENAL = 3;

    public const VELOCIDAD_MEDIA_KMH = 20;

    public function __construct(private Notificador $notificador) {}

    public static function distancia(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    public static function mapa(float $lat, float $lng): string
    {
        return sprintf('https://www.openstreetmap.org/?mlat=%1$.6f&mlon=%2$.6f#map=17/%1$.6f/%2$.6f', $lat, $lng);
    }

    /** MOV-H06 · Lo que ve la familia de cada hijo con movilidad. */
    public function familia(User $usuario): array
    {
        $hijos = FamiliaController::alumnosDe((int) $usuario->id);
        $asignaciones = DB::table('mov_asignaciones as a')->join('mov_paraderos as p', 'p.id', '=', 'a.mov_paradero_id')->join('mov_rutas as r', 'r.id', '=', 'a.mov_ruta_id')
            ->join('alumnos as al', 'al.id', '=', 'a.alumno_id')->leftJoin('mov_unidades as u', 'u.id', '=', 'r.mov_unidad_id')
            ->whereIn('a.alumno_id', $hijos ?: [0])->where('a.activa', true)
            ->get(['a.id', 'a.alumno_id', 'al.nombres as alumno', 'r.id as ruta_id', 'r.nombre as ruta', 'r.turno', 'u.placa', 'p.id as paradero_id', 'p.nombre as paradero', 'p.latitud', 'p.longitud', 'p.hora_estimada']);
        $hoy = now()->toDateString();

        return $asignaciones->map(function ($a) use ($hoy) {
            $recorrido = DB::table('mov_recorridos')->where('mov_ruta_id', $a->ruta_id)->where('fecha', $hoy)->orderByDesc('id')->first();
            $eventos = DB::table('mov_eventos')->where('mov_asignacion_id', $a->id)->whereRaw('(ocurrido_at at time zone ?)::date = ?', [config('app.timezone'), $hoy])->orderBy('ocurrido_at')
                ->get(['tipo', 'ocurrido_at'])->map(fn ($e) => (array) $e)->all();
            $enCurso = $recorrido && $recorrido->estado === 'en_curso';
            $vivo = null;
            if ($enCurso && $recorrido->ultima_latitud !== null) {
                $dist = $a->latitud !== null ? self::distancia((float) $recorrido->ultima_latitud, (float) $recorrido->ultima_longitud, (float) $a->latitud, (float) $a->longitud) : null;
                $vel = (float) DB::table('mov_posiciones')->where('mov_recorrido_id', $recorrido->id)->whereNotNull('velocidad_kmh')->orderByDesc('registrado_at')->limit(5)->avg('velocidad_kmh');
                $vel = $vel >= 5 ? $vel : self::VELOCIDAD_MEDIA_KMH;
                $vivo = [
                    'latitud' => (float) $recorrido->ultima_latitud, 'longitud' => (float) $recorrido->ultima_longitud, 'posicion_at' => $recorrido->ultima_posicion_at,
                    'distancia_m' => $dist !== null ? (int) round($dist) : null, 'llegada_min' => $dist !== null ? (int) ceil($dist / 1000 / $vel * 60) : null,
                    'mapa_url' => self::mapa((float) $recorrido->ultima_latitud, (float) $recorrido->ultima_longitud),
                ];
            }

            return [
                'asignacion_id' => (int) $a->id, 'alumno_id' => (int) $a->alumno_id, 'alumno' => $a->alumno, 'ruta' => $a->ruta, 'turno' => $a->turno, 'placa' => $a->placa,
                'paradero' => $a->paradero, 'hora_estimada' => $a->hora_estimada ? substr((string) $a->hora_estimada, 0, 5) : null,
                'paradero_mapa_url' => $a->latitud !== null ? self::mapa((float) $a->latitud, (float) $a->longitud) : null,
                'recorrido' => $recorrido ? ['estado' => $recorrido->estado, 'sentido' => $recorrido->sentido, 'iniciado_at' => $recorrido->iniciado_at, 'finalizado_at' => $recorrido->finalizado_at] : null,
                'en_vivo' => $vivo, 'bitacora' => $eventos,
            ];
        })->values()->all();
    }

    /** Aviso a las familias cuando la unidad llega a 500 m de su paradero (una vez por recorrido). */
    public function avisarCercania(int $recorridoId, ?float $lat = null, ?float $lng = null): int
    {
        $r = DB::table('mov_recorridos')->find($recorridoId);
        if (! $r || $r->estado !== 'en_curso') {
            return 0;
        }
        // La posición recién recibida (o la última guardada del recorrido).
        $lat ??= $r->ultima_latitud !== null ? (float) $r->ultima_latitud : null;
        $lng ??= $r->ultima_longitud !== null ? (float) $r->ultima_longitud : null;
        if ($lat === null || $lng === null) {
            return 0;
        }
        $avisados = DB::table('mov_avisos_cercania')->where('mov_recorrido_id', $recorridoId)->pluck('mov_paradero_id')->all();
        $n = 0;
        foreach (DB::table('mov_paraderos')->where('mov_ruta_id', $r->mov_ruta_id)->whereNotNull('latitud')->whereNotIn('id', $avisados ?: [0])->get() as $p) {
            if (self::distancia($lat, $lng, (float) $p->latitud, (float) $p->longitud) > self::RADIO_AVISO_M) {
                continue;
            }
            $alumnos = DB::table('mov_asignaciones')->where('mov_paradero_id', $p->id)->where('activa', true)->pluck('alumno_id');
            $familias = DB::table('alumno_apoderado as aa')->join('apoderados as ap', 'ap.id', '=', 'aa.apoderado_id')->whereIn('aa.alumno_id', $alumnos)->whereNotNull('ap.user_id')->pluck('ap.user_id')->unique()->all();
            DB::table('mov_avisos_cercania')->insertOrIgnore(['institucion_id' => ContextoInstitucion::requerido(), 'mov_recorrido_id' => $recorridoId, 'mov_paradero_id' => $p->id]);
            if ($familias) {
                $this->notificador->enviar($familias, ['app'], 'movilidad', 'La movilidad está llegando', "Está a menos de 500 m del paradero {$p->nombre}.", ['pantalla' => 'inicio']);
                $n++;
            }
        }

        return $n;
    }

    /** MOV-H07 · Recorridos en curso con su última señal y retraso. */
    public function monitor(): array
    {
        $hoy = now()->toDateString();

        return DB::table('mov_recorridos as r')->join('mov_rutas as ru', 'ru.id', '=', 'r.mov_ruta_id')->leftJoin('mov_unidades as u', 'u.id', '=', 'ru.mov_unidad_id')
            ->leftJoin('mov_conductores as c', 'c.id', '=', 'ru.conductor_id')
            ->where('r.fecha', $hoy)->whereIn('r.estado', ['en_curso', 'finalizado'])->orderByRaw("r.estado = 'en_curso' desc")->orderBy('ru.nombre')
            ->get(['r.id', 'r.mov_ruta_id', 'r.estado', 'r.sentido', 'r.iniciado_at', 'r.finalizado_at', 'r.ultima_latitud', 'r.ultima_longitud', 'r.ultima_posicion_at', 'r.alerta_senal_at',
                'ru.nombre as ruta', 'ru.turno', 'u.placa', 'c.nombres as conductor'])
            ->map(function ($r) {
                $sinSenal = $r->estado === 'en_curso' ? (int) floor(Carbon::parse($r->ultima_posicion_at ?? $r->iniciado_at)->diffInSeconds(now()) / 60) : null;
                $siguiente = $r->estado === 'en_curso' ? $this->siguienteParadero($r) : null;
                $retraso = $siguiente && $siguiente->hora_estimada ? (int) floor(Carbon::parse(now()->toDateString().' '.$siguiente->hora_estimada)->diffInSeconds(now(), false) / 60) : null;

                return [
                    'id' => (int) $r->id, 'ruta' => $r->ruta, 'turno' => $r->turno, 'placa' => $r->placa, 'conductor' => $r->conductor, 'estado' => $r->estado, 'sentido' => $r->sentido,
                    'iniciado_at' => $r->iniciado_at, 'finalizado_at' => $r->finalizado_at, 'ultima_posicion_at' => $r->ultima_posicion_at,
                    'minutos_sin_senal' => $sinSenal, 'sin_senal' => $sinSenal !== null && $sinSenal > self::MINUTOS_SIN_SENAL,
                    'siguiente_paradero' => $siguiente?->nombre, 'retraso_min' => $retraso !== null && $retraso > 0 ? $retraso : 0,
                    'mapa_url' => $r->ultima_latitud !== null ? self::mapa((float) $r->ultima_latitud, (float) $r->ultima_longitud) : null,
                    'latitud' => $r->ultima_latitud !== null ? (float) $r->ultima_latitud : null, 'longitud' => $r->ultima_longitud !== null ? (float) $r->ultima_longitud : null,
                ];
            })->all();
    }

    /** Alerta a movilidad por recorridos en curso sin señal hace más de 3 minutos (una vez por corte). */
    public function vigilarSenal(): int
    {
        $limite = now()->subMinutes(self::MINUTOS_SIN_SENAL);
        $cortados = DB::table('mov_recorridos as r')->join('mov_rutas as ru', 'ru.id', '=', 'r.mov_ruta_id')->where('r.estado', 'en_curso')->whereNull('r.alerta_senal_at')
            ->where(fn ($q) => $q->where('r.ultima_posicion_at', '<', $limite)->orWhere(fn ($w) => $w->whereNull('r.ultima_posicion_at')->where('r.iniciado_at', '<', $limite)))
            ->get(['r.id', 'ru.nombre']);
        if ($cortados->isEmpty()) {
            return 0;
        }
        $usuarios = DB::table('membresias as m')->join('roles as ro', 'ro.id', '=', 'm.rol_id')->where('ro.codigo', 'movilidad')->pluck('m.user_id')->all();
        foreach ($cortados as $c) {
            DB::table('mov_recorridos')->where('id', $c->id)->update(['alerta_senal_at' => now()]);
            $this->notificador->enviar($usuarios, ['app'], 'movilidad', "Sin señal: {$c->nombre}", 'La unidad no envía su ubicación hace más de '.self::MINUTOS_SIN_SENAL.' minutos.', ['pantalla' => 'inicio']);
        }

        return $cortados->count();
    }

    /** MOV-H04 · Documentos de unidades y licencias que vencen (hoy, en 7 y en 30 días). */
    public function avisarVencimientos(): int
    {
        $hitos = [now()->toDateString(), now()->addDays(7)->toDateString(), now()->addDays(30)->toDateString()];
        $avisos = [];
        foreach (DB::table('mov_unidades')->get() as $u) {
            foreach (['soat_vence' => 'SOAT', 'autorizacion_vence' => 'autorización', 'revision_tecnica_vence' => 'revisión técnica'] as $campo => $nombre) {
                if ($u->{$campo} && in_array((string) $u->{$campo}, $hitos, true)) {
                    $avisos[] = "{$nombre} de {$u->placa} (".date('d/m', strtotime((string) $u->{$campo})).')';
                }
            }
        }
        foreach (DB::table('mov_conductores')->whereNotNull('licencia_vence')->whereIn('licencia_vence', $hitos)->get() as $c) {
            $avisos[] = "licencia de {$c->nombres} (".date('d/m', strtotime((string) $c->licencia_vence)).')';
        }
        if ($avisos) {
            $usuarios = DB::table('membresias as m')->join('roles as ro', 'ro.id', '=', 'm.rol_id')->whereIn('ro.codigo', ['movilidad', 'director'])->pluck('m.user_id')->unique()->all();
            $this->notificador->enviar($usuarios, ['app'], 'movilidad', 'Documentos de movilidad por vencer', ucfirst(implode('; ', array_slice($avisos, 0, 4))).(count($avisos) > 4 ? ' y '.(count($avisos) - 4).' más.' : '.'), ['pantalla' => 'inicio']);
        }

        return count($avisos);
    }

    private function siguienteParadero(object $r): ?object
    {
        $paraderos = DB::table('mov_paraderos')->where('mov_ruta_id', $r->mov_ruta_id)->orderBy('orden', $r->sentido === 'vuelta' ? 'asc' : 'asc')->get();
        foreach ($paraderos as $p) {
            $pendientes = DB::table('mov_asignaciones as a')->where('a.mov_paradero_id', $p->id)->where('a.activa', true)
                ->whereNotExists(fn ($q) => $q->from('mov_eventos as e')->whereColumn('e.mov_asignacion_id', 'a.id')->where('e.mov_recorrido_id', $r->id))->exists();
            if ($pendientes) {
                return $p;
            }
        }

        return null;
    }
}
