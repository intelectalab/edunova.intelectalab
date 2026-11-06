<?php

namespace App\Modulos\SEG\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-39 / SEG-H05 · Segunda capa: sobre qué alumnos y secciones actúa cada usuario
 * (docs/03-roles-y-permisos.md, «Propiedad»).
 *
 * - Apoderado: solo los alumnos vinculados en alumno_apoderado.
 * - Alumno: solo él mismo.
 * - Docente: solo las secciones de sus cargas_docentes y sus alumnos.
 * - Tutor: la sección que tutorea (secciones.tutor_personal_id) y sus alumnos.
 * - Cualquier otro rol del colegio (dirección, secretaría…): todo el colegio; el permiso
 *   del rol ya se validó en la ruta. Si alguien tiene un rol general y además uno
 *   restringido (p. ej. director que también dicta), prevalece el general.
 *
 * Todo corre dentro del colegio activo: el RLS ya limita al colegio, aquí se limita al usuario.
 */
class AlcanceAcademico
{
    public const ROLES_RESTRINGIDOS = ['docente', 'tutor', 'apoderado', 'alumno'];

    public function __construct(private Autorizacion $autorizacion) {}

    public function veTodoElColegio(User $usuario): bool
    {
        return array_diff($this->autorizacion->roles($usuario), self::ROLES_RESTRINGIDOS) !== [];
    }

    public function puedeVerAlumno(User $usuario, int $alumnoId): bool
    {
        return $this->veTodoElColegio($usuario)
            || DB::query()->fromSub($this->alumnosVisibles($usuario), 'v')->where('v.id', $alumnoId)->exists();
    }

    public function puedeVerSeccion(User $usuario, int $seccionId): bool
    {
        return $this->veTodoElColegio($usuario)
            || DB::query()->fromSub($this->seccionesVisibles($usuario), 'v')->where('v.id', $seccionId)->exists();
    }

    /** Subconsulta con los id de alumnos que el usuario puede ver por propiedad. */
    public function alumnosVisibles(User $usuario): Builder
    {
        $roles = $this->autorizacion->roles($usuario);
        $consultas = [];

        if (in_array('apoderado', $roles, true)) {
            $consultas[] = DB::table('alumno_apoderado as aa')
                ->join('apoderados as ap', 'ap.id', '=', 'aa.apoderado_id')
                ->where('ap.user_id', $usuario->id)
                ->select('aa.alumno_id as id');
        }
        if (in_array('alumno', $roles, true)) {
            $consultas[] = DB::table('alumnos')->where('user_id', $usuario->id)->select('id');
        }
        if (array_intersect(['docente', 'tutor'], $roles) !== []) {
            $consultas[] = DB::table('matriculas as m')
                ->whereIn('m.seccion_id', $this->seccionesVisibles($usuario))
                ->whereIn('m.estado', ['matriculado', 'reservado'])
                ->select('m.alumno_id as id');
        }

        return $this->unir($consultas);
    }

    /** Subconsulta con los id de secciones que el docente dicta o el tutor tutorea. */
    public function seccionesVisibles(User $usuario): Builder
    {
        $roles = $this->autorizacion->roles($usuario);
        $personal = DB::table('personal')->where('user_id', $usuario->id)->where('estado', 'activo')->select('id');
        $consultas = [];

        if (in_array('docente', $roles, true)) {
            $consultas[] = DB::table('cargas_docentes')->whereIn('personal_id', $personal)->select('seccion_id as id');
        }
        if (in_array('tutor', $roles, true)) {
            $consultas[] = DB::table('secciones')->whereIn('tutor_personal_id', $personal)->select('id');
        }

        return $this->unir($consultas);
    }

    /** @param  list<Builder>  $consultas */
    private function unir(array $consultas): Builder
    {
        if ($consultas === []) {
            return DB::query()->selectRaw('null::bigint as id')->whereRaw('false');
        }
        $union = array_shift($consultas);
        foreach ($consultas as $consulta) {
            $union->union($consulta);
        }

        return $union;
    }
}
