<?php

namespace App\Modulos\ADM\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\PAG\Services\GeneradorCargos;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-55 · ADM-H13 Ratificar la matrícula desde la app.
 *
 * El apoderado acepta las condiciones económicas del año siguiente, actualiza sus datos y
 * reserva la vacante en el grado siguiente (misma sección si tiene cupo). Se crea solo el cargo
 * de matrícula; al pagarlo (en línea o en caja) la matrícula pasa a «matriculado», se generan
 * los demás cargos del cronograma y queda disponible la constancia.
 */
class Ratificacion
{
    public function __construct(private CondicionesEconomicas $condiciones, private Auditoria $auditoria) {}

    /** Año al que se ratifica: el siguiente al de la matrícula actual, abierto o planificado. */
    private function anioDestino(int $anioActual): ?object
    {
        return DB::table('anios_escolares')->where('anio', '>', $anioActual)->whereIn('estado', ['planificado', 'abierto'])->orderBy('anio')->first(['id', 'anio']);
    }

    /** Hijos que pueden ratificar, con la sección propuesta y las condiciones a aceptar. */
    public function opciones(int $usuarioId): array
    {
        $r = [];
        foreach (FamiliaController::alumnosDe($usuarioId) as $alumnoId) {
            $actual = DB::table('matriculas as m')->join('anios_escolares as y', 'y.id', '=', 'm.anio_escolar_id')->join('secciones as s', 's.id', '=', 'm.seccion_id')
                ->join('grados as g', 'g.id', '=', 's.grado_id')->join('alumnos as a', 'a.id', '=', 'm.alumno_id')
                ->where('m.alumno_id', $alumnoId)->where('m.estado', 'matriculado')->orderByDesc('y.anio')
                ->first(['m.id', 'y.anio', 's.nombre as seccion', 'g.servicio_educativo_id', 'g.numero', 'g.nivel', 'g.nombre as grado', 'a.nombres']);
            if (! $actual || ! ($destino = $this->anioDestino((int) $actual->anio))) {
                continue;
            }
            $ya = DB::table('matriculas')->where('alumno_id', $alumnoId)->where('anio_escolar_id', $destino->id)->first(['id', 'estado']);
            $propuesta = $this->seccionPropuesta($actual, (int) $destino->id);
            $condiciones = $this->condiciones->vigente((int) $destino->id);
            $r[] = [
                'alumno_id' => $alumnoId, 'alumno' => $actual->nombres, 'actual' => "{$actual->grado} {$actual->seccion} ({$actual->anio})", 'anio' => $destino->anio,
                'anio_escolar_id' => $destino->id, 'seccion' => $propuesta ? ['id' => $propuesta->id, 'nombre' => "{$propuesta->grado} {$propuesta->nombre}"] : null,
                'condiciones' => $condiciones ? ['version' => $condiciones->version, 'contenido' => $condiciones->contenido] : null,
                'matricula' => $ya ? ['id' => $ya->id, 'estado' => $ya->estado] : null,
                'cargo_matricula' => $ya ? DB::table('cargos as c')->join('conceptos_cobro as cp', 'cp.id', '=', 'c.concepto_cobro_id')->where('c.matricula_id', $ya->id)
                    ->where('cp.tipo', 'matricula')->first(['c.id', 'c.estado', DB::raw('c.monto - c.descuento + c.mora - c.pagado as saldo')]) : null,
            ];
        }
        $apoderado = DB::table('apoderados')->where('user_id', $usuarioId)->first(['celular', 'email', 'direccion']);

        return ['hijos' => $r, 'datos' => $apoderado ? (array) $apoderado : null];
    }

    /** Grado siguiente del mismo servicio; la misma sección si tiene vacantes, si no la primera con cupo. */
    private function seccionPropuesta(object $actual, int $anioId): ?object
    {
        $grado = DB::table('grados')->where('servicio_educativo_id', $actual->servicio_educativo_id)->where('numero', $actual->numero + 1)->value('id');
        if (! $grado) {
            return null; // Último grado: egresa, no ratifica.
        }
        $secciones = DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->where('s.anio_escolar_id', $anioId)->where('s.grado_id', $grado)
            ->orderByRaw('s.nombre = ? desc', [$actual->seccion])->orderBy('s.nombre')->get(['s.id', 's.nombre', 's.vacantes', 'g.nombre as grado']);
        foreach ($secciones as $s) {
            $ocupadas = DB::table('matriculas')->where('seccion_id', $s->id)->whereIn('estado', ['reservado', 'matriculado'])->count();
            if ($s->vacantes === null || $ocupadas < $s->vacantes) {
                return $s;
            }
        }

        return null;
    }

    /** @param array{celular?: ?string, email?: ?string, direccion?: ?string} $datos */
    public function ratificar(int $usuarioId, int $alumnoId, array $datos, string $ip): array
    {
        $opcion = collect($this->opciones($usuarioId)['hijos'])->firstWhere('alumno_id', $alumnoId);
        if (! $opcion) {
            throw ValidationException::withMessages(['alumno_id' => ['Ese alumno no tiene ratificación pendiente.']]);
        }
        if ($opcion['matricula']) {
            throw ValidationException::withMessages(['alumno_id' => ['La matrícula de ese año ya está registrada.']]);
        }
        if (! $opcion['condiciones']) {
            throw ValidationException::withMessages(['condiciones' => ['El colegio aún no publica las condiciones económicas del próximo año.']]);
        }
        if (! $opcion['seccion']) {
            throw ValidationException::withMessages(['seccion' => ['No hay vacantes en el grado siguiente. Comunícate con el colegio.']]);
        }
        $apoderado = DB::table('apoderados')->where('user_id', $usuarioId)->first();
        abort_if(! $apoderado, 403);

        return DB::transaction(function () use ($opcion, $apoderado, $alumnoId, $datos, $ip) {
            $inst = ContextoInstitucion::requerido();
            $cambios = array_filter(['celular' => $datos['celular'] ?? null, 'email' => $datos['email'] ?? null, 'direccion' => $datos['direccion'] ?? null], fn ($v) => $v !== null && $v !== '');
            if ($cambios) {
                DB::table('apoderados')->where('id', $apoderado->id)->update($cambios);
            }
            $matricula = (int) DB::table('matriculas')->insertGetId([
                'institucion_id' => $inst, 'alumno_id' => $alumnoId, 'anio_escolar_id' => $opcion['anio_escolar_id'], 'seccion_id' => $opcion['seccion']['id'],
                'tipo' => 'ratificacion', 'estado' => 'reservado', 'origen' => 'manual',
            ]);
            $condiciones = $this->condiciones->vigente((int) $opcion['anio_escolar_id']);
            DB::table('condiciones_aceptaciones')->insert(['institucion_id' => $inst, 'condiciones_economicas_id' => $condiciones->id, 'matricula_id' => $matricula, 'apoderado_id' => $apoderado->id, 'ip' => $ip]);

            $nivel = DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->where('s.id', $opcion['seccion']['id'])->value('g.nivel');
            $cronograma = GeneradorCargos::cronogramaDe((int) $opcion['anio_escolar_id'], $nivel);
            $cuota = $cronograma ? DB::table('cronograma_cuotas as cc')->join('conceptos_cobro as cp', 'cp.id', '=', 'cc.concepto_cobro_id')
                ->where('cc.cronograma_id', $cronograma)->where('cp.tipo', 'matricula')->first(['cc.*', 'cp.nombre']) : null;
            $cargo = null;
            if ($cuota) {
                $cargo = (int) DB::table('cargos')->insertGetId([
                    'institucion_id' => $inst, 'alumno_id' => $alumnoId, 'matricula_id' => $matricula, 'concepto_cobro_id' => $cuota->concepto_cobro_id,
                    'cronograma_cuota_id' => $cuota->id, 'descripcion' => $cuota->nombre, 'emision' => min(now()->toDateString(), (string) $cuota->emision),
                    'vencimiento' => max(now()->toDateString(), (string) $cuota->vencimiento), 'monto' => $cuota->monto,
                ]);
                DB::table('cargos')->where('id', $cargo)->update(['codigo_recaudacion' => GeneradorCargos::codigo($cargo)]);
            } else {
                // Sin cuota de matrícula en el cronograma: la ratificación queda confirmada de una vez.
                DB::table('matriculas')->where('id', $matricula)->update(['estado' => 'matriculado']);
                app(GeneradorCargos::class)->generar($matricula);
            }
            $this->auditoria->registrar('matricula_ratificada', 'matriculas', $matricula, null, [
                'alumno_id' => $alumnoId, 'seccion_id' => $opcion['seccion']['id'], 'condiciones_version' => $condiciones->version, 'canal' => 'app', 'datos_actualizados' => array_keys($cambios),
            ]);

            return ['matricula_id' => $matricula, 'cargo_id' => $cargo, 'estado' => $cargo ? 'reservado' : 'matriculado'];
        });
    }

    /** Lo llama Cobros: si se pagó por completo el cargo de matrícula de una reserva, se confirma. */
    public static function alPagar(array $cargoIds): void
    {
        $reservas = DB::table('cargos as c')->join('matriculas as m', 'm.id', '=', 'c.matricula_id')->join('conceptos_cobro as cp', 'cp.id', '=', 'c.concepto_cobro_id')
            ->whereIn('c.id', $cargoIds)->where('c.estado', 'pagado')->where('cp.tipo', 'matricula')->where('m.estado', 'reservado')->pluck('m.id');
        foreach ($reservas as $m) {
            DB::table('matriculas')->where('id', $m)->update(['estado' => 'matriculado', 'fecha_matricula' => now()->toDateString()]);
            app(GeneradorCargos::class)->generar((int) $m);
            Admision::alMatricular((int) DB::table('matriculas')->where('id', $m)->value('alumno_id'));
        }
    }

    /**
     * Lo llama Cobros al anular un pago: si la matrícula de una ratificación hecha en la app se
     * confirmó con ese pago y el cargo de matrícula vuelve a deberse, la matrícula regresa a
     * «reservado» y se quitan las pensiones que se generaron al confirmarla. Si alguna de esas
     * pensiones ya tiene movimientos (pagos, comprobantes, convenios…), no se toca nada y queda en
     * el registro para que lo vea tesorería.
     *
     * @param  int[]  $cargoIds
     * @return int[] matrículas que volvieron a «reservado»
     */
    public static function alAnular(array $cargoIds): array
    {
        $candidatas = DB::table('cargos as c')->join('matriculas as m', 'm.id', '=', 'c.matricula_id')->join('conceptos_cobro as cp', 'cp.id', '=', 'c.concepto_cobro_id')
            ->whereIn('c.id', $cargoIds)->whereIn('c.estado', ['pendiente', 'parcial'])->where('cp.tipo', 'matricula')
            ->where('m.estado', 'matriculado')->where('m.tipo', 'ratificacion')
            ->whereExists(fn ($q) => $q->from('auditoria as a')->whereColumn('a.registro_id', 'm.id')->where('a.tabla', 'matriculas')->where('a.accion', 'matricula_ratificada'))
            ->get(['m.id as matricula_id', 'c.id as cargo_id']);
        $revertidas = [];
        foreach ($candidatas as $c) {
            $pensiones = DB::table('cargos')->where('matricula_id', $c->matricula_id)->where('id', '<>', $c->cargo_id)->pluck('id');
            $conMovimientos = DB::table('cargos')->whereIn('id', $pensiones)->where(fn ($q) => $q->where('pagado', '>', 0)->orWhere('estado', '<>', 'pendiente'))->exists();
            foreach (['pago_aplicaciones', 'comprobante_items', 'recaudacion_lineas', 'convenio_cargos', 'tramites', 'com_consumos', 'tienda_pedidos'] as $tabla) {
                $conMovimientos = $conMovimientos || DB::table($tabla)->whereIn('cargo_id', $pensiones)->exists();
            }
            if ($conMovimientos) {
                Log::warning('Pago de matrícula anulado: la ratificación sigue confirmada porque sus pensiones ya tienen movimientos.', ['matricula_id' => $c->matricula_id]);

                continue;
            }
            DB::table('cargos')->whereIn('id', $pensiones)->delete();
            DB::table('matriculas')->where('id', $c->matricula_id)->update(['estado' => 'reservado']);
            app(Auditoria::class)->registrar('matricula_reservada_por_anulacion', 'matriculas', (int) $c->matricula_id, ['estado' => 'matriculado'], ['estado' => 'reservado', 'pensiones_quitadas' => $pensiones->count()]);
            $revertidas[] = (int) $c->matricula_id;
        }

        return $revertidas;
    }
}
