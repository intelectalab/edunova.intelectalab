<?php

namespace App\Modulos\PLA\Services;

use App\Modulos\Compartido\Correo\CorreoTransaccional;
use App\Modulos\PLA\Correo\AlertaSla;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-29 / EDUN-31 / EDUN-32 / EDUN-33 · Mesa de ayuda con SLA.
 *
 * - Al crear el ticket se calculan los vencimientos de primera respuesta y de resolución según
 *   la prioridad (sla_politicas, en horas hábiles).
 * - Mientras está «esperando» al colegio el reloj se detiene: al reanudar, los vencimientos se
 *   corren lo que duró la pausa.
 * - Semáforo: verde < 75 %, ámbar ≥ 75 %, rojo vencido. Alerta interna al 75 % y escalamiento
 *   al vencer (vigilar(), cada 5 minutos).
 * - Las respuestas internas nunca las ve el colegio.
 *
 * La consola usa la conexión «plataforma»; el colegio, la de la app (RLS por colegio).
 */
class MesaDeAyuda
{
    public const ESTADOS = ['abierto' => 'Abierto', 'en_curso' => 'En curso', 'esperando' => 'Esperando al colegio', 'resuelto' => 'Resuelto', 'cerrado' => 'Cerrado'];

    public const CATEGORIAS = ['consulta' => 'Consulta', 'incidente' => 'Incidente', 'error' => 'Error', 'solicitud' => 'Solicitud', 'capacitacion' => 'Capacitación', 'facturacion' => 'Facturación'];

    public function __construct(private ?string $conexion = null) {}

    public static function plataforma(): self
    {
        return new self(ConsolaInstituciones::CONEXION);
    }

    private function db(): Connection
    {
        return DB::connection($this->conexion);
    }

    private function horario(): HorarioHabil
    {
        return new HorarioHabil($this->conexion);
    }

    public function crear(int $institucionId, array $d, int $autorId): int
    {
        $politica = $this->db()->table('sla_politicas')->where('prioridad', $d['prioridad'])->first();
        $ahora = now();
        $siguiente = (int) $this->db()->table('tickets_soporte')->where('institucion_id', $institucionId)->count() + 1;

        return (int) $this->db()->table('tickets_soporte')->insertGetId([
            'institucion_id' => $institucionId, 'numero' => 'T-'.str_pad((string) $siguiente, 5, '0', STR_PAD_LEFT),
            'asunto' => trim($d['asunto']), 'descripcion' => trim($d['descripcion']), 'categoria' => $d['categoria'], 'prioridad' => $d['prioridad'],
            'creado_por' => $autorId, 'sla_politica_id' => $politica?->id, 'created_at' => $ahora,
            'vence_primera_respuesta_at' => $politica ? $this->vence($ahora, (int) $politica->horas_primera_respuesta, (bool) $politica->solo_horario_habil) : null,
            'vence_resolucion_at' => $politica ? $this->vence($ahora, (int) $politica->horas_resolucion, (bool) $politica->solo_horario_habil) : null,
        ]);
    }

    private function vence(Carbon $desde, int $horas, bool $habil): Carbon
    {
        return $habil ? $this->horario()->sumar($desde, $horas * 60) : $desde->copy()->addHours($horas);
    }

    /** El staff responde (o anota algo interno); el colegio responde desde su panel. */
    public function responder(int $ticketId, int $autorId, string $mensaje, bool $interno, bool $esStaff): void
    {
        $t = $this->ticket($ticketId);
        if ($t->estado === 'cerrado') {
            throw ValidationException::withMessages(['mensaje' => ['El ticket está cerrado.']]);
        }
        $this->db()->table('ticket_mensajes')->insert([
            'institucion_id' => $t->institucion_id, 'ticket_id' => $t->id, 'autor_id' => $autorId, 'mensaje' => trim($mensaje), 'interno' => $esStaff && $interno,
        ]);
        if ($esStaff && ! $interno) {
            $cambios = $t->primera_respuesta_at ? [] : ['primera_respuesta_at' => now()];
            if ($t->estado === 'abierto') {
                $cambios['estado'] = 'en_curso';
            }
            if ($cambios) {
                $this->db()->table('tickets_soporte')->where('id', $t->id)->update($cambios);
            }
        }
        // El colegio responde mientras se le esperaba: el reloj vuelve a correr.
        if (! $esStaff && $t->estado === 'esperando') {
            $this->cambiarEstado($t->id, 'en_curso');
        }
    }

    public function cambiarEstado(int $ticketId, string $estado, ?int $asignado = null, ?string $prioridad = null): void
    {
        $t = $this->ticket($ticketId);
        $cambios = ['estado' => $estado];
        if ($asignado !== null) {
            $cambios['asignado_a'] = $asignado ?: null;
        }
        if ($prioridad && $prioridad !== $t->prioridad) {
            // Cambia el compromiso: se recalcula desde la creación con la política nueva.
            $p = $this->db()->table('sla_politicas')->where('prioridad', $prioridad)->first();
            $creado = Carbon::parse($t->created_at);
            $cambios += ['prioridad' => $prioridad, 'sla_politica_id' => $p->id,
                'vence_primera_respuesta_at' => $this->vence($creado, (int) $p->horas_primera_respuesta, (bool) $p->solo_horario_habil)->addMinutes((int) $t->pausado_minutos),
                'vence_resolucion_at' => $this->vence($creado, (int) $p->horas_resolucion, (bool) $p->solo_horario_habil)->addMinutes((int) $t->pausado_minutos),
                'alerta_75_at' => null, 'escalado_at' => null];
        }
        if ($estado === 'esperando' && $t->estado !== 'esperando') {
            $cambios['pausado_desde'] = now();
        }
        if ($t->estado === 'esperando' && $estado !== 'esperando' && $t->pausado_desde) {
            // Se corren los vencimientos lo que duró la pausa (en minutos hábiles).
            $pausa = $this->horario()->minutosEntre(Carbon::parse($t->pausado_desde), now());
            $base = isset($cambios['vence_resolucion_at']) ? $cambios : (array) $t;
            $cambios['pausado_minutos'] = (int) $t->pausado_minutos + $pausa;
            $cambios['pausado_desde'] = null;
            if (! $t->primera_respuesta_at && $base['vence_primera_respuesta_at']) {
                $cambios['vence_primera_respuesta_at'] = $this->horario()->sumar(Carbon::parse($base['vence_primera_respuesta_at']), $pausa);
            }
            if ($base['vence_resolucion_at']) {
                $cambios['vence_resolucion_at'] = $this->horario()->sumar(Carbon::parse($base['vence_resolucion_at']), $pausa);
            }
        }
        if ($estado === 'resuelto' && ! $t->resuelto_at) {
            $cambios['resuelto_at'] = now();
        }
        if ($estado === 'cerrado') {
            $cambios['cerrado_at'] = now();
            $cambios['resuelto_at'] = $t->resuelto_at ?? now();
        }
        if (in_array($estado, ['abierto', 'en_curso'], true) && in_array($t->estado, ['resuelto', 'cerrado'], true)) {
            $cambios += ['resuelto_at' => null, 'cerrado_at' => null]; // Se reabre.
        }
        $this->db()->table('tickets_soporte')->where('id', $t->id)->update($cambios);
    }

    /** EDUN-33 · El colegio califica la atención al cerrar. */
    public function calificar(int $ticketId, int $calificacion, ?string $comentario): void
    {
        $t = $this->ticket($ticketId);
        if (! in_array($t->estado, ['resuelto', 'cerrado'], true)) {
            throw ValidationException::withMessages(['calificacion' => ['Podrás calificar cuando el ticket esté resuelto.']]);
        }
        $this->db()->table('tickets_soporte')->where('id', $t->id)->update(['calificacion' => $calificacion, 'comentario_calificacion' => $comentario]);
        if ($t->estado === 'resuelto') {
            $this->cambiarEstado($t->id, 'cerrado');
        }
    }

    /**
     * Semáforo del compromiso en curso (primera respuesta o resolución).
     *
     * @return array{meta: string|null, color: string, porcentaje: int|null, vence: string|null}
     */
    public function semaforo(object $t): array
    {
        $resuelto = $t->resuelto_at !== null;
        if ($resuelto) {
            $cumplio = $t->vence_resolucion_at === null || Carbon::parse($t->resuelto_at)->lessThanOrEqualTo($t->vence_resolucion_at);

            return ['meta' => 'resolucion', 'color' => $cumplio ? 'cumplido' : 'incumplido', 'porcentaje' => null, 'vence' => $t->vence_resolucion_at];
        }
        $meta = $t->primera_respuesta_at ? 'resolucion' : 'primera_respuesta';
        $vence = $meta === 'resolucion' ? $t->vence_resolucion_at : $t->vence_primera_respuesta_at;
        $politica = $t->sla_politica_id ? $this->db()->table('sla_politicas')->find($t->sla_politica_id) : null;
        if (! $vence || ! $politica) {
            return ['meta' => $meta, 'color' => 'sin_sla', 'porcentaje' => null, 'vence' => $vence];
        }
        $plazo = (int) ($meta === 'resolucion' ? $politica->horas_resolucion : $politica->horas_primera_respuesta) * 60;
        $hasta = $t->pausado_desde ? Carbon::parse($t->pausado_desde) : now();
        $usado = $this->horario()->minutosEntre(Carbon::parse($t->created_at), $hasta) - (int) $t->pausado_minutos;
        $pct = (int) floor(max(0, $usado) * 100 / max(1, $plazo));
        $vencido = $hasta->greaterThan(Carbon::parse($vence));

        return ['meta' => $meta, 'color' => $vencido ? 'rojo' : ($pct >= 75 ? 'ambar' : 'verde'), 'porcentaje' => $vencido ? max(100, $pct) : min(99, $pct), 'vence' => $vence];
    }

    /**
     * EDUN-31 · Alerta al 75 % y escalamiento al vencer (lo corre el programador cada 5 min).
     *
     * @return array{alertas: int, escalados: int}
     */
    public function vigilar(CorreoTransaccional $correo): array
    {
        $r = ['alertas' => 0, 'escalados' => 0];
        $abiertos = $this->db()->table('tickets_soporte as t')->join('instituciones as i', 'i.id', '=', 't.institucion_id')
            ->whereIn('t.estado', ['abierto', 'en_curso'])->get(['t.*', 'i.nombre as colegio']);
        $staff = $this->db()->table('roles_plataforma as rp')->join('users as u', 'u.id', '=', 'rp.user_id')->join('roles as r', 'r.id', '=', 'rp.rol_id')
            ->where('rp.activo', true)->where('u.activo', true)->whereNotNull('u.email')->pluck('u.email')->unique();
        foreach ($abiertos as $t) {
            $s = $this->semaforo($t);
            $tipo = null;
            if ($s['color'] === 'rojo' && ! $t->escalado_at) {
                $this->db()->table('tickets_soporte')->where('id', $t->id)->update(['escalado_at' => now(), 'alerta_75_at' => $t->alerta_75_at ?? now()]);
                $tipo = 'escalado';
                $r['escalados']++;
            } elseif ($s['color'] === 'ambar' && ! $t->alerta_75_at) {
                $this->db()->table('tickets_soporte')->where('id', $t->id)->update(['alerta_75_at' => now()]);
                $tipo = 'alerta';
                $r['alertas']++;
            }
            if ($tipo) {
                foreach ($staff as $email) {
                    $correo->enviar(new AlertaSla($tipo, $t->numero, $t->colegio, $t->asunto, $t->prioridad, $s), $email, 'alerta_sla', (int) $t->institucion_id);
                }
            }
        }

        return $r;
    }

    /** EDUN-32 · Cumplimiento por prioridad, colegio y mes. */
    public function cumplimiento(string $desde, string $hasta): array
    {
        $tickets = $this->db()->table('tickets_soporte as t')->join('instituciones as i', 'i.id', '=', 't.institucion_id')
            ->where('t.created_at', '>=', $desde)->where('t.created_at', '<', Carbon::parse($hasta)->addDay()->toDateString())
            ->get(['t.*', 'i.nombre as colegio']);
        $resumir = function ($grupo) {
            $respondidos = $grupo->whereNotNull('primera_respuesta_at');
            $resueltos = $grupo->whereNotNull('resuelto_at');
            $pct = fn ($c, $ok) => $c->count() ? round($c->filter($ok)->count() * 100 / $c->count(), 1) : null;
            $horas = fn ($c, $campo) => $c->count() ? round($c->avg(fn ($t) => max(0, Carbon::parse($t->created_at)->diffInMinutes(Carbon::parse($t->{$campo})) - ($campo === 'resuelto_at' ? (int) $t->pausado_minutos : 0)) / 60), 1) : null;

            return [
                'tickets' => $grupo->count(),
                'primera_respuesta_a_tiempo' => $pct($respondidos, fn ($t) => ! $t->vence_primera_respuesta_at || $t->primera_respuesta_at <= $t->vence_primera_respuesta_at),
                'resolucion_a_tiempo' => $pct($resueltos, fn ($t) => ! $t->vence_resolucion_at || $t->resuelto_at <= $t->vence_resolucion_at),
                'horas_primera_respuesta' => $horas($respondidos, 'primera_respuesta_at'),
                'horas_resolucion' => $horas($resueltos, 'resuelto_at'),
                'calificacion' => $grupo->whereNotNull('calificacion')->count() ? round($grupo->whereNotNull('calificacion')->avg('calificacion'), 1) : null,
            ];
        };

        return [
            'total' => $resumir($tickets),
            'por_prioridad' => collect(['critica', 'alta', 'media', 'baja'])->map(fn ($p) => ['prioridad' => $p] + $resumir($tickets->where('prioridad', $p)))->values(),
            'por_colegio' => $tickets->groupBy('colegio')->map(fn ($g, $c) => ['colegio' => $c] + $resumir($g))->sortBy('colegio')->values(),
            'por_mes' => $tickets->groupBy(fn ($t) => substr((string) $t->created_at, 0, 7))->map(fn ($g, $m) => ['mes' => $m] + $resumir($g))->sortKeys()->values(),
        ];
    }

    /** Lista de tickets (la consola ve todos; el colegio, solo los suyos por RLS). */
    public function listar(array $f = []): array
    {
        return $this->db()->table('tickets_soporte as t')->join('instituciones as i', 'i.id', '=', 't.institucion_id')
            ->leftJoin('users as a', 'a.id', '=', 't.asignado_a')
            ->when($f['estado'] ?? null, fn ($q, $v) => $v === 'activos' ? $q->whereIn('t.estado', ['abierto', 'en_curso', 'esperando']) : $q->where('t.estado', $v))
            ->when($f['prioridad'] ?? null, fn ($q, $v) => $q->where('t.prioridad', $v))
            ->when($f['institucion_id'] ?? null, fn ($q, $v) => $q->where('t.institucion_id', $v))
            ->orderByRaw("case t.prioridad when 'critica' then 1 when 'alta' then 2 when 'media' then 3 else 4 end")->orderBy('t.created_at')
            ->limit(500)
            ->get(['t.*', 'i.nombre as colegio', DB::raw("a.nombres || ' ' || a.apellidos as asignado")])
            ->map(fn ($t) => self::resumen($t) + ['semaforo' => $this->semaforo($t)])->all();
    }

    public function detalle(int $ticketId, bool $verInternos): array
    {
        $t = $this->ticket($ticketId);
        $colegio = $this->db()->table('instituciones')->where('id', $t->institucion_id)->value('nombre');

        return ['colegio' => $colegio] + self::resumen($t) + [
            'descripcion' => $t->descripcion, 'semaforo' => $this->semaforo($t),
            'mensajes' => $this->db()->table('ticket_mensajes as m')->join('users as u', 'u.id', '=', 'm.autor_id')->where('m.ticket_id', $t->id)
                ->when(! $verInternos, fn ($q) => $q->where('m.interno', false))->orderBy('m.created_at')
                ->get(['m.id', 'm.mensaje', 'm.interno', 'm.created_at', DB::raw("u.nombres || ' ' || u.apellidos as autor"), DB::raw($this->conexion === ConsolaInstituciones::CONEXION
                    ? 'exists (select 1 from roles_plataforma rp where rp.user_id = u.id) as es_staff'
                    : 'exists (select 1 from fn_roles_plataforma(u.id)) as es_staff')])
                ->map(fn ($m) => (array) $m)->all(),
            'adjuntos' => $this->db()->table('ticket_adjuntos as ta')->join('archivos as a', 'a.id', '=', 'ta.archivo_id')->where('ta.ticket_id', $t->id)
                ->get(['a.id', 'a.nombre_original', 'a.bytes'])->map(fn ($a) => (array) $a)->all(),
        ];
    }

    private function ticket(int $id): object
    {
        $t = $this->db()->table('tickets_soporte')->find($id);
        abort_if(! $t, 404);

        return $t;
    }

    private static function resumen(object $t): array
    {
        return [
            'id' => (int) $t->id, 'numero' => $t->numero, 'asunto' => $t->asunto, 'categoria' => $t->categoria, 'prioridad' => $t->prioridad, 'estado' => $t->estado,
            'colegio' => $t->colegio ?? null, 'asignado' => $t->asignado ?? null, 'asignado_a' => $t->asignado_a, 'creado' => (string) $t->created_at,
            'vence_primera_respuesta_at' => $t->vence_primera_respuesta_at, 'primera_respuesta_at' => $t->primera_respuesta_at,
            'vence_resolucion_at' => $t->vence_resolucion_at, 'resuelto_at' => $t->resuelto_at, 'calificacion' => $t->calificacion,
            'comentario_calificacion' => $t->comentario_calificacion, 'escalado' => $t->escalado_at !== null,
        ];
    }
}
