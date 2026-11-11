<?php

namespace App\Modulos\COM\Services;

use App\Models\User;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use App\Modulos\SEG\Services\Autorizacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COM · Mensajería y citas.
 *
 * - EDUN-63 · La familia conversa con el tutor (o dirección) con adjuntos e indicador de leído.
 *   Regla 3: fuera del horario de atención el mensaje de la familia se entrega al día siguiente.
 * - EDUN-65 · La familia pide cita con el tutor o dirección, el colegio la confirma y registra acuerdos.
 */
class Mensajeria
{
    public function __construct(private Autorizacion $autorizacion, private Notificador $notificador) {}

    /** Horario de atención del colegio (configuración «mensajeria»), por defecto lun–vie 7:30–16:00. */
    public static function horario(): array
    {
        $c = json_decode(DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->value('configuracion') ?? '{}', true)['mensajeria'] ?? [];

        return ['desde' => $c['desde'] ?? '07:30', 'hasta' => $c['hasta'] ?? '16:00', 'dias' => $c['dias'] ?? [1, 2, 3, 4, 5]];
    }

    /** Momento de entrega: ahora si está dentro del horario; si no, la próxima apertura. */
    public static function entregaPara(Carbon $ahora): Carbon
    {
        $h = self::horario();
        $t = $ahora->copy()->timezone(config('app.timezone'));
        for ($i = 0; $i < 8; $i++) {
            $dia = $t->copy()->addDays($i);
            $abre = Carbon::parse($dia->toDateString().' '.$h['desde'], config('app.timezone'));
            $cierra = Carbon::parse($dia->toDateString().' '.$h['hasta'], config('app.timezone'));
            if (! in_array($dia->dayOfWeekIso, $h['dias'], true)) {
                continue;
            }
            if ($i === 0 && $t->between($abre, $cierra)) {
                return $t;
            }
            if ($i > 0 || $t->lessThan($abre)) {
                return $abre;
            }
        }

        return $t;
    }

    /** Personas del colegio con las que la familia puede conversar o pedir cita para un hijo. */
    public function contactosDe(int $alumnoId): array
    {
        $tutor = DB::table('matriculas as m')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('personal as p', 'p.id', '=', 's.tutor_personal_id')
            ->where('m.alumno_id', $alumnoId)->where('m.estado', 'matriculado')->orderByDesc('m.anio_escolar_id')->first(['p.id', 'p.user_id', DB::raw("p.nombres || ' ' || p.apellidos as nombre")]);
        $direccion = DB::table('membresias as mb')->join('roles as r', 'r.id', '=', 'mb.rol_id')->join('users as u', 'u.id', '=', 'mb.user_id')
            ->leftJoin('personal as p', 'p.user_id', '=', 'u.id')->whereIn('r.codigo', ['director'])->first(['p.id', 'u.id as user_id', DB::raw("u.nombres || ' ' || u.apellidos as nombre")]);

        return array_values(array_filter([
            $tutor && $tutor->user_id ? ['rol' => 'tutor', 'personal_id' => $tutor->id, 'user_id' => (int) $tutor->user_id, 'nombre' => $tutor->nombre] : null,
            $direccion ? ['rol' => 'direccion', 'personal_id' => $direccion->id, 'user_id' => (int) $direccion->user_id, 'nombre' => $direccion->nombre] : null,
        ]));
    }

    public function conversaciones(User $usuario): array
    {
        return DB::table('conversaciones as c')->join('conversacion_participantes as p', 'p.conversacion_id', '=', 'c.id')->leftJoin('alumnos as a', 'a.id', '=', 'c.alumno_id')
            ->where('p.user_id', $usuario->id)->orderByDesc('c.updated_at')
            ->select(['c.id', 'c.asunto', 'c.cerrada', 'c.updated_at', 'a.nombres as alumno', 'p.ultimo_leido_at'])
            ->selectRaw('(select count(*) from mensajes m where m.conversacion_id = c.id and m.autor_id <> p.user_id and m.entregar_at <= ? and (p.ultimo_leido_at is null or m.created_at > p.ultimo_leido_at)) as sin_leer', [now()])
            ->selectRaw("(select string_agg(u.nombres || ' ' || u.apellidos, ', ') from conversacion_participantes q join users u on u.id = q.user_id where q.conversacion_id = c.id and q.user_id <> p.user_id) as con")
            ->get()->map(fn ($c) => (array) $c)->all();
    }

    public function iniciar(User $usuario, int $alumnoId, string $rol, string $asunto, string $mensaje, ?int $archivoId): int
    {
        abort_unless(in_array($alumnoId, FamiliaController::alumnosDe((int) $usuario->id), true), 404);
        $contacto = collect($this->contactosDe($alumnoId))->firstWhere('rol', $rol);
        if (! $contacto) {
            throw ValidationException::withMessages(['rol' => [$rol === 'tutor' ? 'La sección aún no tiene tutor con cuenta.' : 'No hay dirección disponible.']]);
        }

        return DB::transaction(function () use ($usuario, $alumnoId, $contacto, $asunto, $mensaje, $archivoId) {
            $inst = ContextoInstitucion::requerido();
            $id = (int) DB::table('conversaciones')->insertGetId(['institucion_id' => $inst, 'asunto' => trim($asunto), 'alumno_id' => $alumnoId]);
            DB::table('conversacion_participantes')->insert([
                ['institucion_id' => $inst, 'conversacion_id' => $id, 'user_id' => $usuario->id, 'ultimo_leido_at' => now()],
                ['institucion_id' => $inst, 'conversacion_id' => $id, 'user_id' => $contacto['user_id'], 'ultimo_leido_at' => null],
            ]);
            $this->enviar($id, $usuario, $mensaje, $archivoId);

            return $id;
        });
    }

    public function ver(int $id, User $usuario): array
    {
        $yo = DB::table('conversacion_participantes')->where('conversacion_id', $id)->where('user_id', $usuario->id)->first();
        abort_if(! $yo, 404);
        $otros = DB::table('conversacion_participantes')->where('conversacion_id', $id)->where('user_id', '<>', $usuario->id)->get();
        $leidoOtros = $otros->max('ultimo_leido_at');
        $mensajes = DB::table('mensajes as m')->join('users as u', 'u.id', '=', 'm.autor_id')->leftJoin('archivos as a', 'a.id', '=', 'm.archivo_id')->where('m.conversacion_id', $id)
            ->where(fn ($q) => $q->where('m.entregar_at', '<=', now())->orWhere('m.autor_id', $usuario->id))->orderBy('m.created_at')
            ->get(['m.id', 'm.contenido', 'm.created_at', 'm.entregar_at', 'm.autor_id', 'm.archivo_id', 'a.nombre_original as adjunto', DB::raw("u.nombres || ' ' || u.apellidos as autor")])
            ->map(fn ($m) => (array) $m + ['mio' => (int) $m->autor_id === (int) $usuario->id, 'leido' => $leidoOtros !== null && $m->created_at <= $leidoOtros,
                'programado' => Carbon::parse($m->entregar_at)->isFuture()])->all();
        DB::table('conversacion_participantes')->where('id', $yo->id)->update(['ultimo_leido_at' => now()]);
        $c = DB::table('conversaciones')->find($id);

        return ['id' => $id, 'asunto' => $c->asunto, 'cerrada' => $c->cerrada, 'mensajes' => $mensajes, 'horario' => self::horario()];
    }

    public function enviar(int $id, User $usuario, string $contenido, ?int $archivoId): array
    {
        abort_unless(DB::table('conversacion_participantes')->where('conversacion_id', $id)->where('user_id', $usuario->id)->exists(), 404);
        if (DB::table('conversaciones')->where('id', $id)->value('cerrada')) {
            throw ValidationException::withMessages(['contenido' => ['La conversación está cerrada.']]);
        }
        if ($archivoId && ! DB::table('archivos')->where('id', $archivoId)->where('subido_por', $usuario->id)->exists()) {
            throw ValidationException::withMessages(['archivo_id' => ['Adjunto no válido.']]);
        }
        $esFamilia = $this->autorizacion->tieneRol($usuario, 'apoderado') && ! $this->autorizacion->tieneRol($usuario, 'docente', 'tutor', 'director', 'coordinador', 'secretaria', 'psicologia');
        $entrega = $esFamilia ? self::entregaPara(now()) : now();
        DB::table('mensajes')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'conversacion_id' => $id, 'autor_id' => $usuario->id,
            'contenido' => trim($contenido), 'archivo_id' => $archivoId, 'entregar_at' => $entrega]);
        DB::table('conversaciones')->where('id', $id)->update(['updated_at' => now()]);
        DB::table('conversacion_participantes')->where('conversacion_id', $id)->where('user_id', $usuario->id)->update(['ultimo_leido_at' => now()]);
        if ($entrega->lessThanOrEqualTo(now()->addMinute())) {
            $otros = DB::table('conversacion_participantes')->where('conversacion_id', $id)->where('user_id', '<>', $usuario->id)->pluck('user_id')->all();
            $this->notificador->enviar($otros, ['app'], 'comunicados', "Mensaje de {$usuario->nombres}", mb_strimwidth(trim($contenido), 0, 120, '…'), ['pantalla' => 'mensajes', 'id' => $id]);
        }

        return ['entregado' => $entrega->lessThanOrEqualTo(now()->addMinute()), 'entregar_at' => $entrega->toIso8601String()];
    }

    // ── EDUN-65 · Citas

    public function citas(User $usuario, bool $comoFamilia): array
    {
        $q = DB::table('citas as c')->join('alumnos as a', 'a.id', '=', 'c.alumno_id')->join('personal as p', 'p.id', '=', 'c.personal_id')->join('apoderados as ap', 'ap.id', '=', 'c.apoderado_id');
        if ($comoFamilia) {
            $q->where('ap.user_id', $usuario->id);
        } elseif (! $this->autorizacion->tieneRol($usuario, 'director', 'coordinador', 'secretaria', 'promotor')) {
            $q->where('p.user_id', $usuario->id);
        }

        return $q->orderByDesc('c.inicio')->limit(200)->get(['c.id', 'c.inicio', 'c.motivo', 'c.acuerdos', 'c.estado', 'a.nombres as alumno',
            DB::raw("p.nombres || ' ' || p.apellidos as con"), DB::raw("ap.nombres || ' ' || ap.apellidos as apoderado")])->map(fn ($c) => (array) $c)->all();
    }

    public function solicitarCita(User $usuario, int $alumnoId, string $rol, string $inicio, string $motivo): int
    {
        abort_unless(in_array($alumnoId, FamiliaController::alumnosDe((int) $usuario->id), true), 404);
        $contacto = collect($this->contactosDe($alumnoId))->firstWhere('rol', $rol);
        $apoderado = DB::table('apoderados')->where('user_id', $usuario->id)->value('id');
        if (! $contacto || ! $contacto['personal_id'] || ! $apoderado) {
            throw ValidationException::withMessages(['rol' => ['No hay con quién agendar la cita (falta tutor o personal de dirección registrado).']]);
        }
        if (Carbon::parse($inicio)->isPast()) {
            throw ValidationException::withMessages(['inicio' => ['Elige una fecha futura.']]);
        }
        $id = (int) DB::table('citas')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'apoderado_id' => $apoderado,
            'personal_id' => $contacto['personal_id'], 'inicio' => Carbon::parse($inicio)->timezone(config('app.timezone')), 'motivo' => trim($motivo)]);
        $this->notificador->enviar([$contacto['user_id']], ['app'], 'comunicados', 'Nueva solicitud de cita', trim($motivo), ['pantalla' => 'citas', 'id' => $id]);

        return $id;
    }

    public function gestionarCita(int $id, string $estado, ?string $inicio, ?string $acuerdos, User $usuario): void
    {
        $c = DB::table('citas as c')->join('personal as p', 'p.id', '=', 'c.personal_id')->join('apoderados as ap', 'ap.id', '=', 'c.apoderado_id')->where('c.id', $id)->first(['c.*', 'p.user_id as personal_user', 'ap.user_id as familia_user']);
        abort_if(! $c, 404);
        abort_unless((int) $c->personal_user === (int) $usuario->id || $this->autorizacion->tieneRol($usuario, 'director', 'coordinador', 'secretaria', 'promotor'), 403);
        if ($estado === 'realizada' && ! trim((string) $acuerdos)) {
            throw ValidationException::withMessages(['acuerdos' => ['Registra los acuerdos de la cita.']]);
        }
        DB::table('citas')->where('id', $id)->update(array_filter(['estado' => $estado, 'inicio' => $inicio ? Carbon::parse($inicio)->timezone(config('app.timezone')) : null, 'acuerdos' => $acuerdos], fn ($v) => $v !== null));
        if ($c->familia_user && in_array($estado, ['confirmada', 'cancelada'], true)) {
            $cuando = Carbon::parse($inicio ?? $c->inicio)->timezone(config('app.timezone'))->locale('es')->translatedFormat('l j \d\e F, H:i');
            $this->notificador->enviar([(int) $c->familia_user], ['app'], 'comunicados', $estado === 'confirmada' ? 'Cita confirmada' : 'Cita cancelada', $estado === 'confirmada' ? "Te esperamos el {$cuando}." : 'El colegio canceló la cita.', ['pantalla' => 'citas', 'id' => $id]);
        }
    }
}
