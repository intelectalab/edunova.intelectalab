<?php

namespace App\Modulos\SEG\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-40 / SEG-H06 · Registro inalterable de cambios (tabla auditoria: solo INSERT; la base
 * rechaza UPDATE y DELETE con un trigger y la app no tiene esos privilegios).
 *
 * Guarda el antes y el después de cada cambio relevante (permisos, configuración, módulos…).
 * Nunca guarda contraseñas, secretos ni tokens.
 */
class Auditoria
{
    private const OCULTAR = ['password', 'dos_pasos_secreto', 'dos_pasos_respaldo', 'token', 'token_hash', 'token_cifrado', 'sso_secreto_cifrado'];

    /** Nombres para mostrar de las acciones (la lista de filtros sale de aquí). */
    public const ACCIONES = [
        'alta_institucion' => 'Alta del colegio',
        'modulo_activado' => 'Módulo activado',
        'modulo_desactivado' => 'Módulo desactivado',
        'aula_cambiada' => 'Opción de aula cambiada',
        'configuracion_editada' => 'Datos del colegio editados',
        'servicios_editados' => 'Códigos modulares editados',
        'logo_cambiado' => 'Logo cambiado',
        'usuario_invitado' => 'Usuario invitado',
        'invitacion_reenviada' => 'Invitación reenviada',
        'roles_cambiados' => 'Roles cambiados',
        'usuario_desactivado' => 'Usuario desactivado',
        'usuario_reactivado' => 'Usuario reactivado',
        'anio_creado' => 'Año escolar creado',
        'anio_editado' => 'Año escolar editado',
        'moodle_configurado' => 'Conector de Moodle configurado',
    ];

    /**
     * @param  array<string, mixed>|null  $antes
     * @param  array<string, mixed>|null  $despues
     */
    public function registrar(string $accion, string $tabla, ?int $registroId, ?array $antes, ?array $despues, ?int $institucionId = null, ?string $conexion = null): void
    {
        DB::connection($conexion)->table('auditoria')->insert([
            'institucion_id' => $institucionId ?? ContextoInstitucion::requerido(),
            'user_id' => auth()->id(),
            'accion' => $accion,
            'tabla' => $tabla,
            'registro_id' => $registroId,
            'antes' => $antes === null ? null : json_encode(self::limpiar($antes), JSON_UNESCAPED_UNICODE),
            'despues' => $despues === null ? null : json_encode(self::limpiar($despues), JSON_UNESCAPED_UNICODE),
            'ip' => request()?->ip(),
        ]);
    }

    /**
     * Consulta del colegio activo (el RLS solo deja ver sus filas).
     *
     * @param  array{usuario?: int|null, accion?: string|null, tabla?: string|null, desde?: string|null, hasta?: string|null}  $filtros
     */
    public function consulta(array $filtros): Builder
    {
        return DB::table('auditoria as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->select('a.id', 'a.created_at', 'a.accion', 'a.tabla', 'a.registro_id', 'a.antes', 'a.despues', 'a.ip', 'a.user_id',
                DB::raw("coalesce(u.nombres || ' ' || u.apellidos, 'Sistema') as usuario"))
            ->when($filtros['usuario'] ?? null, fn ($q, $v) => $q->where('a.user_id', $v))
            ->when($filtros['accion'] ?? null, fn ($q, $v) => $q->where('a.accion', $v))
            ->when($filtros['tabla'] ?? null, fn ($q, $v) => $q->where('a.tabla', $v))
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->where('a.created_at', '>=', $v))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->where('a.created_at', '<', Carbon::parse($v)->addDay()->toDateString()))
            ->orderByDesc('a.id');
    }

    /** @param  array<string, mixed>  $datos */
    private static function limpiar(array $datos): array
    {
        foreach ($datos as $clave => $valor) {
            if (in_array($clave, self::OCULTAR, true)) {
                unset($datos[$clave]);
            } elseif (is_array($valor)) {
                $datos[$clave] = self::limpiar($valor);
            }
        }

        return $datos;
    }
}
