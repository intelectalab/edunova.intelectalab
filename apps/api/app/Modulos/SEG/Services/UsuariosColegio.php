<?php

namespace App\Modulos\SEG\Services;

use App\Models\User;
use App\Modulos\Compartido\ContextoInstitucion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-36 / SEG-02 · Usuarios del colegio activo: invitar con uno o varios roles, cambiar
 * roles y desactivar. Todo queda en auditoría (SEG regla 4: cambios de permisos).
 *
 * - Si la persona ya usa Edunova (mismo correo o documento), se reutiliza su usuario.
 * - Apoderados y alumnos se activan desde la matrícula (SEG-H03), no desde aquí.
 * - Solo un promotor asigna o quita el rol de promotor. Nadie cambia sus propios roles
 *   ni se desactiva a sí mismo (evita quedarse sin acceso).
 */
class UsuariosColegio
{
    public const NO_ASIGNABLES = ['apoderado', 'alumno'];

    public function __construct(private Autorizacion $autorizacion, private Invitaciones $invitaciones, private Auditoria $auditoria) {}

    /** @return list<array{codigo: string, nombre: string}> */
    public function rolesAsignables(User $actor): array
    {
        $esPromotor = $this->autorizacion->tieneRol($actor, 'promotor');

        return DB::table('roles')->where('ambito', 'institucion')->whereNotIn('codigo', self::NO_ASIGNABLES)
            ->when(! $esPromotor, fn ($q) => $q->where('codigo', '<>', 'promotor'))
            ->orderBy('id')->get(['codigo', 'nombre'])->map(fn ($r) => (array) $r)->all();
    }

    /** @param  array{buscar?: string|null, rol?: string|null, estado?: string|null}  $filtros */
    public function listar(array $filtros): array
    {
        $filas = DB::table('membresias as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->join('roles as r', 'r.id', '=', 'm.rol_id')
            ->whereNotIn('r.codigo', self::NO_ASIGNABLES)
            ->when($filtros['buscar'] ?? null, function ($q, $v) {
                $t = '%'.mb_strtolower(trim($v)).'%';
                $q->where(fn ($w) => $w->whereRaw("lower(u.nombres || ' ' || u.apellidos) like ?", [$t])
                    ->orWhereRaw('lower(u.email) like ?', [$t])->orWhere('u.numero_documento', 'like', $t));
            })
            ->orderBy('u.apellidos')->orderBy('u.nombres')
            ->get(['u.id', 'u.nombres', 'u.apellidos', 'u.tipo_documento', 'u.numero_documento', 'u.email', 'u.celular', 'u.ultimo_acceso_at',
                DB::raw('u.password is not null as tiene_password'), DB::raw('u.dos_pasos_activo_at is not null as dos_pasos'), 'r.codigo as rol', 'm.activo']);

        $pendientes = DB::table('invitaciones')->whereNull('usada_at')->where('expira_at', '>', now())
            ->select('user_id', DB::raw('max(expira_at) as vence'), DB::raw('max(canal) as canal'))->groupBy('user_id')->get()->keyBy('user_id');

        $usuarios = [];
        foreach ($filas as $f) {
            $u = $usuarios[$f->id] ??= [
                'id' => (int) $f->id, 'nombres' => $f->nombres, 'apellidos' => $f->apellidos, 'tipo_documento' => $f->tipo_documento,
                'numero_documento' => $f->numero_documento, 'email' => $f->email, 'celular' => $f->celular, 'ultimo_acceso_at' => $f->ultimo_acceso_at,
                'tiene_password' => (bool) $f->tiene_password, 'dos_pasos' => (bool) $f->dos_pasos, 'roles' => [], 'activo' => false,
                'invitacion_pendiente' => isset($pendientes[$f->id]) ? ['vence' => $pendientes[$f->id]->vence, 'canal' => $pendientes[$f->id]->canal] : null,
            ];
            if ($f->activo) {
                $u['roles'][] = $f->rol;
                $u['activo'] = true;
            }
            $usuarios[$f->id] = $u;
        }

        return array_values(array_filter($usuarios, function ($u) use ($filtros) {
            if (($filtros['rol'] ?? null) && ! in_array($filtros['rol'], $u['roles'], true)) {
                return false;
            }

            return match ($filtros['estado'] ?? null) {
                'activo' => $u['activo'] && $u['tiene_password'],
                'pendiente' => $u['activo'] && ! $u['tiene_password'],
                'inactivo' => ! $u['activo'],
                default => true,
            };
        }));
    }

    /**
     * @param  array{nombres: string, apellidos: string, tipo_documento?: string|null, numero_documento?: string|null, email?: string|null, celular?: string|null, roles: list<string>, canal: string}  $datos
     * @return array{usuario_id: int, invitacion: array<string, mixed>}
     */
    public function invitar(User $actor, array $datos): array
    {
        $roles = $this->validarRoles($actor, $datos['roles']);
        $institucion = ContextoInstitucion::requerido();
        $email = isset($datos['email']) ? mb_strtolower(trim($datos['email'])) : null;
        $tipoDoc = $datos['tipo_documento'] ?? 'DNI';

        $usuario = self::buscarPersona($email, $tipoDoc, $datos['numero_documento'] ?? null);
        if ($usuario) {
            if ($this->rolesDe($usuario->id) !== []) {
                throw ValidationException::withMessages(['email' => ['Esta persona ya es usuaria del colegio: cambia sus roles desde la lista.']]);
            }
            // Completa el contacto que le falte, sin cambiar el que ya tiene.
            $completar = array_filter([
                'celular' => $usuario->celular ? null : ($datos['celular'] ?? null),
                'email' => $usuario->email ? null : $email,
            ]);
            if ($completar !== []) {
                DB::table('users')->where('id', $usuario->id)->update($completar);
            }
        } else {
            $id = DB::table('users')->insertGetId([
                'nombres' => trim($datos['nombres']), 'apellidos' => trim($datos['apellidos']), 'tipo_documento' => $tipoDoc,
                'numero_documento' => $datos['numero_documento'] ?? null, 'email' => $email, 'celular' => $datos['celular'] ?? null,
            ]);
            $usuario = DB::table('users')->find($id);
        }
        $usuario = DB::table('users')->find($usuario->id);

        $this->fijarRoles($institucion, (int) $usuario->id, $roles);
        $invitacion = $this->invitaciones->invitar($institucion, $usuario, $roles, $datos['canal'], $actor->id);
        $this->auditoria->registrar('usuario_invitado', 'membresias', (int) $usuario->id, null, [
            'usuario' => trim("{$usuario->nombres} {$usuario->apellidos}"), 'roles' => $roles, 'canal' => $datos['canal'],
        ]);

        return ['usuario_id' => (int) $usuario->id, 'invitacion' => $invitacion];
    }

    public function reinvitar(User $actor, int $usuarioId, string $canal): array
    {
        $roles = $this->miembro($usuarioId);
        $usuario = DB::table('users')->find($usuarioId);
        $invitacion = $this->invitaciones->invitar(ContextoInstitucion::requerido(), $usuario, $roles, $canal, $actor->id);
        $this->auditoria->registrar('invitacion_reenviada', 'membresias', $usuarioId, null, ['canal' => $canal]);

        return $invitacion;
    }

    /** @param  list<string>  $roles */
    public function cambiarRoles(User $actor, int $usuarioId, array $roles): void
    {
        if ($actor->id === $usuarioId) {
            throw ValidationException::withMessages(['roles' => ['No puedes cambiar tus propios roles.']]);
        }
        $antes = $this->miembro($usuarioId);
        $roles = $this->validarRoles($actor, $roles, $antes);
        $this->fijarRoles(ContextoInstitucion::requerido(), $usuarioId, $roles);
        $this->auditoria->registrar('roles_cambiados', 'membresias', $usuarioId, ['roles' => $antes], ['roles' => $roles]);
    }

    public function cambiarEstado(User $actor, int $usuarioId, bool $activo): void
    {
        if ($actor->id === $usuarioId) {
            throw ValidationException::withMessages(['activo' => ['No puedes desactivarte a ti mismo.']]);
        }
        $roles = $this->miembro($usuarioId, incluirInactivos: true);
        if (in_array('promotor', $roles, true) && ! $this->autorizacion->tieneRol($actor, 'promotor')) {
            throw ValidationException::withMessages(['activo' => ['Solo un promotor puede desactivar a otro promotor.']])->status(403);
        }
        $institucion = ContextoInstitucion::requerido();
        DB::table('membresias')->where('user_id', $usuarioId)->update(['activo' => $activo]);
        if (! $activo) {
            // Cierra sus sesiones en este colegio.
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $usuarioId)
                ->where('institucion_id', $institucion)->delete();
        }
        $this->auditoria->registrar($activo ? 'usuario_reactivado' : 'usuario_desactivado', 'membresias', $usuarioId, ['activo' => ! $activo], ['activo' => $activo]);
    }

    /** Usuario existente por correo o por documento. */
    public static function buscarPersona(?string $email, string $tipoDoc, ?string $documento, ?string $conexion = null): ?object
    {
        $db = DB::connection($conexion);
        $porCorreo = $email ? $db->table('users')->whereRaw('lower(email) = ?', [$email])->first() : null;
        $porDoc = $documento ? $db->table('users')->where('tipo_documento', $tipoDoc)->where('numero_documento', $documento)->first() : null;
        if ($porCorreo && $porDoc && $porCorreo->id !== $porDoc->id) {
            throw ValidationException::withMessages(['email' => ['El correo y el documento pertenecen a personas distintas.']]);
        }

        return $porCorreo ?? $porDoc;
    }

    /**
     * Roles del usuario en el colegio activo; 404 si no es miembro.
     *
     * @return list<string>
     */
    private function miembro(int $usuarioId, bool $incluirInactivos = false): array
    {
        $roles = $this->rolesDe($usuarioId, $incluirInactivos);
        abort_if($roles === [], 404);

        return $roles;
    }

    /** @return list<string> */
    private function rolesDe(int $usuarioId, bool $incluirInactivos = true): array
    {
        return DB::table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->where('m.user_id', $usuarioId)
            ->when(! $incluirInactivos, fn ($q) => $q->where('m.activo', true))
            ->whereNotIn('r.codigo', self::NO_ASIGNABLES)->orderBy('r.id')->pluck('r.codigo')->all();
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $actuales
     * @return list<string>
     */
    private function validarRoles(User $actor, array $roles, array $actuales = []): array
    {
        $roles = array_values(array_unique($roles));
        $permitidos = array_column($this->rolesAsignables($actor), 'codigo');
        if ($roles === []) {
            throw ValidationException::withMessages(['roles' => ['Elige al menos un rol.']]);
        }
        foreach ($roles as $rol) {
            if (! in_array($rol, $permitidos, true)) {
                throw ValidationException::withMessages(['roles' => [$rol === 'promotor' ? 'Solo un promotor puede asignar el rol de promotor.' : 'Ese rol no se puede asignar.']]);
            }
        }
        if (in_array('promotor', $actuales, true) && ! in_array('promotor', $roles, true) && ! $this->autorizacion->tieneRol($actor, 'promotor')) {
            throw ValidationException::withMessages(['roles' => ['Solo un promotor puede quitar el rol de promotor.']]);
        }

        return $roles;
    }

    /**
     * Deja activos exactamente esos roles (los demás quedan inactivos, no se borran).
     *
     * @param  list<string>  $roles
     */
    public static function fijarRoles(int $institucion, int $usuarioId, array $roles, ?string $conexion = null): void
    {
        $db = DB::connection($conexion);
        $ids = $db->table('roles')->whereIn('codigo', $roles)->pluck('id')->all();
        foreach ($ids as $rolId) {
            $db->table('membresias')->upsert(
                [['institucion_id' => $institucion, 'user_id' => $usuarioId, 'rol_id' => $rolId, 'activo' => true]],
                ['institucion_id', 'user_id', 'rol_id'],
                ['activo'],
            );
        }
        $db->table('membresias')->where('institucion_id', $institucion)->where('user_id', $usuarioId)
            ->whereNotIn('rol_id', $ids)
            ->whereIn('rol_id', $db->table('roles')->whereNotIn('codigo', self::NO_ASIGNABLES)->select('id'))
            ->update(['activo' => false]);
    }
}
