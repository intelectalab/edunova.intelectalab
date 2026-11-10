<?php

namespace App\Modulos\PLA\Services;

use App\Modulos\Compartido\Correo\CorreoTransaccional;
use App\Modulos\PLA\Correo\AvisoSuspension;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-28 / PLA-H05 · Suspender un colegio por falta de pago.
 *
 * - Se programa la fecha: el promotor recibe un aviso 7 días antes y otro 3 días antes.
 * - El día de la suspensión el colegio pasa a «suspendido»: nadie inicia sesión ahí y la familia
 *   ve un aviso neutro. Los datos se conservan y se pueden exportar (EDUN-34).
 * - Reactivar devuelve el colegio a «activo» (o a «implementacion» si nunca se activó).
 */
class Suspensiones
{
    public const DIAS_AVISO = [7, 3];

    public function __construct(private Auditoria $auditoria, private CorreoTransaccional $correo) {}

    private function db()
    {
        return DB::connection(ConsolaInstituciones::CONEXION);
    }

    public function programar(int $id, string $fecha, string $motivo): void
    {
        $i = $this->db()->table('instituciones')->find($id);
        abort_if(! $i, 404);
        if (in_array($i->estado, ['suspendido', 'baja'], true)) {
            throw ValidationException::withMessages(['fecha' => ['El colegio ya está suspendido o dado de baja.']]);
        }
        if ($fecha <= now()->timezone('America/Lima')->toDateString()) {
            throw ValidationException::withMessages(['fecha' => ['Programa la suspensión para un día futuro (o suspende ahora).']]);
        }
        $this->db()->table('instituciones')->where('id', $id)->update(['suspension_programada' => $fecha, 'suspension_motivo' => $motivo, 'avisos_suspension' => '{}']);
        $this->auditoria->registrar('suspension_programada', 'instituciones', $id, null, ['fecha' => $fecha, 'motivo' => $motivo], $id, ConsolaInstituciones::CONEXION);
    }

    public function cancelar(int $id): void
    {
        $this->db()->table('instituciones')->where('id', $id)->update(['suspension_programada' => null, 'suspension_motivo' => null, 'avisos_suspension' => '{}']);
        $this->auditoria->registrar('suspension_cancelada', 'instituciones', $id, null, null, $id, ConsolaInstituciones::CONEXION);
    }

    public function suspender(int $id, string $motivo): void
    {
        $i = $this->db()->table('instituciones')->find($id);
        abort_if(! $i, 404);
        $cfg = json_decode($i->configuracion ?? '{}', true) ?: [];
        $cfg['estado_antes_de_suspender'] = $i->estado === 'implementacion' ? 'implementacion' : 'activo';
        $this->db()->table('instituciones')->where('id', $id)->update([
            'estado' => 'suspendido', 'suspension_programada' => null, 'suspension_motivo' => $motivo, 'configuracion' => json_encode($cfg, JSON_UNESCAPED_UNICODE),
        ]);
        // Las sesiones abiertas en el colegio se cierran.
        $this->db()->table('personal_access_tokens')->where('institucion_id', $id)->delete();
        $this->auditoria->registrar('colegio_suspendido', 'instituciones', $id, ['estado' => $i->estado], ['estado' => 'suspendido', 'motivo' => $motivo], $id, ConsolaInstituciones::CONEXION);
    }

    public function reactivar(int $id): void
    {
        $i = $this->db()->table('instituciones')->find($id);
        abort_if(! $i, 404);
        if ($i->estado !== 'suspendido') {
            throw ValidationException::withMessages(['estado' => ['El colegio no está suspendido.']]);
        }
        $estado = json_decode($i->configuracion ?? '{}', true)['estado_antes_de_suspender'] ?? 'activo';
        $this->db()->table('instituciones')->where('id', $id)->update(['estado' => $estado, 'suspension_motivo' => null]);
        $this->auditoria->registrar('colegio_reactivado', 'instituciones', $id, ['estado' => 'suspendido'], ['estado' => $estado], $id, ConsolaInstituciones::CONEXION);
    }

    /**
     * Avisos de 7 y 3 días y suspensiones del día (lo corre el programador una vez al día).
     *
     * @return array{avisos: int, suspendidos: int}
     */
    public function procesar(?Carbon $hoy = null): array
    {
        $hoy = ($hoy ?? now())->timezone('America/Lima')->startOfDay();
        $r = ['avisos' => 0, 'suspendidos' => 0];
        foreach ($this->db()->table('instituciones')->whereNotNull('suspension_programada')->whereIn('estado', ['activo', 'implementacion'])->get() as $i) {
            $fecha = Carbon::parse($i->suspension_programada, 'America/Lima')->startOfDay();
            $dias = (int) $hoy->diffInDays($fecha, false);
            if ($dias <= 0) {
                $this->suspender((int) $i->id, $i->suspension_motivo ?? 'Falta de pago');
                $r['suspendidos']++;

                continue;
            }
            $enviados = json_decode($i->avisos_suspension ?? '{}', true) ?: [];
            foreach (self::DIAS_AVISO as $d) {
                if ($dias <= $d && empty($enviados[(string) $d])) {
                    foreach ($this->promotores((int) $i->id) as $email) {
                        $this->correo->enviar(new AvisoSuspension($i->nombre, $fecha->format('d/m/Y'), $dias, $i->suspension_motivo), $email, 'aviso_suspension', (int) $i->id);
                    }
                    // Si ya pasó el de 7 días sin enviarse, basta con el más cercano.
                    foreach (self::DIAS_AVISO as $otro) {
                        if ($otro >= $d) {
                            $enviados[(string) $otro] = now()->toIso8601String();
                        }
                    }
                    $r['avisos']++;
                    break;
                }
            }
            $this->db()->table('instituciones')->where('id', $i->id)->update(['avisos_suspension' => json_encode($enviados)]);
        }

        return $r;
    }

    /** @return list<string> */
    private function promotores(int $institucion): array
    {
        return $this->db()->table('membresias as m')->join('users as u', 'u.id', '=', 'm.user_id')->join('roles as r', 'r.id', '=', 'm.rol_id')
            ->where('m.institucion_id', $institucion)->where('r.codigo', 'promotor')->where('m.activo', true)->whereNotNull('u.email')
            ->pluck('u.email')->unique()->values()->all();
    }
}
