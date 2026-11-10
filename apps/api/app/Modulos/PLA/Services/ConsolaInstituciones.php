<?php

namespace App\Modulos\PLA\Services;

use App\Models\User;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SEG\Services\Invitaciones;
use App\Modulos\SEG\Services\UsuariosColegio;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-24 / EDUN-25 / EDUN-26 · Consola de la plataforma: alta de colegios, módulos y aula.
 *
 * Usa la conexión «plataforma» (rol edunova_plataforma, sin RLS): solo la consola, solo staff
 * con segundo factor (AccesoPlataforma). Cada cambio queda en la auditoría del colegio.
 */
class ConsolaInstituciones
{
    public const CONEXION = 'plataforma';

    /** Subdominios que no se entregan a un colegio. */
    public const RESERVADOS = ['www', 'app', 'api', 'admin', 'blog', 'cms', 'panel', 'plataforma', 'soporte', 'ayuda', 'correo', 'mail', 'static', 'cdn', 'demo', 'edunova', 'staging', 'test'];

    /** Módulos que la consola no apaga (sin Administración no funciona nada). */
    public const FIJOS = ['ADM'];

    public function __construct(private Invitaciones $invitaciones, private Auditoria $auditoria) {}

    private function db(): Connection
    {
        return DB::connection(self::CONEXION);
    }

    public function catalogos(): array
    {
        return [
            'planes' => $this->db()->table('planes')->where('activo', true)->orderBy('alumnos_min')->get(['codigo', 'nombre', 'alumnos_min', 'alumnos_max']),
            'modulos' => $this->db()->table('modulos_catalogo')->orderBy('id')->get(['codigo', 'nombre', 'tipo']),
            'dominio' => config('edunova.dominio_colegios'),
        ];
    }

    /** @param  array{buscar?: string|null, estado?: string|null}  $filtros */
    public function listar(array $filtros): array
    {
        $db = $this->db();
        $filas = $db->table('instituciones as i')
            ->when($filtros['buscar'] ?? null, function ($q, $v) {
                $t = '%'.mb_strtolower(trim($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(i.nombre) like ?', [$t])->orWhere('i.slug', 'like', $t)->orWhere('i.codigo_modular', 'like', $t)->orWhere('i.ruc', 'like', $t));
            })
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('i.estado', $v))
            ->orderBy('i.nombre')
            ->get(['i.id', 'i.nombre', 'i.slug', 'i.codigo_modular', 'i.nivel_educativo', 'i.estado', 'i.created_at']);

        $ids = $filas->pluck('id')->all();
        $suscripciones = $db->table('suscripciones as s')->join('planes as p', 'p.id', '=', 's.plan_id')
            ->whereIn('s.institucion_id', $ids)->whereIn('s.estado', ['prueba', 'activa'])
            ->orderBy('s.fecha_inicio')->get(['s.id', 's.institucion_id', 's.aula_opcion', 's.estado', 'p.codigo as plan', 'p.nombre as plan_nombre'])
            ->keyBy('institucion_id');
        $extras = $db->table('suscripcion_modulos as sm')->join('modulos_catalogo as m', 'm.id', '=', 'sm.modulo_id')
            ->whereIn('sm.suscripcion_id', $suscripciones->pluck('id')->all())->where('sm.activo', true)->where('m.tipo', 'extra')
            ->get(['sm.suscripcion_id', 'm.codigo'])->groupBy('suscripcion_id');
        $alumnos = $db->table('matriculas as mt')->join('anios_escolares as a', 'a.id', '=', 'mt.anio_escolar_id')
            ->whereIn('mt.institucion_id', $ids)->where('a.estado', 'abierto')->where('mt.estado', 'matriculado')
            ->groupBy('mt.institucion_id')->select('mt.institucion_id', DB::raw('count(*) as n'))->pluck('n', 'institucion_id');

        return $filas->map(function ($i) use ($suscripciones, $extras, $alumnos) {
            $s = $suscripciones[$i->id] ?? null;

            return [
                'id' => (int) $i->id, 'nombre' => $i->nombre, 'slug' => $i->slug, 'codigo_modular' => $i->codigo_modular,
                'nivel_educativo' => $i->nivel_educativo, 'estado' => $i->estado, 'creado' => $i->created_at,
                'plan' => $s?->plan, 'plan_nombre' => $s?->plan_nombre, 'aula_opcion' => $s?->aula_opcion, 'suscripcion_estado' => $s?->estado,
                'modulos_extra' => $s ? ($extras[$s->id] ?? collect())->pluck('codigo')->values()->all() : [],
                'alumnos_activos' => (int) ($alumnos[$i->id] ?? 0),
            ];
        })->all();
    }

    public function detalle(int $id): array
    {
        $db = $this->db();
        $i = $db->table('instituciones')->find($id);
        abort_if(! $i, 404);
        $s = ModulosColegio::suscripcion(self::CONEXION, $id);
        $activos = $s ? $db->table('suscripcion_modulos')->where('suscripcion_id', $s->id)->where('activo', true)->pluck('modulo_id')->all() : [];

        return [
            'id' => (int) $i->id, 'nombre' => $i->nombre, 'razon_social' => $i->razon_social, 'ruc' => $i->ruc, 'slug' => $i->slug,
            'subdominio' => ($i->subdominio ?? $i->slug).'.'.config('edunova.dominio_colegios'),
            'codigo_modular' => $i->codigo_modular, 'ugel' => $i->ugel, 'dre' => $i->dre, 'nivel_educativo' => $i->nivel_educativo,
            'estado' => $i->estado, 'creado' => $i->created_at, 'suspension_programada' => $i->suspension_programada ?? null,
            'servicios' => $db->table('servicios_educativos')->where('institucion_id', $id)->orderBy('id')->get(['nivel', 'codigo_modular', 'anexo']),
            'suscripcion' => $s ? [
                'plan' => $db->table('planes')->where('id', $s->plan_id)->value('codigo'), 'fecha_inicio' => $s->fecha_inicio,
                'meses_gratis' => (int) $s->meses_gratis, 'precio_congelado_hasta' => $s->precio_congelado_hasta,
                'aula_opcion' => $s->aula_opcion, 'estado' => $s->estado,
            ] : null,
            'modulos' => $db->table('modulos_catalogo')->orderBy('id')->get(['id', 'codigo', 'nombre', 'tipo'])
                ->map(fn ($m) => ['codigo' => $m->codigo, 'nombre' => $m->nombre, 'tipo' => $m->tipo, 'activo' => in_array($m->id, $activos), 'fijo' => in_array($m->codigo, self::FIJOS, true)]),
            'promotores' => $db->table('membresias as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('roles as r', 'r.id', '=', 'm.rol_id')
                ->where('m.institucion_id', $id)->where('r.codigo', 'promotor')
                ->get(['u.id', 'u.nombres', 'u.apellidos', 'u.email', 'u.celular', 'm.activo', DB::raw('u.password is not null as acepto')]),
        ];
    }

    /**
     * EDUN-24 · Alta: institución en «implementacion», un servicio educativo por código modular,
     * suscripción con plan, aula y módulos, y el promotor invitado por correo.
     */
    public function crear(array $d, User $actor): array
    {
        $db = $this->db();
        $slug = mb_strtolower($d['slug']);
        if (in_array($slug, self::RESERVADOS, true)) {
            throw ValidationException::withMessages(['slug' => ['Ese subdominio está reservado.']]);
        }
        if ($db->table('instituciones')->where('slug', $slug)->orWhere('subdominio', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => ['Ese subdominio ya lo usa otro colegio.']]);
        }
        $niveles = array_column($d['servicios'], 'nivel');
        $codigos = array_column($d['servicios'], 'codigo_modular');
        if (count($niveles) !== count(array_unique($niveles))) {
            throw ValidationException::withMessages(['servicios' => ['Cada nivel va una sola vez, con su propio código modular.']]);
        }
        if (count($codigos) !== count(array_unique($codigos))) {
            throw ValidationException::withMessages(['servicios' => ['Cada nivel tiene un código modular distinto.']]);
        }
        $usado = $db->table('servicios_educativos')->whereIn('codigo_modular', $codigos)->value('codigo_modular')
            ?? $db->table('instituciones')->whereIn('codigo_modular', $codigos)->value('codigo_modular');
        if ($usado) {
            throw ValidationException::withMessages(['servicios' => ["El código modular {$usado} ya está registrado en Edunova."]]);
        }
        if (! empty($d['ruc']) && $db->table('instituciones')->where('ruc', $d['ruc'])->exists()) {
            throw ValidationException::withMessages(['ruc' => ['Ese RUC ya está registrado en otro colegio.']]);
        }
        $plan = $db->table('planes')->where('codigo', $d['plan'])->where('activo', true)->first();
        if (! $plan) {
            throw ValidationException::withMessages(['plan' => ['Elige un plan válido.']]);
        }
        $extras = $db->table('modulos_catalogo')->whereIn('codigo', $d['modulos_extra'] ?? [])->where('tipo', 'extra')->pluck('codigo')->all();
        $p = $d['promotor'];
        $email = mb_strtolower(trim($p['email']));
        $persona = UsuariosColegio::buscarPersona($email, 'DNI', $p['numero_documento'] ?? null, self::CONEXION);

        $orden = ['inicial' => 1, 'primaria' => 2, 'secundaria' => 3];
        $servicios = collect($d['servicios'])->sortBy(fn ($s) => $orden[$s['nivel']])->values()->all();

        [$id, $promotor] = $db->transaction(function () use ($db, $d, $slug, $servicios, $plan, $extras, $p, $email, $persona) {
            $id = (int) $db->table('instituciones')->insertGetId([
                'slug' => $slug, 'subdominio' => $slug, 'nombre' => trim($d['nombre']), 'razon_social' => $d['razon_social'] ?? null,
                'ruc' => $d['ruc'] ?? null, 'codigo_modular' => $servicios[0]['codigo_modular'], 'codigo_local' => $d['codigo_local'] ?? null,
                'ugel' => $d['ugel'], 'dre' => $d['dre'] ?? null, 'ubigeo' => $d['ubigeo'] ?? null, 'direccion' => $d['direccion'] ?? null,
                'telefono' => $d['telefono'] ?? null, 'correo' => $d['correo'] ?? null,
                'nivel_educativo' => count($servicios) > 1 ? 'multinivel' : $servicios[0]['nivel'], 'estado' => 'implementacion',
            ]);
            foreach ($servicios as $s) {
                $db->table('servicios_educativos')->insert(['institucion_id' => $id, 'codigo_modular' => $s['codigo_modular'], 'anexo' => $s['anexo'] ?? '0', 'nivel' => $s['nivel']]);
            }
            $mesesGratis = (int) ($d['meses_gratis'] ?? 0);
            $suscripcion = (int) $db->table('suscripciones')->insertGetId([
                'institucion_id' => $id, 'plan_id' => $plan->id, 'fecha_inicio' => $d['fecha_inicio'] ?? now()->toDateString(),
                'meses_gratis' => $mesesGratis, 'precio_congelado_hasta' => $d['precio_congelado_hasta'] ?? null,
                'aula_opcion' => $d['aula_opcion'], 'estado' => $mesesGratis > 0 ? 'prueba' : 'activa',
            ]);
            $codigos = [...$db->table('modulos_catalogo')->where('tipo', 'base')->pluck('codigo')->all(), ...ModulosColegio::AULA[$d['aula_opcion']], ...$extras];
            foreach ($db->table('modulos_catalogo')->whereIn('codigo', $codigos)->pluck('id') as $moduloId) {
                $db->table('suscripcion_modulos')->insert(['institucion_id' => $id, 'suscripcion_id' => $suscripcion, 'modulo_id' => $moduloId]);
            }

            $promotorId = $persona?->id ?? (int) $db->table('users')->insertGetId([
                'nombres' => trim($p['nombres']), 'apellidos' => trim($p['apellidos']), 'tipo_documento' => 'DNI',
                'numero_documento' => $p['numero_documento'] ?? null, 'email' => $email, 'celular' => $p['celular'] ?? null,
            ]);
            UsuariosColegio::fijarRoles($id, (int) $promotorId, ['promotor'], self::CONEXION);

            return [$id, $db->table('users')->find($promotorId)];
        });

        $invitacion = $this->invitaciones->invitar($id, $promotor, ['promotor'], 'correo', $actor->id, self::CONEXION);
        $detalle = $this->detalle($id);
        $this->auditoria->registrar('alta_institucion', 'instituciones', $id, null, [
            'nombre' => $detalle['nombre'], 'slug' => $slug, 'servicios' => $detalle['servicios']->all(), 'plan' => $d['plan'],
            'aula_opcion' => $d['aula_opcion'], 'modulos_extra' => $extras, 'promotor' => $email,
        ], $id, self::CONEXION);

        return $detalle + ['invitacion' => $invitacion];
    }

    /** EDUN-25 · Encender o apagar un módulo: aplica en la siguiente petición del colegio. */
    public function cambiarModulo(int $id, string $codigo, bool $activo): array
    {
        $db = $this->db();
        $modulo = $db->table('modulos_catalogo')->where('codigo', strtoupper($codigo))->first();
        abort_if(! $modulo, 404);
        if (in_array($modulo->codigo, self::FIJOS, true)) {
            throw ValidationException::withMessages(['modulo' => ['Administración no se puede apagar.']]);
        }
        if (in_array($modulo->tipo, ['aula', 'integracion'], true)) {
            throw ValidationException::withMessages(['modulo' => ['El aula virtual y Moodle se eligen con la opción de aula.']]);
        }
        $s = ModulosColegio::suscripcion(self::CONEXION, $id);
        abort_if(! $s, 404);
        $antes = (bool) $db->table('suscripcion_modulos')->where('suscripcion_id', $s->id)->where('modulo_id', $modulo->id)->value('activo');
        if ($antes !== $activo) {
            $this->fijarModulo($id, (int) $s->id, (int) $modulo->id, $activo);
            $this->auditoria->registrar($activo ? 'modulo_activado' : 'modulo_desactivado', 'suscripcion_modulos', (int) $s->id,
                ['modulo' => $modulo->codigo, 'activo' => $antes], ['modulo' => $modulo->codigo, 'activo' => $activo], $id, self::CONEXION);
        }

        return $this->detalle($id);
    }

    /** EDUN-26 · Aula a medida, Moodle, ambas o ninguna: enciende AUL y MDL según corresponda. */
    public function cambiarAula(int $id, string $opcion): array
    {
        $db = $this->db();
        $s = ModulosColegio::suscripcion(self::CONEXION, $id);
        abort_if(! $s, 404);
        if ($s->aula_opcion !== $opcion) {
            $db->transaction(function () use ($db, $id, $s, $opcion) {
                $db->table('suscripciones')->where('id', $s->id)->update(['aula_opcion' => $opcion]);
                foreach ($db->table('modulos_catalogo')->whereIn('codigo', ['AUL', 'MDL'])->get(['id', 'codigo']) as $m) {
                    $this->fijarModulo($id, (int) $s->id, (int) $m->id, in_array($m->codigo, ModulosColegio::AULA[$opcion], true));
                }
            });
            $this->auditoria->registrar('aula_cambiada', 'suscripciones', (int) $s->id, ['aula_opcion' => $s->aula_opcion], ['aula_opcion' => $opcion], $id, self::CONEXION);
        }

        return $this->detalle($id);
    }

    public function reinvitarPromotor(int $id, int $usuarioId, User $actor): array
    {
        $db = $this->db();
        $esPromotor = $db->table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')
            ->where('m.institucion_id', $id)->where('m.user_id', $usuarioId)->where('r.codigo', 'promotor')->exists();
        abort_if(! $esPromotor, 404);

        return $this->invitaciones->invitar($id, $db->table('users')->find($usuarioId), ['promotor'], 'correo', $actor->id, self::CONEXION);
    }

    private function fijarModulo(int $institucion, int $suscripcion, int $modulo, bool $activo): void
    {
        $this->db()->table('suscripcion_modulos')->upsert(
            [['institucion_id' => $institucion, 'suscripcion_id' => $suscripcion, 'modulo_id' => $modulo, 'activo' => $activo, 'activado_at' => now()]],
            ['suscripcion_id', 'modulo_id'],
            ['activo', 'activado_at'],
        );
    }
}
