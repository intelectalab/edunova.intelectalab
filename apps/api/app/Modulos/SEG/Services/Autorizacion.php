<?php

namespace App\Modulos\SEG\Services;

use App\Models\User;
use App\Modulos\Compartido\ContextoInstitucion;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-39 / SEG-H05 · Primera capa: permisos del rol (docs/03-roles-y-permisos.md).
 *
 * Los permisos de un usuario en el colegio activo salen de sus membresías vigentes
 * (activo y dentro de desde/hasta) → rol_permiso → permisos. Se calcula una vez por
 * petición. La segunda capa, sobre qué registros, la resuelve AlcanceAcademico en las policies.
 */
class Autorizacion
{
    /** @var array<string, array{roles: list<string>, permisos: array<string, true>}> */
    private array $cache = [];

    /** @return list<string> códigos de rol vigentes en el colegio activo */
    public function roles(User $usuario): array
    {
        return $this->cargar($usuario)['roles'];
    }

    public function tieneRol(User $usuario, string ...$roles): bool
    {
        return array_intersect($roles, $this->roles($usuario)) !== [];
    }

    public function tiene(User $usuario, string $permiso): bool
    {
        return isset($this->cargar($usuario)['permisos'][$permiso]);
    }

    /** Alguno de los permisos (para rutas con «a|b»). */
    public function tieneAlguno(User $usuario, array $permisos): bool
    {
        foreach ($permisos as $permiso) {
            if ($this->tiene($usuario, $permiso)) {
                return true;
            }
        }

        return false;
    }

    public function esMiembro(User $usuario): bool
    {
        return $this->roles($usuario) !== [];
    }

    public function olvidar(): void
    {
        $this->cache = [];
    }

    private function cargar(User $usuario): array
    {
        $institucion = ContextoInstitucion::actual();
        $clave = $usuario->id.'|'.($institucion ?? '-');
        if (isset($this->cache[$clave])) {
            return $this->cache[$clave];
        }
        if ($institucion === null) {
            return $this->cache[$clave] = ['roles' => [], 'permisos' => []];
        }

        // membresias tiene RLS por colegio: solo devuelve filas del colegio activo.
        $roles = DB::table('membresias as m')
            ->join('roles as r', 'r.id', '=', 'm.rol_id')
            ->where('m.user_id', $usuario->id)
            ->where('m.activo', true)
            ->where('m.desde', '<=', DB::raw('current_date'))
            ->where(fn ($q) => $q->whereNull('m.hasta')->orWhere('m.hasta', '>=', DB::raw('current_date')))
            ->pluck('r.codigo')->unique()->values()->all();

        $permisos = $roles === [] ? [] : DB::table('rol_permiso as rp')
            ->join('roles as r', 'r.id', '=', 'rp.rol_id')
            ->join('permisos as p', 'p.id', '=', 'rp.permiso_id')
            ->whereIn('r.codigo', $roles)
            ->pluck('p.codigo')->unique()->all();

        return $this->cache[$clave] = ['roles' => $roles, 'permisos' => array_fill_keys($permisos, true)];
    }
}
