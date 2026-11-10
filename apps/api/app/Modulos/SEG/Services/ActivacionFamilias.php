<?php

namespace App\Modulos\SEG\Services;

use App\Models\User;
use App\Modulos\Compartido\ContextoInstitucion;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-37 / SEG-H03 · Activar a los padres desde la matrícula.
 *
 * Al registrar un apoderado con celular o correo se le crea el acceso (rol apoderado) y se le
 * envía la invitación: por correo si lo tiene; si no, queda el enlace de WhatsApp para enviarlo.
 * Si su DNI o correo ya es de un usuario de Edunova (por ejemplo, en otro colegio), se reutiliza
 * ese usuario: no se crea otro y, si ya tiene contraseña, no hace falta invitarlo.
 */
class ActivacionFamilias
{
    public function __construct(private Invitaciones $invitaciones) {}

    /**
     * Devuelve null si el apoderado no tiene correo ni celular.
     *
     * @return array{apoderado_id: int, nombre: string, canal: string|null, whatsapp_url: string|null, reutilizado: bool}|null
     */
    public function activar(int $apoderadoId, ?User $actor = null, ?string $canal = null): ?array
    {
        $p = DB::table('apoderados')->find($apoderadoId);
        if (! $p || (! $p->email && ! Invitaciones::celularWhatsapp($p->celular))) {
            return null;
        }
        $institucion = ContextoInstitucion::requerido();
        $reutilizado = false;

        $userId = $p->user_id;
        if (! $userId) {
            $existente = UsuariosColegio::buscarPersona($p->email, $p->tipo_documento, $p->numero_documento);
            if ($existente) {
                $userId = (int) $existente->id;
                $reutilizado = true;
                // Completa el contacto que le falte, sin cambiar el que ya tiene.
                $completar = array_filter(['email' => $existente->email ? null : $p->email, 'celular' => $existente->celular ? null : $p->celular]);
                if ($completar !== []) {
                    DB::table('users')->where('id', $userId)->update($completar);
                }
            } else {
                $userId = (int) DB::table('users')->insertGetId([
                    'nombres' => $p->nombres, 'apellidos' => $p->apellidos, 'tipo_documento' => $p->tipo_documento,
                    'numero_documento' => $p->numero_documento, 'email' => $p->email, 'celular' => $p->celular,
                ]);
            }
            DB::table('apoderados')->where('id', $apoderadoId)->update(['user_id' => $userId]);
        }

        // Rol de apoderado en este colegio (sin tocar otros roles que ya tenga, p. ej. docente).
        $rol = DB::table('roles')->where('codigo', 'apoderado')->value('id');
        DB::table('membresias')->upsert(
            [['institucion_id' => $institucion, 'user_id' => $userId, 'rol_id' => $rol, 'activo' => true]],
            ['institucion_id', 'user_id', 'rol_id'],
            ['activo'],
        );

        $usuario = DB::table('users')->find($userId);
        $resultado = ['apoderado_id' => $apoderadoId, 'nombre' => trim("{$p->nombres} {$p->apellidos}"), 'canal' => null, 'whatsapp_url' => null, 'reutilizado' => $reutilizado];
        if ($usuario->password && $canal === null) {
            return $resultado; // Ya usa Edunova: entra con su clave y ve el colegio nuevo.
        }
        $canal ??= $usuario->email ? 'correo' : 'whatsapp';
        $inv = $this->invitaciones->invitar($institucion, $usuario, ['apoderado'], $canal, $actor?->id);

        return ['canal' => $canal, 'whatsapp_url' => $inv['whatsapp_url']] + $resultado;
    }
}
