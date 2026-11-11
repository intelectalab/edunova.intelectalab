<?php

namespace App\Modulos\PAG\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-72 · PAG-H04 Descuentos y becas.
 *
 * Tesorería propone el beneficio de un alumno con su sustento adjunto; dirección lo aprueba o
 * rechaza (queda quién y cuándo). Quien tiene pag.beneficios.aprobar lo asigna ya aprobado. Al
 * aprobar o quitar uno se recalculan los cargos pendientes (sin pagos) del alumno en ese año.
 */
class Beneficios
{
    public const MOTIVOS = ['hermanos' => 'Hermanos', 'pronto_pago' => 'Pronto pago', 'academica' => 'Académica', 'orfandad' => 'Orfandad', 'convenio' => 'Convenio', 'otro' => 'Otro'];

    public const APLICA = ['pension' => 'Pensiones', 'matricula' => 'Matrícula', 'cuota_ingreso' => 'Cuota de ingreso', 'todos' => 'Todos los conceptos'];

    public function __construct(private Auditoria $auditoria, private GeneradorCargos $cargos) {}

    public function tipos(): array
    {
        return DB::table('beneficios')->orderByDesc('activo')->orderBy('nombre')->get()->map(fn ($b) => array_merge((array) $b, ['valor' => (float) $b->valor]))->all();
    }

    public function guardarTipo(array $d, ?int $id = null): int
    {
        if ($d['tipo'] === 'porcentaje' && ((float) $d['valor'] <= 0 || (float) $d['valor'] > 100)) {
            throw ValidationException::withMessages(['valor' => ['El porcentaje va de 1 a 100.']]);
        }
        $fila = [
            'nombre' => trim($d['nombre']), 'tipo' => $d['tipo'], 'valor' => round((float) $d['valor'], 2), 'motivo' => $d['motivo'],
            'aplica_a' => $d['aplica_a'] ?? 'pension', 'activo' => (bool) ($d['activo'] ?? true),
        ];
        if ($id) {
            abort_if(! DB::table('beneficios')->where('id', $id)->exists(), 404);
            DB::table('beneficios')->where('id', $id)->update($fila);
            $this->auditoria->registrar('beneficio_actualizado', 'beneficios', $id, null, $fila);

            return $id;
        }
        $id = (int) DB::table('beneficios')->insertGetId($fila + ['institucion_id' => ContextoInstitucion::requerido()]);
        $this->auditoria->registrar('beneficio_creado', 'beneficios', $id, null, $fila);

        return $id;
    }

    public function asignaciones(int $anioId, ?string $estado = null): array
    {
        return DB::table('alumno_beneficios as ab')->join('beneficios as b', 'b.id', '=', 'ab.beneficio_id')->join('alumnos as a', 'a.id', '=', 'ab.alumno_id')
            ->leftJoin('users as s', 's.id', '=', 'ab.solicitado_por')->leftJoin('users as u', 'u.id', '=', 'ab.aprobado_por')
            ->leftJoin('archivos as f', 'f.id', '=', 'ab.sustento_archivo_id')
            ->where('ab.anio_escolar_id', $anioId)->when($estado, fn ($q) => $q->where('ab.estado', $estado))
            ->orderByRaw("ab.estado = 'pendiente' desc")->orderBy('a.apellido_paterno')
            ->get([
                'ab.id', 'ab.alumno_id', 'ab.beneficio_id', 'ab.estado', 'ab.observacion', 'ab.aprobado_at', 'ab.created_at', 'ab.sustento_archivo_id',
                'b.nombre as beneficio', 'b.tipo', 'b.valor', 'b.aplica_a', 'f.nombre_original as sustento',
                DB::raw("a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ', ' || a.nombres as alumno"),
                DB::raw("s.nombres || ' ' || s.apellidos as solicitado_por"), DB::raw("u.nombres || ' ' || u.apellidos as aprobado_por"),
            ])->map(fn ($f) => array_merge((array) $f, ['valor' => (float) $f->valor]))->all();
    }

    /** @return array{id: int, estado: string, recalculados: int} */
    public function asignar(int $alumnoId, int $beneficioId, int $anioId, int $archivoId, ?string $observacion, int $usuarioId, bool $puedeAprobar): array
    {
        abort_if(! DB::table('alumnos')->whereNull('eliminado_at')->where('id', $alumnoId)->exists(), 404);
        if (! DB::table('beneficios')->where('id', $beneficioId)->where('activo', true)->exists()) {
            throw ValidationException::withMessages(['beneficio_id' => ['Elige un beneficio activo.']]);
        }
        if (! DB::table('archivos')->where('id', $archivoId)->exists()) {
            throw ValidationException::withMessages(['sustento_archivo_id' => ['Adjunta el sustento del beneficio.']]);
        }
        if (DB::table('alumno_beneficios')->where(['alumno_id' => $alumnoId, 'beneficio_id' => $beneficioId, 'anio_escolar_id' => $anioId])->exists()) {
            throw ValidationException::withMessages(['beneficio_id' => ['El alumno ya tiene ese beneficio en el año.']]);
        }
        $estado = $puedeAprobar ? 'aprobado' : 'pendiente';

        return DB::transaction(function () use ($alumnoId, $beneficioId, $anioId, $archivoId, $observacion, $usuarioId, $estado) {
            $id = (int) DB::table('alumno_beneficios')->insertGetId([
                'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'beneficio_id' => $beneficioId, 'anio_escolar_id' => $anioId,
                'sustento_archivo_id' => $archivoId, 'solicitado_por' => $usuarioId, 'estado' => $estado, 'observacion' => $observacion,
                'aprobado_por' => $estado === 'aprobado' ? $usuarioId : null, 'aprobado_at' => $estado === 'aprobado' ? now() : null,
            ]);
            $this->auditoria->registrar($estado === 'aprobado' ? 'beneficio_aprobado' : 'beneficio_solicitado', 'alumno_beneficios', $id, null, [
                'alumno_id' => $alumnoId, 'beneficio_id' => $beneficioId, 'anio_escolar_id' => $anioId,
            ]);

            return ['id' => $id, 'estado' => $estado, 'recalculados' => $estado === 'aprobado' ? $this->cargos->recalcular($alumnoId, $anioId) : 0];
        });
    }

    /** @return array{estado: string, recalculados: int} */
    public function resolver(int $id, bool $aprobar, ?string $observacion, int $usuarioId): array
    {
        $ab = DB::table('alumno_beneficios')->find($id);
        abort_if(! $ab, 404);
        if ($ab->estado !== 'pendiente') {
            throw ValidationException::withMessages(['estado' => ['Esa solicitud ya fue resuelta.']]);
        }
        if (! $aprobar && ! trim((string) $observacion)) {
            throw ValidationException::withMessages(['observacion' => ['Indica el motivo del rechazo.']]);
        }
        $estado = $aprobar ? 'aprobado' : 'rechazado';

        return DB::transaction(function () use ($ab, $estado, $observacion, $usuarioId) {
            DB::table('alumno_beneficios')->where('id', $ab->id)->update([
                'estado' => $estado, 'aprobado_por' => $usuarioId, 'aprobado_at' => now(), 'observacion' => $observacion ?: $ab->observacion,
            ]);
            $this->auditoria->registrar($estado === 'aprobado' ? 'beneficio_aprobado' : 'beneficio_rechazado', 'alumno_beneficios', (int) $ab->id, ['estado' => 'pendiente'], ['estado' => $estado, 'observacion' => $observacion]);

            return ['estado' => $estado, 'recalculados' => $estado === 'aprobado' ? $this->cargos->recalcular((int) $ab->alumno_id, (int) $ab->anio_escolar_id) : 0];
        });
    }

    public function quitar(int $id): int
    {
        $ab = DB::table('alumno_beneficios')->find($id);
        abort_if(! $ab, 404);

        return DB::transaction(function () use ($ab) {
            DB::table('alumno_beneficios')->where('id', $ab->id)->delete();
            $this->auditoria->registrar('beneficio_retirado', 'alumno_beneficios', (int) $ab->id, (array) $ab, null);

            return $this->cargos->recalcular((int) $ab->alumno_id, (int) $ab->anio_escolar_id);
        });
    }
}
