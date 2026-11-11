<?php

namespace App\Modulos\COM\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COM · Comunicados y agenda.
 *
 * - EDUN-60 · Alcance: colegio, nivel, grado, sección o filtro «con deuda»; canales app, WhatsApp
 *   y correo; envío inmediato o programado. Un enviado no se edita: se anula y se envía otro.
 * - EDUN-61 · Porcentaje leído por sección y no leídos con recordatorio.
 * - EDUN-62 · La familia lo recibe por push, lo confirma si se pide y ve el historial por hijo.
 * - EDUN-64 · Eventos de la agenda por nivel o sección con recordatorio automático el día antes.
 */
class Comunicados
{
    public function __construct(private Notificador $notificador, private Auditoria $auditoria) {}

    /** Alumnos (y sus apoderados con cuenta) que entran en el alcance. */
    public function destinatarios(array $alcance): Collection
    {
        $anio = DB::table('anios_escolares')->orderByRaw("estado = 'abierto' desc")->orderByDesc('anio')->value('id');
        $q = DB::table('matriculas as m')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->join('alumno_apoderado as aa', 'aa.alumno_id', '=', 'm.alumno_id')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')
            ->where('m.anio_escolar_id', $anio)->where('m.estado', 'matriculado')->whereNotNull('p.user_id');
        $tipo = $alcance['tipo'] ?? 'colegio';
        $ids = array_map('intval', $alcance['ids'] ?? []);
        match ($tipo) {
            'nivel' => $q->whereIn('g.nivel', $alcance['niveles'] ?? []),
            'grado' => $q->whereIn('g.id', $ids),
            'seccion' => $q->whereIn('s.id', $ids),
            default => null,
        };
        if (! empty($alcance['con_deuda'])) {
            $q->whereExists(fn ($e) => $e->from('cargos as c')->whereColumn('c.alumno_id', 'm.alumno_id')->whereIn('c.estado', ['pendiente', 'parcial'])->where('c.vencimiento', '<', now()->toDateString()));
        }

        return $q->get(['p.user_id', 'm.alumno_id', 's.id as seccion_id'])->unique(fn ($d) => $d->user_id.'-'.$d->alumno_id)->values();
    }

    public function listar(): array
    {
        return DB::table('comunicados as c')->leftJoin('users as u', 'u.id', '=', 'c.autor_id')->orderByDesc('c.id')->limit(200)
            ->get(['c.*', DB::raw("u.nombres || ' ' || u.apellidos as autor"),
                DB::raw('(select count(*) from comunicado_destinatarios d where d.comunicado_id = c.id) as destinatarios'),
                DB::raw('(select count(*) from comunicado_destinatarios d where d.comunicado_id = c.id and d.leido_at is not null) as leidos')])
            ->map(fn ($c) => array_merge((array) $c, ['alcance' => json_decode($c->alcance, true), 'canales' => explode(',', $c->canales)]))->all();
    }

    public function guardar(array $d, int $autorId): int
    {
        $canales = array_values(array_intersect(array_keys(Notificador::CANALES), $d['canales'] ?? ['app'])) ?: ['app'];
        if (! empty($d['programado_para']) && Carbon::parse($d['programado_para'])->isPast()) {
            throw ValidationException::withMessages(['programado_para' => ['La fecha de envío programado debe ser futura.']]);
        }
        if ($this->destinatarios($d['alcance'])->isEmpty()) {
            throw ValidationException::withMessages(['alcance' => ['Nadie recibiría este comunicado: revisa el alcance (las familias deben tener cuenta activa).']]);
        }
        $id = (int) DB::table('comunicados')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'titulo' => trim($d['titulo']), 'contenido' => trim($d['contenido']),
            'alcance' => json_encode($d['alcance']), 'canales' => implode(',', $canales), 'requiere_confirmacion' => (bool) ($d['requiere_confirmacion'] ?? false),
            'programado_para' => $d['programado_para'] ?? null, 'autor_id' => $autorId, 'estado' => ! empty($d['programado_para']) ? 'programado' : 'borrador',
        ]);
        $this->auditoria->registrar('comunicado_creado', 'comunicados', $id, null, ['titulo' => $d['titulo'], 'alcance' => $d['alcance']]);
        if (empty($d['programado_para'])) {
            $this->enviar($id);
        }

        return $id;
    }

    /** Envía (ahora o desde la cola de programados): crea los destinatarios y notifica. */
    public function enviar(int $id): int
    {
        $c = DB::table('comunicados')->find($id);
        if (! $c || in_array($c->estado, ['enviado', 'anulado'], true)) {
            return 0;
        }
        $dest = $this->destinatarios(json_decode($c->alcance, true));
        $inst = ContextoInstitucion::requerido();
        foreach ($dest->chunk(500) as $lote) {
            DB::table('comunicado_destinatarios')->insertOrIgnore($lote->map(fn ($d) => [
                'institucion_id' => $inst, 'comunicado_id' => $id, 'user_id' => $d->user_id, 'alumno_id' => $d->alumno_id, 'entregado_at' => now(),
            ])->all());
        }
        DB::table('comunicados')->where('id', $id)->update(['estado' => 'enviado', 'enviado_at' => now()]);
        $this->notificador->enviar($dest->pluck('user_id')->all(), explode(',', $c->canales), 'comunicados', $c->titulo, mb_strimwidth(strip_tags($c->contenido), 0, 180, '…'), ['pantalla' => 'comunicados', 'id' => $id]);

        return $dest->count();
    }

    public function enviarProgramados(): int
    {
        $n = 0;
        foreach (DB::table('comunicados')->where('estado', 'programado')->where('programado_para', '<=', now())->pluck('id') as $id) {
            $n += $this->enviar((int) $id);
        }

        return $n;
    }

    public function anular(int $id, string $motivo): void
    {
        $c = DB::table('comunicados')->find($id);
        abort_if(! $c, 404);
        if ($c->estado === 'anulado') {
            throw ValidationException::withMessages(['comunicado' => ['Ya estaba anulado.']]);
        }
        DB::table('comunicados')->where('id', $id)->update(['estado' => 'anulado', 'motivo_anulacion' => $motivo]);
        $this->auditoria->registrar('comunicado_anulado', 'comunicados', $id, ['estado' => $c->estado], ['estado' => 'anulado', 'motivo' => $motivo]);
    }

    /** EDUN-61 · Lectura por sección y lista de no leídos. */
    public function lectura(int $id): array
    {
        abort_if(! DB::table('comunicados')->where('id', $id)->exists(), 404);
        $filas = DB::table('comunicado_destinatarios as d')->join('alumnos as a', 'a.id', '=', 'd.alumno_id')->join('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('matriculas as m', fn ($j) => $j->on('m.alumno_id', '=', 'd.alumno_id')->where('m.estado', '=', 'matriculado'))
            ->leftJoin('secciones as s', 's.id', '=', 'm.seccion_id')->leftJoin('grados as g', 'g.id', '=', 's.grado_id')->where('d.comunicado_id', $id)
            ->get(['d.id', 'd.leido_at', 'd.confirmado_at', DB::raw("g.nombre || ' ' || s.nombre as seccion"), DB::raw("a.nombres || ' ' || a.apellido_paterno as alumno"), DB::raw("u.nombres || ' ' || u.apellidos as apoderado")])
            ->unique('id');

        return [
            'por_seccion' => $filas->groupBy('seccion')->map(fn ($g, $s) => ['seccion' => $s ?: '—', 'total' => $g->count(), 'leidos' => $g->whereNotNull('leido_at')->count(),
                'confirmados' => $g->whereNotNull('confirmado_at')->count(), 'porcentaje' => (int) floor($g->whereNotNull('leido_at')->count() * 100 / max(1, $g->count()))])->values()->all(),
            'no_leidos' => $filas->whereNull('leido_at')->values()->map(fn ($f) => (array) $f)->all(),
        ];
    }

    public function recordar(int $id): int
    {
        $c = DB::table('comunicados')->find($id);
        abort_if(! $c || $c->estado !== 'enviado', 404);
        $usuarios = DB::table('comunicado_destinatarios')->where('comunicado_id', $id)->whereNull('leido_at')->pluck('user_id')->unique()->all();
        $this->notificador->enviar($usuarios, ['app'], 'comunicados', "Recordatorio: {$c->titulo}", 'Tienes un comunicado sin leer.', ['pantalla' => 'comunicados', 'id' => $id]);

        return count($usuarios);
    }

    /** EDUN-62 · Comunicados de la familia, por hijo. */
    public function deFamilia(int $usuarioId): array
    {
        return DB::table('comunicado_destinatarios as d')->join('comunicados as c', 'c.id', '=', 'd.comunicado_id')->join('alumnos as a', 'a.id', '=', 'd.alumno_id')
            ->where('d.user_id', $usuarioId)->where('c.estado', 'enviado')->orderByDesc('c.enviado_at')->limit(200)
            ->get(['d.id', 'c.id as comunicado_id', 'c.titulo', 'c.contenido', 'c.enviado_at', 'c.requiere_confirmacion', 'd.leido_at', 'd.confirmado_at', 'a.id as alumno_id', 'a.nombres as alumno'])
            ->map(fn ($c) => (array) $c)->all();
    }

    public function marcar(int $destinatarioId, int $usuarioId, bool $confirmar): void
    {
        $d = DB::table('comunicado_destinatarios')->where('id', $destinatarioId)->where('user_id', $usuarioId)->first();
        abort_if(! $d, 404);
        $cambios = ['leido_at' => $d->leido_at ?? now()];
        if ($confirmar) {
            $cambios['confirmado_at'] = $d->confirmado_at ?? now();
        }
        DB::table('comunicado_destinatarios')->where('id', $destinatarioId)->update($cambios);
    }

    // ── EDUN-64 · Agenda

    public function eventos(?array $secciones = null): array
    {
        $niveles = $secciones ? DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->whereIn('s.id', $secciones)->pluck('g.nivel')->unique()->all() : null;

        return DB::table('eventos_calendario')->where('inicio', '>=', now()->subMonths(1))->orderBy('inicio')->limit(300)->get()
            ->map(fn ($e) => array_merge((array) $e, ['alcance' => json_decode($e->alcance, true) ?: []]))
            ->filter(function ($e) use ($secciones, $niveles) {
                if ($secciones === null) {
                    return true;
                }
                $a = $e['alcance'];

                return empty($a['niveles']) && empty($a['secciones'])
                    || array_intersect($a['secciones'] ?? [], $secciones) || array_intersect($a['niveles'] ?? [], $niveles ?? []);
            })->values()->all();
    }

    public function guardarEvento(array $d, ?int $id = null): int
    {
        $fila = [
            'titulo' => trim($d['titulo']), 'descripcion' => $d['descripcion'] ?? null, 'inicio' => $d['inicio'], 'fin' => $d['fin'] ?? null,
            'alcance' => json_encode(['niveles' => $d['niveles'] ?? [], 'secciones' => array_map('intval', $d['secciones'] ?? [])]), 'tipo' => $d['tipo'] ?? 'evento',
        ];
        if ($id) {
            DB::table('eventos_calendario')->where('id', $id)->update($fila + ['recordatorio_enviado_at' => null]);

            return $id;
        }

        return (int) DB::table('eventos_calendario')->insertGetId($fila + ['institucion_id' => ContextoInstitucion::requerido()]);
    }

    /** Recordatorio automático un día antes a las familias del alcance. */
    public function recordarEventos(): int
    {
        $n = 0;
        foreach (DB::table('eventos_calendario')->whereNull('recordatorio_enviado_at')->whereBetween('inicio', [now(), now()->addDay()])->get() as $e) {
            $a = json_decode($e->alcance, true) ?: [];
            $alcance = ! empty($a['secciones']) ? ['tipo' => 'seccion', 'ids' => $a['secciones']] : (! empty($a['niveles']) ? ['tipo' => 'nivel', 'niveles' => $a['niveles']] : ['tipo' => 'colegio']);
            $usuarios = $this->destinatarios($alcance)->pluck('user_id')->unique()->all();
            $this->notificador->enviar($usuarios, ['app'], 'comunicados', "Mañana: {$e->titulo}", Carbon::parse($e->inicio)->timezone(config('app.timezone'))->locale('es')->translatedFormat('l j \d\e F, H:i'), ['pantalla' => 'agenda', 'id' => $e->id]);
            DB::table('eventos_calendario')->where('id', $e->id)->update(['recordatorio_enviado_at' => now()]);
            $n++;
        }

        return $n;
    }
}
