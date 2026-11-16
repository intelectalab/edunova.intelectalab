<?php

namespace App\Modulos\MOV\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-161 / EDUN-162 (base de EDUN-163) · Configuración de la movilidad escolar en la web:
 * unidades (placa, capacidad y vencimientos), personal (conductores y auxiliares, enlazados a su
 * usuario para la app), rutas por turno, paraderos en orden y alumnos asignados a cada paradero.
 */
class ConfiguracionMovilidad
{
    public const TURNOS = ['manana', 'tarde'];

    public function __construct(private Auditoria $auditoria) {}

    public function resumen(): array
    {
        $hoy = now()->toDateString();
        $anio = $this->anioVigente();

        return [
            'unidades' => DB::table('mov_unidades')->orderBy('placa')->get()->map(fn ($u) => (array) $u + [
                'por_vencer' => collect(['soat_vence' => 'SOAT', 'autorizacion_vence' => 'Autorización', 'revision_tecnica_vence' => 'Revisión técnica'])
                    ->filter(fn ($_, $c) => $u->{$c} && $u->{$c} <= now()->addDays(30)->toDateString())->map(fn ($n, $c) => $u->{$c} < $hoy ? "{$n} vencido" : "{$n} por vencer")->values()->all(),
            ])->all(),
            'personal' => DB::table('mov_conductores as c')->leftJoin('users as u', 'u.id', '=', 'c.user_id')->orderBy('c.nombres')
                ->get(['c.id', 'c.nombres', 'c.numero_documento', 'c.rol', 'c.licencia', 'c.licencia_vence', 'c.user_id', 'u.email'])->map(fn ($c) => (array) $c)->all(),
            'rutas' => DB::table('mov_rutas as r')->leftJoin('mov_unidades as u', 'u.id', '=', 'r.mov_unidad_id')->orderBy('r.turno')->orderBy('r.nombre')
                ->get(['r.id', 'r.nombre', 'r.turno', 'r.mov_unidad_id', 'r.conductor_id', 'r.auxiliar_id', 'u.placa', 'u.capacidad'])
                ->map(fn ($r) => (array) $r + [
                    'paraderos' => DB::table('mov_paraderos')->where('mov_ruta_id', $r->id)->orderBy('orden')->get(['id', 'nombre', 'orden', 'hora_estimada', 'latitud', 'longitud'])->map(fn ($p) => (array) $p)->all(),
                    'alumnos' => $anio ? DB::table('mov_asignaciones')->where('mov_ruta_id', $r->id)->where('anio_escolar_id', $anio)->where('activa', true)->count() : 0,
                ])->all(),
            // Usuarios del colegio que pueden usar la app de movilidad (para enlazar al personal).
            'usuarios' => DB::table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.institucion_id', ContextoInstitucion::requerido())->whereIn('r.codigo', ['conductor', 'movilidad'])->distinct()
                ->orderBy('nombre')->get(['u.id', DB::raw("u.nombres || ' ' || u.apellidos as nombre"), 'u.email'])->map(fn ($u) => (array) $u)->all(),
            'turnos' => self::TURNOS,
        ];
    }

    public function guardarUnidad(?int $id, array $d): int
    {
        $placa = strtoupper(preg_replace('/\s+/', '', $d['placa']));
        if (DB::table('mov_unidades')->where('placa', $placa)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            throw ValidationException::withMessages(['placa' => ['Ya hay una unidad con esa placa.']]);
        }
        $fila = ['placa' => $placa, 'capacidad' => (int) $d['capacidad'], 'soat_vence' => $d['soat_vence'] ?? null, 'autorizacion_vence' => $d['autorizacion_vence'] ?? null,
            'revision_tecnica_vence' => $d['revision_tecnica_vence'] ?? null, 'propia' => (bool) ($d['propia'] ?? false), 'empresa' => $d['empresa'] ?? null];

        return $this->guardar('mov_unidades', $id, $fila, 'movilidad_unidad');
    }

    public function eliminarUnidad(int $id): void
    {
        abort_if(! DB::table('mov_unidades')->where('id', $id)->exists(), 404);
        if (DB::table('mov_rutas')->where('mov_unidad_id', $id)->exists()) {
            throw ValidationException::withMessages(['unidad' => ['La unidad está asignada a una ruta: quítala de la ruta primero.']]);
        }
        DB::table('mov_unidades')->where('id', $id)->delete();
        $this->auditoria->registrar('movilidad_unidad', 'mov_unidades', $id, ['id' => $id], null);
    }

    public function guardarPersonal(?int $id, array $d): int
    {
        if (! empty($d['user_id']) && ! DB::table('membresias')->where('institucion_id', ContextoInstitucion::requerido())->where('user_id', $d['user_id'])->exists()) {
            throw ValidationException::withMessages(['user_id' => ['Elige un usuario del colegio.']]);
        }
        if ($d['rol'] === 'conductor' && empty($d['licencia'])) {
            throw ValidationException::withMessages(['licencia' => ['El conductor necesita su número de licencia.']]);
        }
        $fila = ['nombres' => trim($d['nombres']), 'numero_documento' => strtoupper(trim($d['numero_documento'])), 'rol' => $d['rol'],
            'licencia' => $d['licencia'] ?? null, 'licencia_vence' => $d['licencia_vence'] ?? null, 'user_id' => $d['user_id'] ?? null];

        return $this->guardar('mov_conductores', $id, $fila, 'movilidad_personal');
    }

    public function guardarRuta(?int $id, array $d): int
    {
        foreach (['conductor_id' => 'conductor', 'auxiliar_id' => 'auxiliar'] as $campo => $rol) {
            if (! empty($d[$campo]) && ! DB::table('mov_conductores')->where('id', $d[$campo])->where('rol', $rol)->exists()) {
                throw ValidationException::withMessages([$campo => ["Elige un {$rol} registrado en Personal."]]);
            }
        }
        if (! empty($d['mov_unidad_id']) && ! DB::table('mov_unidades')->where('id', $d['mov_unidad_id'])->exists()) {
            throw ValidationException::withMessages(['mov_unidad_id' => ['Elige una unidad registrada.']]);
        }
        $fila = ['nombre' => trim($d['nombre']), 'turno' => $d['turno'], 'mov_unidad_id' => $d['mov_unidad_id'] ?? null,
            'conductor_id' => $d['conductor_id'] ?? null, 'auxiliar_id' => $d['auxiliar_id'] ?? null];

        return $this->guardar('mov_rutas', $id, $fila, 'movilidad_ruta');
    }

    /**
     * Reemplaza los paraderos de la ruta en el orden recibido. Los que tienen id se actualizan; uno
     * que se quita y todavía tiene alumnos asignados no se puede borrar.
     *
     * @param  list<array{id?: int|null, nombre: string, hora_estimada?: string|null, latitud?: float|null, longitud?: float|null}>  $paraderos
     */
    public function guardarParaderos(int $rutaId, array $paraderos): array
    {
        abort_if(! DB::table('mov_rutas')->where('id', $rutaId)->exists(), 404);
        $actuales = DB::table('mov_paraderos')->where('mov_ruta_id', $rutaId)->pluck('id')->all();
        $quedan = collect($paraderos)->pluck('id')->filter()->map(fn ($v) => (int) $v)->all();
        if (array_diff($quedan, $actuales)) {
            throw ValidationException::withMessages(['paraderos' => ['Hay un paradero que no es de esta ruta.']]);
        }
        $quitar = array_diff($actuales, $quedan);
        if ($quitar && DB::table('mov_asignaciones')->whereIn('mov_paradero_id', $quitar)->where('activa', true)->exists()) {
            throw ValidationException::withMessages(['paraderos' => ['Un paradero que quitaste tiene alumnos asignados: muévelos a otro paradero primero.']]);
        }
        DB::table('mov_asignaciones')->whereIn('mov_paradero_id', $quitar)->delete();
        DB::table('mov_paraderos')->whereIn('id', $quitar)->delete();
        foreach (array_values($paraderos) as $i => $p) {
            $fila = ['nombre' => trim($p['nombre']), 'orden' => $i + 1, 'hora_estimada' => $p['hora_estimada'] ?? null, 'latitud' => $p['latitud'] ?? null, 'longitud' => $p['longitud'] ?? null];
            if (! empty($p['id'])) {
                DB::table('mov_paraderos')->where('id', $p['id'])->update($fila);
            } else {
                DB::table('mov_paraderos')->insert($fila + ['institucion_id' => ContextoInstitucion::requerido(), 'mov_ruta_id' => $rutaId]);
            }
        }
        $this->auditoria->registrar('movilidad_paraderos', 'mov_rutas', $rutaId, ['paraderos' => count($actuales)], ['paraderos' => count($paraderos)]);

        return DB::table('mov_paraderos')->where('mov_ruta_id', $rutaId)->orderBy('orden')->get()->map(fn ($p) => (array) $p)->all();
    }

    /** Alumnos de la ruta en el año vigente, con su paradero. */
    public function alumnosDeRuta(int $rutaId): array
    {
        abort_if(! DB::table('mov_rutas')->where('id', $rutaId)->exists(), 404);

        return DB::table('mov_asignaciones as a')->join('alumnos as al', 'al.id', '=', 'a.alumno_id')->join('mov_paraderos as p', 'p.id', '=', 'a.mov_paradero_id')
            ->where('a.mov_ruta_id', $rutaId)->where('a.anio_escolar_id', $this->anioVigente())->where('a.activa', true)->orderBy('p.orden')->orderBy('al.apellido_paterno')
            ->get(['a.alumno_id', 'a.mov_paradero_id', DB::raw("al.apellido_paterno || ' ' || coalesce(al.apellido_materno, '') || ', ' || al.nombres as alumno"), 'p.nombre as paradero'])
            ->map(fn ($x) => (array) $x)->all();
    }

    /**
     * Fija los alumnos de la ruta (año vigente). Los que salen quedan inactivos (se conserva su
     * historial de subidas y bajadas). No se pasa la capacidad de la unidad.
     *
     * @param  list<array{alumno_id: int, paradero_id: int}>  $asignaciones
     */
    public function guardarAlumnos(int $rutaId, array $asignaciones): array
    {
        $ruta = DB::table('mov_rutas as r')->leftJoin('mov_unidades as u', 'u.id', '=', 'r.mov_unidad_id')->where('r.id', $rutaId)->first(['r.id', 'u.capacidad']);
        abort_if(! $ruta, 404);
        $anio = $this->anioVigente();
        if (! $anio) {
            throw ValidationException::withMessages(['alumnos' => ['No hay un año escolar abierto.']]);
        }
        if ($ruta->capacidad && count($asignaciones) > (int) $ruta->capacidad) {
            throw ValidationException::withMessages(['alumnos' => ["La unidad tiene capacidad para {$ruta->capacidad} alumnos."]]);
        }
        $paraderos = DB::table('mov_paraderos')->where('mov_ruta_id', $rutaId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $alumnos = collect($asignaciones)->pluck('alumno_id')->map(fn ($v) => (int) $v);
        if ($alumnos->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['alumnos' => ['Un alumno está dos veces en la ruta.']]);
        }
        $validos = DB::table('matriculas')->where('anio_escolar_id', $anio)->where('estado', 'matriculado')->whereIn('alumno_id', $alumnos)->pluck('alumno_id')->map(fn ($v) => (int) $v);
        foreach ($asignaciones as $a) {
            if (! in_array((int) $a['paradero_id'], $paraderos, true)) {
                throw ValidationException::withMessages(['alumnos' => ['Elige un paradero de esta ruta para cada alumno.']]);
            }
            if (! $validos->contains((int) $a['alumno_id'])) {
                throw ValidationException::withMessages(['alumnos' => ['Solo se asignan alumnos matriculados en el año vigente.']]);
            }
        }
        DB::table('mov_asignaciones')->where('mov_ruta_id', $rutaId)->where('anio_escolar_id', $anio)->whereNotIn('alumno_id', $alumnos)->update(['activa' => false]);
        foreach ($asignaciones as $a) {
            DB::table('mov_asignaciones')->upsert([['institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => (int) $a['alumno_id'], 'mov_ruta_id' => $rutaId,
                'mov_paradero_id' => (int) $a['paradero_id'], 'anio_escolar_id' => $anio, 'activa' => true]], ['alumno_id', 'anio_escolar_id', 'mov_ruta_id'], ['mov_paradero_id', 'activa']);
        }
        $this->auditoria->registrar('movilidad_alumnos', 'mov_rutas', $rutaId, null, ['alumnos' => count($asignaciones)]);

        return $this->alumnosDeRuta($rutaId);
    }

    /** Busca alumnos matriculados en el año vigente (para asignarlos a una ruta). */
    public function buscarAlumnos(string $texto): array
    {
        $t = '%'.mb_strtolower(trim($texto)).'%';

        return DB::table('alumnos as a')->join('matriculas as m', 'm.alumno_id', '=', 'a.id')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->where('m.anio_escolar_id', $this->anioVigente())->where('m.estado', 'matriculado')->whereNull('a.eliminado_at')
            ->where(fn ($q) => $q->whereRaw("lower(a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ' ' || a.nombres) like ?", [$t])->orWhere('a.numero_documento', trim($texto)))
            ->orderBy('a.apellido_paterno')->limit(15)
            ->get(['a.id', DB::raw("a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ', ' || a.nombres as alumno"), DB::raw("g.nombre || ' ' || s.nombre as seccion")])
            ->map(fn ($x) => (array) $x)->all();
    }

    private function anioVigente(): ?int
    {
        $id = DB::table('anios_escolares')->where('estado', 'abierto')->orderByDesc('anio')->value('id');

        return $id ? (int) $id : null;
    }

    private function guardar(string $tabla, ?int $id, array $fila, string $accion): int
    {
        if ($id) {
            $antes = DB::table($tabla)->find($id);
            abort_if(! $antes, 404);
            DB::table($tabla)->where('id', $id)->update($fila);
            $this->auditoria->registrar($accion, $tabla, $id, array_intersect_key((array) $antes, $fila), $fila);

            return $id;
        }
        $nuevo = (int) DB::table($tabla)->insertGetId($fila + ['institucion_id' => ContextoInstitucion::requerido()]);
        $this->auditoria->registrar($accion, $tabla, $nuevo, null, $fila);

        return $nuevo;
    }
}
