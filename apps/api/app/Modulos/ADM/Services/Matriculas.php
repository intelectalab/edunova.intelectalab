<?php

namespace App\Modulos\ADM\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Dompdf\Dompdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Matrícula y sus movimientos.
 *
 * - EDUN-56 / ADM-H14 · Ventanilla: el mismo flujo que en línea (sección con vacantes, el
 *   apoderado acepta las condiciones económicas vigentes, se genera la matrícula y los cargos
 *   del cronograma) y la constancia en PDF.
 * - EDUN-57 / ADM-H15 · Retiro o traslado: motivo e IE destino, anula los cargos futuros, quita
 *   el acceso al aula y a Moodle y deja el aviso para la conciliación SIAGIE.
 * - EDUN-58 / ADM-H16 · Cambio de sección del mismo grado: conserva las notas de los periodos
 *   abiertos (pasan a la sección nueva) y deja el aviso para el SIAGIE y Moodle.
 */
class Matriculas
{
    public function __construct(private RegistroAlumnos $registro, private CondicionesEconomicas $condiciones, private Auditoria $auditoria) {}

    /**
     * @return array{matricula_id: int, cargos: int, aviso: string|null}
     */
    public function matricularEnVentanilla(int $alumnoId, int $seccionId, int $apoderadoId, string $tipo, string $ip): array
    {
        $alumno = DB::table('alumnos')->whereNull('eliminado_at')->find($alumnoId);
        abort_if(! $alumno, 404);
        $seccion = DB::table('secciones')->find($seccionId);
        if (! $seccion) {
            throw ValidationException::withMessages(['seccion_id' => ['Elige una sección del colegio.']]);
        }
        $condiciones = $this->condiciones->vigente((int) $seccion->anio_escolar_id);
        if (! $condiciones) {
            throw ValidationException::withMessages(['condiciones' => ['Publica primero las condiciones económicas del año: el apoderado debe aceptarlas antes de matricular.']]);
        }
        if (! DB::table('alumno_apoderado')->where('alumno_id', $alumnoId)->where('apoderado_id', $apoderadoId)->exists()) {
            throw ValidationException::withMessages(['apoderado_id' => ['Elige a un apoderado del alumno.']]);
        }
        if (DB::table('matriculas')->where('alumno_id', $alumnoId)->where('anio_escolar_id', $seccion->anio_escolar_id)->exists()) {
            throw ValidationException::withMessages(['seccion_id' => ['El alumno ya está matriculado en ese año.']]);
        }

        // Deuda de años anteriores: se avisa, no se bloquea (parámetro legal por confirmar).
        $deuda = (float) DB::table('cargos')->where('alumno_id', $alumnoId)->whereIn('estado', ['pendiente', 'parcial'])
            ->where('vencimiento', '<', now()->toDateString())->sum(DB::raw('monto - descuento + mora - pagado'));

        return DB::transaction(function () use ($alumnoId, $seccionId, $tipo, $condiciones, $apoderadoId, $ip, $deuda, $alumno) {
            $this->registro->matricular($alumnoId, $seccionId, $tipo);
            $matricula = DB::table('matriculas')->where('alumno_id', $alumnoId)->where('seccion_id', $seccionId)->value('id');
            DB::table('condiciones_aceptaciones')->insert([
                'institucion_id' => ContextoInstitucion::requerido(), 'condiciones_economicas_id' => $condiciones->id,
                'matricula_id' => $matricula, 'apoderado_id' => $apoderadoId, 'ip' => $ip,
            ]);
            if ($alumno->estado !== 'activo') {
                DB::table('alumnos')->where('id', $alumnoId)->update(['estado' => 'activo']);
            }
            $cargos = $this->generarCargos((int) $matricula);
            $this->auditoria->registrar('matricula_registrada', 'matriculas', (int) $matricula, null, [
                'alumno_id' => $alumnoId, 'seccion_id' => $seccionId, 'condiciones_version' => $condiciones->version, 'cargos' => $cargos['creados'], 'canal' => 'ventanilla',
            ]);

            $aviso = $cargos['cronograma'] ? null : 'El año no tiene cronograma de pagos: no se generaron cargos.';
            if ($deuda > 0) {
                $aviso = trim(($aviso ?? '').' El alumno tiene una deuda vencida de S/ '.number_format($deuda, 2).'.');
            }

            return ['matricula_id' => (int) $matricula, 'cargos' => $cargos['creados'], 'aviso' => $aviso];
        });
    }

    /**
     * Cargos del cronograma del año (del nivel, o el general): matrícula y cuota de ingreso
     * siempre; pensiones desde el mes de la matrícula. Aplica los beneficios del alumno.
     *
     * @return array{creados: int, cronograma: bool}
     */
    public function generarCargos(int $matriculaId): array
    {
        $m = DB::table('matriculas as m')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->where('m.id', $matriculaId)->first(['m.id', 'm.alumno_id', 'm.anio_escolar_id', 'm.fecha_matricula', 'g.nivel']);
        $cronograma = DB::table('cronogramas')->where('anio_escolar_id', $m->anio_escolar_id)
            ->where(fn ($q) => $q->where('nivel', $m->nivel)->orWhereNull('nivel'))->orderByRaw('nivel is null')->value('id');
        if (! $cronograma) {
            return ['creados' => 0, 'cronograma' => false];
        }
        $beneficios = DB::table('alumno_beneficios as ab')->join('beneficios as b', 'b.id', '=', 'ab.beneficio_id')
            ->where('ab.alumno_id', $m->alumno_id)->where('ab.anio_escolar_id', $m->anio_escolar_id)->where('b.activo', true)->get(['b.tipo', 'b.valor', 'b.aplica_a']);
        $mesMatricula = substr((string) $m->fecha_matricula, 0, 7);
        $creados = 0;
        foreach (DB::table('cronograma_cuotas as cc')->join('conceptos_cobro as cp', 'cp.id', '=', 'cc.concepto_cobro_id')
            ->where('cc.cronograma_id', $cronograma)->orderBy('cc.numero')
            ->get(['cc.id', 'cc.concepto_cobro_id', 'cc.mes', 'cc.emision', 'cc.vencimiento', 'cc.monto', 'cp.nombre', 'cp.tipo']) as $c) {
            if ($c->tipo === 'pension' && $c->mes < $mesMatricula) {
                continue; // Ingresó a mitad de año: no se cobran las pensiones anteriores.
            }
            if (DB::table('cargos')->where('matricula_id', $matriculaId)->where('cronograma_cuota_id', $c->id)->exists()) {
                continue;
            }
            $descuento = 0.0;
            foreach ($beneficios as $b) {
                if ($b->aplica_a === $c->tipo) {
                    $descuento += $b->tipo === 'porcentaje' ? round($c->monto * $b->valor / 100, 2) : (float) $b->valor;
                }
            }
            DB::table('cargos')->insert([
                'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $m->alumno_id, 'matricula_id' => $matriculaId,
                'concepto_cobro_id' => $c->concepto_cobro_id, 'cronograma_cuota_id' => $c->id,
                'descripcion' => $c->tipo === 'pension' ? "{$c->nombre} ".Carbon::parse($c->mes.'-01')->locale('es')->translatedFormat('F Y') : $c->nombre,
                'emision' => $c->emision, 'vencimiento' => $c->vencimiento, 'monto' => $c->monto, 'descuento' => min($descuento, (float) $c->monto),
            ]);
            $creados++;
        }

        return ['creados' => $creados, 'cronograma' => true];
    }

    /** Constancia de matrícula en PDF. */
    public function constancia(int $matriculaId): string
    {
        $m = DB::table('matriculas as m')
            ->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->join('secciones as s', 's.id', '=', 'm.seccion_id')
            ->join('grados as g', 'g.id', '=', 's.grado_id')->join('anios_escolares as y', 'y.id', '=', 'm.anio_escolar_id')
            ->leftJoin('condiciones_aceptaciones as ca', 'ca.matricula_id', '=', 'm.id')
            ->leftJoin('condiciones_economicas as ce', 'ce.id', '=', 'ca.condiciones_economicas_id')
            ->leftJoin('apoderados as ap', 'ap.id', '=', 'ca.apoderado_id')
            ->where('m.id', $matriculaId)
            ->first(['m.id', 'm.fecha_matricula', 'm.estado', 'm.tipo', 'a.apellido_paterno', 'a.apellido_materno', 'a.nombres', 'a.numero_documento',
                'a.codigo_estudiante_siagie', 'g.nombre as grado', 'g.nivel', 's.nombre as seccion', 's.turno', 'y.anio', 'ca.aceptado_at', 'ce.version',
                DB::raw("ap.nombres || ' ' || ap.apellidos as apoderado"), 'ap.numero_documento as apoderado_dni']);
        abort_if(! $m, 404);
        $i = DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->first(['nombre', 'codigo_modular', 'ugel', 'direccion', 'configuracion']);
        $servicio = DB::table('servicios_educativos')->where('nivel', $m->nivel)->value('codigo_modular') ?? $i->codigo_modular;
        $director = json_decode($i->configuracion ?? '{}', true)['datos']['director_nombre'] ?? null;
        $e = fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        $alumno = implode(' ', array_filter([$m->apellido_paterno, $m->apellido_materno])).', '.$m->nombres;
        $fecha = Carbon::parse($m->fecha_matricula)->locale('es')->translatedFormat('d \d\e F \d\e Y');
        $cargos = DB::table('cargos')->where('matricula_id', $matriculaId)->where('estado', '<>', 'anulado')->orderBy('vencimiento')
            ->get(['descripcion', 'vencimiento', 'monto', 'descuento']);
        $filas = $cargos->map(fn ($c) => '<tr><td>'.$e($c->descripcion).'</td><td>'.$e(Carbon::parse($c->vencimiento)->format('d/m/Y')).'</td><td class="n">S/ '
            .number_format($c->monto - $c->descuento, 2).'</td></tr>')->implode('');

        $html = '<html><head><meta charset="utf-8"><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f3d5e; margin: 10px 24px; }
            h1 { font-size: 18px; text-align: center; margin: 18px 0 4px; } .sub { text-align: center; color: #64748b; margin: 0 0 18px; }
            table.datos td { padding: 4px 6px; } table.datos td:first-child { color: #64748b; width: 160px; }
            table.cargos { width: 100%; border-collapse: collapse; margin-top: 6px; } table.cargos th, table.cargos td { border: 1px solid #d8e0e8; padding: 4px 6px; }
            table.cargos th { background: #eef4fa; text-align: left; } .n { text-align: right; } .firma { margin-top: 60px; text-align: center; }
            .pie { margin-top: 24px; font-size: 9px; color: #64748b; }
            </style></head><body>
            <div style="font-size:13px"><b>'.$e($i->nombre).'</b><br><span style="color:#64748b">Código modular '.$e($servicio).' · '.$e($i->ugel).'</span></div>
            <h1>Constancia de matrícula '.$e($m->anio).'</h1><p class="sub">N.° '.$e(str_pad((string) $m->id, 6, '0', STR_PAD_LEFT)).'</p>
            <p>Se deja constancia de que el alumno(a) <b>'.$e($alumno).'</b> está matriculado(a) en el año escolar '.$e($m->anio).':</p>
            <table class="datos">
              <tr><td>DNI</td><td>'.$e($m->numero_documento ?? '—').'</td></tr>
              <tr><td>Código del estudiante</td><td>'.$e($m->codigo_estudiante_siagie ?? '—').'</td></tr>
              <tr><td>Grado y sección</td><td>'.$e("{$m->grado} {$m->seccion}").' · turno '.$e(Horarios::TURNOS[$m->turno] ?? $m->turno).'</td></tr>
              <tr><td>Fecha de matrícula</td><td>'.$e($fecha).'</td></tr>
              <tr><td>Apoderado(a)</td><td>'.$e($m->apoderado ?? '—').($m->apoderado_dni ? ' · DNI '.$e($m->apoderado_dni) : '').'</td></tr>
              <tr><td>Condiciones económicas</td><td>'.($m->version ? 'Aceptó la versión '.$e($m->version).' el '.$e(Carbon::parse($m->aceptado_at)->timezone('America/Lima')->format('d/m/Y H:i')) : '—').'</td></tr>
            </table>'
            .($filas ? '<p style="margin-top:14px"><b>Cronograma de pagos</b></p><table class="cargos"><tr><th>Concepto</th><th>Vence</th><th class="n">Monto</th></tr>'.$filas.'</table>' : '')
            .'<div class="firma">______________________________<br>'.$e($director ?? 'Dirección').'<br>Director(a)</div>
            <p class="pie">Documento emitido por Edunova el '.$e(now()->timezone('America/Lima')->format('d/m/Y H:i')).'.</p>
            </body></html>';

        $pdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** EDUN-57 · Retiro o traslado de salida. */
    public function retirar(int $matriculaId, string $tipo, string $fecha, string $motivo, ?string $ieDestino, int $usuarioId): array
    {
        $m = DB::table('matriculas')->find($matriculaId);
        abort_if(! $m, 404);
        if (! in_array($m->estado, ['matriculado', 'reservado'], true)) {
            throw ValidationException::withMessages(['matricula' => ['Esta matrícula ya no está vigente.']]);
        }
        if ($tipo === 'traslado' && ! $ieDestino) {
            throw ValidationException::withMessages(['ie_destino' => ['Escribe la institución educativa de destino.']]);
        }

        return DB::transaction(function () use ($m, $tipo, $fecha, $motivo, $ieDestino, $usuarioId) {
            $institucion = ContextoInstitucion::requerido();
            DB::table('matriculas')->where('id', $m->id)->update(['estado' => $tipo === 'retiro' ? 'retirado' : 'trasladado']);
            DB::table('alumnos')->where('id', $m->alumno_id)->update(['estado' => 'retirado']);
            $movimiento = (int) DB::table('movimientos_matricula')->insertGetId([
                'institucion_id' => $institucion, 'matricula_id' => $m->id, 'tipo' => $tipo === 'retiro' ? 'retiro' : 'traslado_salida',
                'fecha' => $fecha, 'motivo' => $motivo, 'ie_destino' => $ieDestino, 'registrado_por' => $usuarioId,
            ]);

            // Cargos que vencen después del retiro y no tienen pagos: se anulan.
            $anulados = DB::table('cargos')->where('matricula_id', $m->id)->where('estado', 'pendiente')->where('pagado', 0)
                ->where('vencimiento', '>', $fecha)->update(['estado' => 'anulado']);

            // Acceso al aula virtual y a Moodle del alumno.
            $usuario = DB::table('alumnos')->where('id', $m->alumno_id)->value('user_id');
            $suspendido = false;
            if ($usuario) {
                DB::table('membresias')->where('user_id', $usuario)->where('rol_id', DB::table('roles')->where('codigo', 'alumno')->value('id'))->update(['activo' => false]);
                DB::table('moodle_usuarios')->where('user_id', $usuario)->update(['suspendido' => true]);
                $suspendido = true;
            }

            $nombre = $tipo === 'retiro' ? 'Retiro' : 'Traslado a '.$ieDestino;
            DB::table('siagie_pendientes')->insert([
                'institucion_id' => $institucion, 'alumno_id' => $m->alumno_id, 'matricula_id' => $m->id, 'movimiento_id' => $movimiento,
                'tipo' => $tipo === 'retiro' ? 'retiro' : 'traslado_salida', 'detalle' => mb_substr("{$nombre} el ".Carbon::parse($fecha)->format('d/m/Y').": {$motivo}", 0, 250),
            ]);
            $this->auditoria->registrar($tipo === 'retiro' ? 'alumno_retirado' : 'alumno_trasladado', 'matriculas', (int) $m->id, ['estado' => $m->estado],
                ['estado' => $tipo === 'retiro' ? 'retirado' : 'trasladado', 'fecha' => $fecha, 'motivo' => $motivo, 'ie_destino' => $ieDestino, 'cargos_anulados' => $anulados]);

            return ['cargos_anulados' => $anulados, 'acceso_suspendido' => $suspendido];
        });
    }

    /** EDUN-58 · Cambio de sección del mismo grado y año. */
    public function cambiarSeccion(int $matriculaId, int $seccionId, string $fecha, ?string $motivo, int $usuarioId): array
    {
        $m = DB::table('matriculas as m')->join('secciones as s', 's.id', '=', 'm.seccion_id')->where('m.id', $matriculaId)
            ->first(['m.id', 'm.alumno_id', 'm.anio_escolar_id', 'm.seccion_id', 'm.estado', 's.grado_id', 's.nombre']);
        abort_if(! $m, 404);
        $destino = DB::table('secciones')->find($seccionId);
        if ($m->estado !== 'matriculado') {
            throw ValidationException::withMessages(['matricula' => ['Esta matrícula ya no está vigente.']]);
        }
        if (! $destino || (int) $destino->anio_escolar_id !== (int) $m->anio_escolar_id || (int) $destino->grado_id !== (int) $m->grado_id || $seccionId === (int) $m->seccion_id) {
            throw ValidationException::withMessages(['seccion_id' => ['Elige otra sección del mismo grado y año.']]);
        }
        if ($destino->vacantes !== null && DB::table('matriculas')->where('seccion_id', $seccionId)->whereIn('estado', ['reservado', 'matriculado'])->count() >= $destino->vacantes) {
            throw ValidationException::withMessages(['seccion_id' => ["La sección {$destino->nombre} ya no tiene vacantes."]]);
        }

        return DB::transaction(function () use ($m, $seccionId, $destino, $fecha, $motivo, $usuarioId) {
            $institucion = ContextoInstitucion::requerido();
            DB::table('matriculas')->where('id', $m->id)->update(['seccion_id' => $seccionId]);
            // Las notas de los periodos abiertos pasan a la sección nueva; las de periodos cerrados quedan como están.
            $notas = DB::table('calificaciones')->where('alumno_id', $m->alumno_id)->where('seccion_id', $m->seccion_id)
                ->whereIn('periodo_id', DB::table('periodos')->where('anio_escolar_id', $m->anio_escolar_id)->where('estado', '<>', 'cerrado')->select('id'))
                ->update(['seccion_id' => $seccionId]);
            $movimiento = (int) DB::table('movimientos_matricula')->insertGetId([
                'institucion_id' => $institucion, 'matricula_id' => $m->id, 'tipo' => 'cambio_seccion', 'fecha' => $fecha,
                'motivo' => $motivo, 'seccion_destino_id' => $seccionId, 'registrado_por' => $usuarioId,
            ]);
            DB::table('siagie_pendientes')->insert([
                'institucion_id' => $institucion, 'alumno_id' => $m->alumno_id, 'matricula_id' => $m->id, 'movimiento_id' => $movimiento,
                'tipo' => 'cambio_seccion', 'detalle' => mb_substr("Cambio de la sección {$m->nombre} a la {$destino->nombre} el ".Carbon::parse($fecha)->format('d/m/Y'), 0, 250),
            ]);
            // Moodle: la matrícula en los cursos de la sección nueva la hace el conector en su próxima sincronización.
            $this->auditoria->registrar('seccion_cambiada', 'matriculas', (int) $m->id, ['seccion_id' => (int) $m->seccion_id], ['seccion_id' => $seccionId, 'notas_movidas' => $notas, 'motivo' => $motivo]);

            return ['notas_movidas' => $notas];
        });
    }

    /** @return list<array<string, mixed>> */
    public function pendientesSiagie(bool $todos = false): array
    {
        return DB::table('siagie_pendientes as p')->join('alumnos as a', 'a.id', '=', 'p.alumno_id')
            ->when(! $todos, fn ($q) => $q->whereNull('p.atendido_at'))->orderByDesc('p.id')
            ->get(['p.id', 'p.tipo', 'p.detalle', 'p.created_at', 'p.atendido_at', 'a.codigo_estudiante_siagie',
                DB::raw("a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ', ' || a.nombres as alumno")])
            ->map(fn ($p) => (array) $p)->all();
    }

    public function atenderPendiente(int $id, int $usuarioId): void
    {
        $n = DB::table('siagie_pendientes')->where('id', $id)->whereNull('atendido_at')->update(['atendido_at' => now(), 'atendido_por' => $usuarioId]);
        abort_if($n === 0, 404);
    }
}
