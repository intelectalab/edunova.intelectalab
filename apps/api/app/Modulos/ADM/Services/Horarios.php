<?php

namespace App\Modulos\ADM\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Dompdf\Dompdf;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-49 / ADM-H07 · Horario semanal sin cruces.
 *
 * - Bloques de hora por turno (con recreos).
 * - Vista por sección y por docente.
 * - Un docente ni un ambiente pueden estar en dos secciones en el mismo bloque del año:
 *   se avisa con un mensaje claro y la base lo impide igual (índices únicos por año).
 * - Exportación a PDF.
 */
class Horarios
{
    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'];

    public const TURNOS = ['manana' => 'Mañana', 'tarde' => 'Tarde', 'noche' => 'Noche'];

    /** Bloques sugeridos: 7 horas pedagógicas de 45 minutos con un recreo. */
    public const SUGERIDOS = [
        'manana' => [['08:00', '08:45'], ['08:45', '09:30'], ['09:30', '10:15'], ['10:15', '10:35', true], ['10:35', '11:20'], ['11:20', '12:05'], ['12:05', '12:50'], ['12:50', '13:35']],
        'tarde' => [['13:30', '14:15'], ['14:15', '15:00'], ['15:00', '15:45'], ['15:45', '16:05', true], ['16:05', '16:50'], ['16:50', '17:35'], ['17:35', '18:20']],
        'noche' => [['18:30', '19:15'], ['19:15', '20:00'], ['20:00', '20:45'], ['20:45', '21:30']],
    ];

    public function __construct(private Auditoria $auditoria) {}

    public function bloques(?string $turno = null): array
    {
        return DB::table('bloques_horarios')->when($turno, fn ($q, $t) => $q->where('turno', $t))->orderBy('turno')->orderBy('orden')
            ->get(['id', 'turno', 'orden', 'hora_inicio', 'hora_fin', 'es_recreo'])
            ->map(fn ($b) => ['id' => (int) $b->id, 'turno' => $b->turno, 'orden' => (int) $b->orden, 'hora_inicio' => substr($b->hora_inicio, 0, 5),
                'hora_fin' => substr($b->hora_fin, 0, 5), 'es_recreo' => (bool) $b->es_recreo])->all();
    }

    /** @param list<array{hora_inicio: string, hora_fin: string, es_recreo?: bool}> $bloques */
    public function guardarBloques(string $turno, array $bloques): array
    {
        usort($bloques, fn ($a, $b) => strcmp($a['hora_inicio'], $b['hora_inicio']));
        $anterior = null;
        foreach ($bloques as $b) {
            if ($b['hora_fin'] <= $b['hora_inicio']) {
                throw ValidationException::withMessages(['bloques' => ["El bloque de {$b['hora_inicio']} termina antes de empezar."]]);
            }
            if ($anterior && $b['hora_inicio'] < $anterior['hora_fin']) {
                throw ValidationException::withMessages(['bloques' => ["El bloque de {$b['hora_inicio']} se cruza con el de {$anterior['hora_inicio']}."]]);
            }
            $anterior = $b;
        }
        $institucion = ContextoInstitucion::requerido();
        $actuales = DB::table('bloques_horarios')->where('turno', $turno)->orderBy('orden')->get();
        try {
            DB::transaction(function () use ($bloques, $actuales, $turno, $institucion) {
                // Se reusan los bloques por orden; los que sobran se borran (si no tienen clases).
                DB::table('bloques_horarios')->where('turno', $turno)->update(['orden' => DB::raw('orden + 100')]);
                foreach (array_values($bloques) as $i => $b) {
                    $fila = ['orden' => $i + 1, 'hora_inicio' => $b['hora_inicio'], 'hora_fin' => $b['hora_fin'], 'es_recreo' => (bool) ($b['es_recreo'] ?? false)];
                    if (isset($actuales[$i])) {
                        DB::table('bloques_horarios')->where('id', $actuales[$i]->id)->update($fila);
                    } else {
                        DB::table('bloques_horarios')->insert($fila + ['institucion_id' => $institucion, 'turno' => $turno]);
                    }
                }
                $sobran = $actuales->slice(count($bloques))->pluck('id');
                if ($sobran->isNotEmpty()) {
                    DB::table('bloques_horarios')->whereIn('id', $sobran)->delete();
                }
                // Un bloque que pasa a recreo no puede tener clases.
                $recreoConClase = DB::table('horario_sesiones as h')->join('bloques_horarios as b', 'b.id', '=', 'h.bloque_horario_id')
                    ->where('b.turno', $turno)->where('b.es_recreo', true)->exists();
                if ($recreoConClase) {
                    throw ValidationException::withMessages(['bloques' => ['Un bloque marcado como recreo ya tiene clases en el horario.']]);
                }
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                throw ValidationException::withMessages(['bloques' => ['Hay bloques con clases asignadas: quita esas clases del horario antes de eliminarlos.']]);
            }
            throw $e;
        }
        $this->auditoria->registrar('bloques_editados', 'bloques_horarios', null, ['turno' => $turno, 'bloques' => $actuales->count()], ['turno' => $turno, 'bloques' => count($bloques)]);

        return $this->bloques($turno);
    }

    /** Horario de una sección o de un docente en el año. */
    public function ver(int $anioId, ?int $seccionId, ?int $personalId): array
    {
        PlanEstudios::anio($anioId);
        $consulta = DB::table('horario_sesiones as h')
            ->join('cargas_docentes as c', 'c.id', '=', 'h.carga_docente_id')
            ->join('areas_curriculares as a', 'a.id', '=', 'c.area_curricular_id')
            ->join('personal as p', 'p.id', '=', 'h.personal_id')
            ->join('secciones as s', 's.id', '=', 'h.seccion_id')
            ->join('grados as g', 'g.id', '=', 's.grado_id')
            ->leftJoin('ambientes as am', 'am.id', '=', 'h.ambiente_id')
            ->where('h.anio_escolar_id', $anioId)
            ->when($seccionId, fn ($q) => $q->where('h.seccion_id', $seccionId))
            ->when($personalId, fn ($q) => $q->where('h.personal_id', $personalId));
        $celdas = $consulta->get(['h.id', 'h.dia_semana', 'h.bloque_horario_id', 'h.carga_docente_id', 'h.ambiente_id', 'a.nombre as area',
            DB::raw("p.nombres || ' ' || p.apellidos as docente"), DB::raw("g.nombre || ' ' || s.nombre as seccion"), 'am.nombre as ambiente'])
            ->map(fn ($c) => (array) $c)->all();

        if ($seccionId) {
            $seccion = DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->where('s.id', $seccionId)->first(['s.turno', DB::raw("g.nombre || ' ' || s.nombre as nombre")]);
            abort_if(! $seccion, 404);
            $turnos = [$seccion->turno];
            $titulo = "Horario de {$seccion->nombre}";
            $opciones = DB::table('cargas_docentes as c')->join('areas_curriculares as a', 'a.id', '=', 'c.area_curricular_id')->join('personal as p', 'p.id', '=', 'c.personal_id')
                ->where('c.seccion_id', $seccionId)->orderBy('a.orden')
                ->get(['c.id', 'a.nombre as area', DB::raw("p.nombres || ' ' || p.apellidos as docente"), 'c.horas_semanales'])->all();
        } else {
            $docente = DB::table('personal')->where('id', $personalId)->first(['nombres', 'apellidos']);
            abort_if(! $docente, 404);
            $turnos = DB::table('horario_sesiones as h')->join('secciones as s', 's.id', '=', 'h.seccion_id')
                ->where('h.anio_escolar_id', $anioId)->where('h.personal_id', $personalId)->distinct()->pluck('s.turno')->all() ?: ['manana'];
            $titulo = "Horario de {$docente->nombres} {$docente->apellidos}";
            $opciones = [];
        }

        $bloques = array_values(array_filter($this->bloques(), fn ($b) => in_array($b['turno'], $turnos, true)));
        $usaSabado = collect($celdas)->contains(fn ($c) => (int) $c['dia_semana'] === 6);

        return [
            'titulo' => $titulo, 'bloques' => $bloques, 'dias' => array_slice(self::DIAS, 0, $usaSabado ? 6 : 5, true),
            'celdas' => $celdas, 'cargas' => $opciones,
            'ambientes' => DB::table('ambientes')->orderBy('nombre')->get(['id', 'nombre']),
        ];
    }

    /** Asigna (o vacía, con carga null) una celda del horario de la sección. */
    public function asignar(int $seccionId, int $dia, int $bloqueId, ?int $cargaId, ?int $ambienteId): void
    {
        $seccion = DB::table('secciones')->find($seccionId);
        abort_if(! $seccion, 404);
        $bloque = DB::table('bloques_horarios')->find($bloqueId);
        if (! $bloque || $bloque->turno !== $seccion->turno) {
            throw ValidationException::withMessages(['bloque_horario_id' => ['Ese bloque no es del turno de la sección.']]);
        }
        $actual = DB::table('horario_sesiones')->where('seccion_id', $seccionId)->where('dia_semana', $dia)->where('bloque_horario_id', $bloqueId)->first();

        if ($cargaId === null) {
            if ($actual) {
                DB::table('horario_sesiones')->where('id', $actual->id)->delete();
                $this->auditoria->registrar('horario_editado', 'horario_sesiones', (int) $actual->id, ['carga_docente_id' => $actual->carga_docente_id], null);
            }

            return;
        }
        if ($bloque->es_recreo) {
            throw ValidationException::withMessages(['bloque_horario_id' => ['En el recreo no se asignan clases.']]);
        }
        $carga = DB::table('cargas_docentes')->where('id', $cargaId)->where('seccion_id', $seccionId)->first();
        if (! $carga) {
            throw ValidationException::withMessages(['carga_docente_id' => ['Esa área no tiene docente asignado en esta sección.']]);
        }

        // Cruces: se buscan antes para dar un mensaje claro.
        $hora = substr($bloque->hora_inicio, 0, 5);
        $diaNombre = mb_strtolower(self::DIAS[$dia]);
        $cruceDocente = $this->ocupado('h.personal_id', $carga->personal_id, $seccion->anio_escolar_id, $dia, $bloqueId, $seccionId);
        if ($cruceDocente) {
            throw ValidationException::withMessages(['carga_docente_id' => ["{$cruceDocente->persona} ya tiene clase el {$diaNombre} a las {$hora} en {$cruceDocente->seccion}."]]);
        }
        if ($ambienteId) {
            $cruceAmbiente = $this->ocupado('h.ambiente_id', $ambienteId, $seccion->anio_escolar_id, $dia, $bloqueId, $seccionId);
            if ($cruceAmbiente) {
                throw ValidationException::withMessages(['ambiente_id' => ["{$cruceAmbiente->ambiente} ya está ocupado el {$diaNombre} a las {$hora} por {$cruceAmbiente->seccion}."]]);
            }
        }

        $fila = ['carga_docente_id' => $cargaId, 'personal_id' => $carga->personal_id, 'ambiente_id' => $ambienteId];
        try {
            if ($actual) {
                DB::table('horario_sesiones')->where('id', $actual->id)->update($fila);
            } else {
                DB::table('horario_sesiones')->insert($fila + [
                    'institucion_id' => ContextoInstitucion::requerido(), 'seccion_id' => $seccionId, 'dia_semana' => $dia, 'bloque_horario_id' => $bloqueId,
                ]);
            }
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['carga_docente_id' => ['Ese bloque ya está ocupado por el docente o el ambiente en otra sección.']]);
            }
            throw $e;
        }
        $this->auditoria->registrar('horario_editado', 'horario_sesiones', $actual ? (int) $actual->id : null,
            $actual ? ['carga_docente_id' => $actual->carga_docente_id] : null, ['seccion_id' => $seccionId, 'dia' => $dia, 'bloque' => $hora] + $fila);
    }

    private function ocupado(string $columna, int $valor, int $anioId, int $dia, int $bloqueId, int $seccionId): ?object
    {
        return DB::table('horario_sesiones as h')
            ->join('secciones as s', 's.id', '=', 'h.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->join('personal as p', 'p.id', '=', 'h.personal_id')->leftJoin('ambientes as am', 'am.id', '=', 'h.ambiente_id')
            ->where('h.anio_escolar_id', $anioId)->where('h.dia_semana', $dia)->where('h.bloque_horario_id', $bloqueId)
            ->where($columna, $valor)->where('h.seccion_id', '<>', $seccionId)
            ->first([DB::raw("g.nombre || ' ' || s.nombre as seccion"), DB::raw("p.nombres || ' ' || p.apellidos as persona"), 'am.nombre as ambiente']);
    }

    /** PDF del horario (A4 horizontal). */
    public function pdf(int $anioId, ?int $seccionId, ?int $personalId): string
    {
        $h = $this->ver($anioId, $seccionId, $personalId);
        $colegio = (string) DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->value('nombre');
        $anio = PlanEstudios::anio($anioId)->anio;
        $celdas = collect($h['celdas'])->keyBy(fn ($c) => $c['dia_semana'].'-'.$c['bloque_horario_id']);
        $e = fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');

        $filas = '';
        foreach ($h['bloques'] as $b) {
            $filas .= '<tr><th class="hora">'.$e($b['hora_inicio'].' – '.$b['hora_fin']).'</th>';
            foreach (array_keys($h['dias']) as $d) {
                if ($b['es_recreo']) {
                    $filas .= '<td class="recreo">Recreo</td>';

                    continue;
                }
                $c = $celdas[$d.'-'.$b['id']] ?? null;
                $detalle = $c ? ($seccionId ? $c['docente'] : $c['seccion']).($c['ambiente'] ? ' · '.$c['ambiente'] : '') : '';
                $filas .= '<td>'.($c ? '<b>'.$e($c['area']).'</b><br><small>'.$e($detalle).'</small>' : '').'</td>';
            }
            $filas .= '</tr>';
        }
        $cabecera = '<tr><th></th>'.implode('', array_map(fn ($d) => '<th>'.$e($d).'</th>', $h['dias'])).'</tr>';

        $html = '<html><head><meta charset="utf-8"><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f3d5e; }
            h1 { font-size: 16px; margin: 0 0 2px; } p { margin: 0 0 10px; color: #64748b; }
            table { width: 100%; border-collapse: collapse; table-layout: fixed; }
            th, td { border: 1px solid #d8e0e8; padding: 5px; vertical-align: top; }
            th { background: #eef4fa; } .hora { width: 80px; white-space: nowrap; }
            .recreo { background: #f1f5f9; color: #64748b; text-align: center; } small { color: #475569; }
            </style></head><body>
            <h1>'.$e($h['titulo']).'</h1><p>'.$e($colegio).' · Año escolar '.$e($anio).'</p>
            <table>'.$cabecera.$filas.'</table></body></html>';

        $pdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return (string) $pdf->output();
    }
}
