<?php

namespace App\Modulos\SAL\Services;

use App\Models\User;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-177 / 178 · Ficha de salud y campañas (datos sensibles, Ley 29733).
 *
 * - Los datos viven en el esquema «salud»: solo se abren con app.acceso_salud dentro de la
 *   transacción y cada consulta o cambio queda en la auditoría.
 * - Ficha (SAL-H02): alergias, condiciones, medicación, seguro, grupo sanguíneo y contacto de
 *   emergencia; la mantiene enfermería y la familia la actualiza desde la app.
 * - Campañas (SAL-H03): vacunación o despistaje para una o varias secciones, con autorización que
 *   firman los padres en la app y la lista de autorizados para el día de la campaña.
 */
class Salud
{
    public const CAMPOS = ['grupo_sanguineo', 'seguro', 'alergias', 'condiciones', 'medicacion', 'contacto_emergencia', 'telefono_emergencia'];

    public function __construct(private Auditoria $auditoria, private Notificador $notificador) {}

    /** Ejecuta $fn con el esquema de salud abierto solo en esta transacción. */
    public static function conAcceso(callable $fn): mixed
    {
        return DB::transaction(function () use ($fn) {
            DB::select("select set_config('app.acceso_salud', 'on', true)");
            try {
                return $fn();
            } finally {
                DB::select("select set_config('app.acceso_salud', 'off', true)");
            }
        });
    }

    public function ficha(int $alumnoId, User $usuario, string $quien): array
    {
        abort_if(! DB::table('alumnos')->where('id', $alumnoId)->whereNull('eliminado_at')->exists(), 404);
        $f = self::conAcceso(fn () => DB::table('salud.fichas')->where('alumno_id', $alumnoId)->first([...self::CAMPOS, 'updated_at', 'actualizado_por']));
        $this->auditoria->registrar('salud_consultada', 'salud.fichas', $alumnoId, null, ['alumno_id' => $alumnoId, 'por' => $quien]);
        $a = DB::table('alumnos')->find($alumnoId, ['nombres', 'apellido_paterno']);

        return ['alumno_id' => $alumnoId, 'alumno' => "{$a->apellido_paterno}, {$a->nombres}", 'ficha' => $f ? (array) $f : null];
    }

    public function guardarFicha(int $alumnoId, array $d, User $usuario, string $quien): array
    {
        abort_if(! DB::table('alumnos')->where('id', $alumnoId)->whereNull('eliminado_at')->exists(), 404);
        $fila = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, array_intersect_key($d, array_flip(self::CAMPOS)));
        if (isset($fila['telefono_emergencia']) && ! preg_match('/^\+?\d{6,15}$/', (string) $fila['telefono_emergencia'])) {
            throw ValidationException::withMessages(['telefono_emergencia' => ['Escribe un teléfono válido (solo números).']]);
        }
        self::conAcceso(function () use ($alumnoId, $fila, $usuario) {
            $existe = DB::table('salud.fichas')->where('alumno_id', $alumnoId)->exists();
            if ($existe) {
                DB::table('salud.fichas')->where('alumno_id', $alumnoId)->update($fila + ['actualizado_por' => $usuario->id]);
            } else {
                DB::table('salud.fichas')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'actualizado_por' => $usuario->id] + $fila);
            }
        });
        $this->auditoria->registrar('salud_actualizada', 'salud.fichas', $alumnoId, null, ['alumno_id' => $alumnoId, 'campos' => array_keys($fila), 'por' => $quien]);

        return $this->ficha($alumnoId, $usuario, $quien);
    }

    /** Alumnos con ficha (o buscados por nombre o documento) para la lista de enfermería. */
    public function fichas(?string $buscar): array
    {
        $con = self::conAcceso(fn () => DB::table('salud.fichas')->get(['alumno_id', 'alergias', 'condiciones', 'medicacion', 'updated_at'])->keyBy('alumno_id'));
        $q = DB::table('alumnos as a')->whereNull('a.eliminado_at')->where('a.estado', 'activo');
        if ($buscar && trim($buscar) !== '') {
            $t = '%'.mb_strtolower(trim($buscar)).'%';
            $q->where(fn ($w) => $w->whereRaw("lower(a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ' ' || a.nombres) like ?", [$t])->orWhere('a.numero_documento', trim($buscar)));
        } else {
            $q->whereIn('a.id', $con->keys()->all() ?: [0]);
        }

        return $q->orderBy('a.apellido_paterno')->limit(100)->get(['a.id', 'a.nombres', 'a.apellido_paterno', 'a.apellido_materno'])->map(fn ($a) => [
            'alumno_id' => (int) $a->id, 'alumno' => trim("{$a->apellido_paterno} {$a->apellido_materno}, {$a->nombres}"),
            'tiene_ficha' => isset($con[$a->id]), 'alertas' => isset($con[$a->id]) && ($con[$a->id]->alergias || $con[$a->id]->condiciones || $con[$a->id]->medicacion),
            'actualizada_at' => $con[$a->id]->updated_at ?? null,
        ])->all();
    }

    /** Atenciones del tópico (de un día o de un alumno). */
    public function atenciones(?string $fecha, ?int $alumnoId): array
    {
        return self::conAcceso(fn () => DB::table('salud.atenciones as t')->join('alumnos as a', 'a.id', '=', 't.alumno_id')->leftJoin('users as u', 'u.id', '=', 't.registrado_por')
            ->when($alumnoId, fn ($q, $id) => $q->where('t.alumno_id', $id), fn ($q) => $q->whereRaw('(t.atendido_at at time zone ?)::date = ?', [config('app.timezone'), $fecha ?? now()->toDateString()]))
            ->orderByDesc('t.atendido_at')->limit(200)
            ->get(['t.id', 't.atendido_at', 't.motivo', 't.accion', 't.derivado', 't.retirado_por', 't.padre_notificado_at', DB::raw("a.apellido_paterno || ', ' || a.nombres as alumno"),
                DB::raw("trim(coalesce(u.nombres, '') || ' ' || coalesce(u.apellidos, '')) as registrado_por")])
            ->map(fn ($x) => (array) $x)->all());
    }

    // ───────────── Campañas (SAL-H03)

    public function campanas(): array
    {
        $lista = self::conAcceso(fn () => DB::table('salud.campanas')->orderByDesc('fecha')->get(['id', 'nombre', 'fecha', 'descripcion', 'alcance', 'autorizacion_id']));

        return $lista->map(function ($c) {
            $secciones = json_decode((string) $c->alcance, true)['secciones'] ?? [];
            $total = DB::table('matriculas')->whereIn('seccion_id', $secciones)->where('estado', 'matriculado')->count();
            $f = $c->autorizacion_id ? DB::table('autorizacion_firmas')->where('autorizacion_id', $c->autorizacion_id)->selectRaw('count(*) filter (where acepta) as si, count(*) filter (where not acepta) as no')->first() : null;

            return ['id' => (int) $c->id, 'nombre' => $c->nombre, 'fecha' => $c->fecha, 'descripcion' => $c->descripcion, 'total' => $total,
                'autorizados' => (int) ($f->si ?? 0), 'no_autorizan' => (int) ($f->no ?? 0), 'pendientes' => max(0, $total - (int) ($f->si ?? 0) - (int) ($f->no ?? 0)),
                'secciones' => DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->whereIn('s.id', $secciones)->get([DB::raw("g.nombre || ' ' || s.nombre as nombre")])->pluck('nombre')->all()];
        })->all();
    }

    /**
     * Crea la campaña y su autorización (la familia la firma en la app) y avisa a las familias.
     *
     * @param  list<int>  $secciones
     */
    public function crearCampana(string $nombre, string $fecha, string $descripcion, array $secciones, User $usuario): array
    {
        if ($fecha < now()->toDateString()) {
            throw ValidationException::withMessages(['fecha' => ['La fecha de la campaña no puede ser pasada.']]);
        }
        $secciones = array_values(array_unique(array_map('intval', $secciones)));
        if (DB::table('secciones')->whereIn('id', $secciones)->count() !== count($secciones)) {
            throw ValidationException::withMessages(['secciones' => ['Alguna sección no existe.']]);
        }
        $inst = ContextoInstitucion::requerido();
        $autorizacion = (int) DB::table('autorizaciones')->insertGetId([
            'institucion_id' => $inst, 'titulo' => mb_substr("Campaña de salud: {$nombre}", 0, 150), 'contenido' => trim($descripcion)."\n\nFecha: ".date('d/m/Y', strtotime($fecha)).'.',
            'alcance' => json_encode(['secciones' => $secciones]), 'vence' => $fecha, 'creado_por' => $usuario->id,
        ]);
        $id = self::conAcceso(fn () => (int) DB::table('salud.campanas')->insertGetId(['institucion_id' => $inst, 'nombre' => trim($nombre), 'fecha' => $fecha, 'descripcion' => trim($descripcion),
            'alcance' => json_encode(['secciones' => $secciones]), 'autorizacion_id' => $autorizacion, 'creado_por' => $usuario->id]));
        $this->auditoria->registrar('campana_salud_creada', 'salud.campanas', $id, null, ['nombre' => trim($nombre), 'fecha' => $fecha, 'secciones' => $secciones]);
        $alumnos = DB::table('matriculas')->whereIn('seccion_id', $secciones)->where('estado', 'matriculado')->pluck('alumno_id');
        $familias = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->whereIn('aa.alumno_id', $alumnos)->whereNotNull('p.user_id')->pluck('p.user_id')->unique()->all();
        $this->notificador->enviar($familias, ['app'], 'comunicados', "Campaña de salud: {$nombre}", 'Autoriza o no la participación de tu hijo en la app.', ['pantalla' => 'autorizaciones', 'id' => $autorizacion]);

        return collect($this->campanas())->firstWhere('id', $id);
    }

    /** Lista de alumnos con su autorización para el día de la campaña. */
    public function autorizados(int $campanaId): array
    {
        $c = self::conAcceso(fn () => DB::table('salud.campanas')->find($campanaId));
        abort_if(! $c, 404);
        $secciones = json_decode((string) $c->alcance, true)['secciones'] ?? [];
        $firmas = DB::table('autorizacion_firmas')->where('autorizacion_id', $c->autorizacion_id)->pluck('acepta', 'alumno_id');

        return DB::table('matriculas as m')->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->whereIn('m.seccion_id', $secciones)->where('m.estado', 'matriculado')->orderBy('g.numero')->orderBy('s.nombre')->orderBy('a.apellido_paterno')
            ->get(['a.id', DB::raw("a.apellido_paterno || ', ' || a.nombres as alumno"), DB::raw("g.nombre || ' ' || s.nombre as seccion")])
            ->map(fn ($a) => ['alumno_id' => (int) $a->id, 'alumno' => $a->alumno, 'seccion' => $a->seccion,
                'estado' => isset($firmas[$a->id]) ? ($firmas[$a->id] ? 'autorizado' : 'no_autoriza') : 'pendiente'])->all();
    }
}
