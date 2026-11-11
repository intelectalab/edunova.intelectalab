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
        'plan_estudios_editado' => 'Plan de estudios editado',
        'plan_estudios_copiado' => 'Plan de estudios copiado',
        'seccion_creada' => 'Sección creada',
        'seccion_editada' => 'Sección editada',
        'seccion_eliminada' => 'Sección eliminada',
        'secciones_copiadas' => 'Secciones copiadas',
        'personal_registrado' => 'Personal registrado',
        'personal_editado' => 'Personal editado',
        'carga_asignada' => 'Carga docente asignada',
        'carga_quitada' => 'Carga docente quitada',
        'bloques_editados' => 'Bloques de hora editados',
        'horario_editado' => 'Horario editado',
        'alumno_registrado' => 'Alumno registrado',
        'alumno_editado' => 'Alumno editado',
        'alumnos_importados' => 'Alumnos importados',
        'consentimiento_texto_publicado' => 'Texto de consentimiento publicado',
        'consentimiento_registrado' => 'Consentimiento registrado',
        'condiciones_publicadas' => 'Condiciones económicas publicadas',
        'matricula_registrada' => 'Matrícula registrada',
        'alumno_retirado' => 'Alumno retirado',
        'alumno_trasladado' => 'Alumno trasladado',
        'seccion_cambiada' => 'Cambio de sección',
        'carne_regenerado' => 'Carné regenerado',
        'foto_alumno' => 'Foto del alumno cambiada',
        'salud_consultada' => 'Ficha de salud consultada',
        'postulante_convertido' => 'Postulante convertido en alumno',
        'suspension_programada' => 'Suspensión programada',
        'concepto_creado' => 'Concepto de cobro creado',
        'concepto_actualizado' => 'Concepto de cobro actualizado',
        'cronograma_guardado' => 'Cronograma guardado',
        'cronograma_eliminado' => 'Cronograma eliminado',
        'cronogramas_copiados' => 'Cronogramas copiados de otro año',
        'beneficio_creado' => 'Tipo de beneficio creado',
        'beneficio_actualizado' => 'Tipo de beneficio actualizado',
        'beneficio_solicitado' => 'Beneficio solicitado',
        'beneficio_aprobado' => 'Beneficio aprobado',
        'beneficio_rechazado' => 'Beneficio rechazado',
        'beneficio_retirado' => 'Beneficio retirado',
        'proveedor_facturacion_creado' => 'Proveedor de facturación agregado',
        'proveedor_facturacion_actualizado' => 'Proveedor de facturación actualizado',
        'pago_registrado' => 'Pago registrado',
        'pago_anulado' => 'Pago anulado',
        'caja_abierta' => 'Caja abierta',
        'caja_cerrada' => 'Caja cerrada con arqueo',
        'comprobante_creado' => 'Comprobante emitido',
        'comprobante_anulado' => 'Comprobante anulado',
        'nota_credito_emitida' => 'Nota de crédito emitida',
        'pasarela_configurada' => 'Pasarela de pagos conectada',
        'pasarela_actualizada' => 'Pasarela de pagos actualizada',
        'pasarela_verificada' => 'Llaves de la pasarela verificadas',
        'pasarela_fallo_prueba' => 'Prueba de la pasarela fallida',
        'pago_en_linea_confirmado' => 'Pago en línea confirmado',
        'matricula_ratificada' => 'Matrícula ratificada por la familia',
        'matricula_reservada_por_anulacion' => 'Matrícula vuelve a reserva (se anuló el pago de matrícula)',
        'recordatorio_notas' => 'Recordatorio de notas pendientes',
        'seccion_cerrada' => 'Sección cerrada en el periodo',
        'periodo_cerrado' => 'Periodo cerrado',
        'periodo_reabierto' => 'Periodo reabierto',
        'cobro_mensual_emitido' => 'Cobro mensual emitido',
        'cobro_mensual_pagada' => 'Cobro mensual pagado',
        'cobro_mensual_anulada' => 'Cobro mensual anulado',
        'incidencia_registrada' => 'Incidencia de conducta registrada',
        'merito_registrado' => 'Mérito registrado',
        'alerta_seguimiento' => 'Seguimiento de alerta de riesgo',
        'libretas_generadas' => 'Libretas generadas',
        'libretas_publicadas' => 'Libretas publicadas',
        'recuperacion_registrada' => 'Nota de recuperación registrada',
        'nomina_siagie_subida' => 'Nómina del SIAGIE importada',
        'plantilla_siagie_generada' => 'Plantilla de notas del SIAGIE generada',
        'mapeo_siagie_guardado' => 'Mapeo de plantilla SIAGIE guardado',
        'comunicado_creado' => 'Comunicado creado',
        'comunicado_anulado' => 'Comunicado anulado',
        'reclamo_respondido' => 'Reclamo respondido',
        'gestion_cobranza' => 'Gestión de cobranza registrada',
        'convenio_creado' => 'Convenio de fraccionamiento creado',
        'convenio_firmado' => 'Convenio firmado',
        'convenio_aprobado' => 'Convenio aprobado',
        'convenio_anulado' => 'Convenio anulado',
        'convenio_incumplido' => 'Convenio incumplido',
        'recaudacion_deudas' => 'Archivo de deudas generado',
        'recaudacion_pagos' => 'Archivo de pagos del banco procesado',
        'suspension_cancelada' => 'Suspensión cancelada',
        'colegio_suspendido' => 'Colegio suspendido',
        'colegio_reactivado' => 'Colegio reactivado',
        'colegio_activado' => 'Colegio activado',
        'checklist_marcado' => 'Paso de implementación marcado',
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
