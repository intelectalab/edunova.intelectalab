<?php

namespace App\Modulos\CAL\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SEG\Services\NotificacionesPush;
use Dompdf\Dompdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * CAL · Libretas, actas, cuadro de méritos y recuperación.
 *
 * - EDUN-99 · CAL-H09 Libreta PDF por alumno (notas, conclusiones, asistencia y conducta del
 *   periodo), publicación en la app y registro de cuándo la vio el padre.
 * - EDUN-100 · CAL-H10 La familia ve notas y libreta por hijo y periodo, sin bloqueo por deuda.
 * - EDUN-104 · CAL-H14 Cuadro de méritos y acta por sección y año, en PDF y Excel.
 * - EDUN-105 · CAL-H15 Nota de recuperación por competencia que actualiza la situación final.
 */
class Libretas
{
    public function __construct(private AlmacenArchivos $almacen, private Auditoria $auditoria, private NotificacionesPush $push) {}

    /** Notas del alumno en el periodo agrupadas por área. */
    public static function notasPorArea(int $alumnoId, int $periodoId): array
    {
        return DB::table('calificaciones as c')->join('competencias as k', 'k.id', '=', 'c.competencia_id')->join('areas_curriculares as a', 'a.id', '=', 'k.area_curricular_id')
            ->where('c.alumno_id', $alumnoId)->where('c.periodo_id', $periodoId)->orderBy('a.orden')->orderBy('k.orden')->orderBy('k.numero')
            ->get(['a.nombre as area', 'k.numero', 'k.nombre as competencia', 'c.valor', 'c.conclusion_descriptiva'])
            ->groupBy('area')->map(fn ($g, $area) => ['area' => $area, 'competencias' => $g->map(fn ($x) => (array) $x)->values()->all()])->values()->all();
    }

    public function estado(int $seccionId, int $periodoId): array
    {
        $matriculas = DB::table('matriculas as m')->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->where('m.seccion_id', $seccionId)->where('m.estado', 'matriculado')
            ->orderBy('a.apellido_paterno')->orderBy('a.nombres')->get(['m.id', 'a.id as alumno_id', DB::raw("a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ', ' || a.nombres as alumno")]);
        $libretas = DB::table('libretas')->where('periodo_id', $periodoId)->whereIn('matricula_id', $matriculas->pluck('id'))->get()->keyBy('matricula_id');

        return $matriculas->map(fn ($m) => (array) $m + [
            'libreta' => isset($libretas[$m->id]) ? ['id' => $libretas[$m->id]->id, 'publicada_at' => $libretas[$m->id]->publicada_at, 'vista_por_padre_at' => $libretas[$m->id]->vista_por_padre_at] : null,
        ])->all();
    }

    /** Genera (o regenera) las libretas de la sección en el periodo. Regenerar quita la publicación. */
    public function generar(int $seccionId, int $periodoId): int
    {
        $p = DB::table('periodos')->find($periodoId);
        abort_if(! $p, 404);
        $s = DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->join('anios_escolares as y', 'y.id', '=', 's.anio_escolar_id')
            ->where('s.id', $seccionId)->first(['s.id', 'g.nombre as grado', 's.nombre', 'y.anio', 'y.id as anio_id']);
        abort_if(! $s || (int) $s->anio_id !== (int) $p->anio_escolar_id, 404);
        $inst = DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->first(['nombre', 'codigo_modular', 'ugel']);
        $n = 0;
        foreach ($this->estado($seccionId, $periodoId) as $m) {
            $pdf = $this->pdf($inst, $s, $p, $m);
            $archivo = $this->almacen->guardarContenido($pdf, "libreta-{$s->anio}-{$p->numero}-{$m['alumno_id']}.pdf", 'application/pdf');
            DB::table('libretas')->upsert([[
                'institucion_id' => ContextoInstitucion::requerido(), 'matricula_id' => $m['id'], 'periodo_id' => $periodoId, 'archivo_id' => $archivo->id,
                'publicada_at' => null, 'vista_por_padre_at' => null,
            ]], ['matricula_id', 'periodo_id'], ['archivo_id', 'publicada_at', 'vista_por_padre_at']);
            $n++;
        }
        $this->auditoria->registrar('libretas_generadas', 'libretas', null, null, ['seccion_id' => $seccionId, 'periodo_id' => $periodoId, 'libretas' => $n]);

        return $n;
    }

    /** Publica en la app (la deuda nunca bloquea la libreta) y avisa a las familias. */
    public function publicar(int $seccionId, int $periodoId): int
    {
        $ids = DB::table('libretas as l')->join('matriculas as m', 'm.id', '=', 'l.matricula_id')->where('m.seccion_id', $seccionId)->where('l.periodo_id', $periodoId)->whereNull('l.publicada_at')->pluck('l.id');
        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['periodo_id' => ['No hay libretas generadas sin publicar.']]);
        }
        DB::table('libretas')->whereIn('id', $ids)->update(['publicada_at' => now()]);
        $padres = DB::table('libretas as l')->join('matriculas as m', 'm.id', '=', 'l.matricula_id')->join('alumno_apoderado as aa', 'aa.alumno_id', '=', 'm.alumno_id')
            ->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->whereIn('l.id', $ids)->whereNotNull('p.user_id')->pluck('p.user_id')->unique()->map(fn ($u) => (int) $u)->values()->all();
        if ($padres) {
            $periodo = DB::table('periodos')->where('id', $periodoId)->value('nombre');
            $this->push->enviar($padres, 'notas', "Libreta del {$periodo} publicada", 'Ya puedes ver las notas y descargar la libreta.', ['pantalla' => 'notas', 'id' => $periodoId]);
        }
        $this->auditoria->registrar('libretas_publicadas', 'libretas', null, null, ['seccion_id' => $seccionId, 'periodo_id' => $periodoId, 'libretas' => $ids->count()]);

        return $ids->count();
    }

    /** EDUN-100 · Lo que ve la familia: periodos con libreta publicada, notas y PDF. */
    public function paraFamilia(array $hijos): array
    {
        return DB::table('libretas as l')->join('matriculas as m', 'm.id', '=', 'l.matricula_id')->join('periodos as p', 'p.id', '=', 'l.periodo_id')
            ->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->whereIn('m.alumno_id', $hijos)->whereNotNull('l.publicada_at')
            ->orderBy('a.nombres')->orderBy('p.numero')->get(['l.id', 'm.alumno_id', 'a.nombres as alumno', 'p.id as periodo_id', 'p.nombre as periodo', 'l.publicada_at', 'l.vista_por_padre_at'])
            ->groupBy('alumno_id')->map(fn ($g, $id) => ['alumno_id' => (int) $id, 'alumno' => $g->first()->alumno, 'periodos' => $g->map(fn ($l) => (array) $l + ['areas' => self::notasPorArea((int) $id, (int) $l->periodo_id)])->values()->all()])
            ->values()->all();
    }

    /** Enlace de descarga para la familia; la primera vez queda registrada la vista. */
    public function descargaFamilia(int $libretaId, array $hijos): string
    {
        $l = DB::table('libretas as l')->join('matriculas as m', 'm.id', '=', 'l.matricula_id')->where('l.id', $libretaId)->whereNotNull('l.publicada_at')->first(['l.*', 'm.alumno_id']);
        abort_unless($l && in_array((int) $l->alumno_id, $hijos, true), 404);
        if (! $l->vista_por_padre_at) {
            DB::table('libretas')->where('id', $libretaId)->update(['vista_por_padre_at' => now()]);
        }

        return $this->almacen->urlTemporal(Archivo::query()->findOrFail($l->archivo_id));
    }

    private function pdf(object $inst, object $s, object $p, array $m): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $areas = self::notasPorArea((int) $m['alumno_id'], (int) $p->id);
        $asis = Asistencia::resumen((int) $m['alumno_id'], (string) $p->fecha_inicio, (string) $p->fecha_fin);
        $conducta = DB::table('incidencias_conducta')->where('alumno_id', $m['alumno_id'])->whereBetween('fecha', [$p->fecha_inicio, $p->fecha_fin])->orderBy('fecha')->get(['fecha', 'tipo', 'detalle']);
        $filas = '';
        foreach ($areas as $a) {
            foreach ($a['competencias'] as $i => $k) {
                $filas .= '<tr>'.($i === 0 ? '<td rowspan="'.count($a['competencias']).'"><b>'.$e($a['area']).'</b></td>' : '').'<td>C'.$k['numero'].'. '.$e($k['competencia']).'</td><td class="n"><b>'.$e($k['valor']).'</b></td></tr>'
                    .($k['conclusion_descriptiva'] ? '<tr><td></td><td colspan="2" class="c">'.$e($k['conclusion_descriptiva']).'</td></tr>' : '');
            }
        }
        $cond = $conducta->map(fn ($c) => '<li>'.$e(Carbon::parse($c->fecha)->format('d/m')).' · '.($c->tipo === 'merito' ? 'Mérito' : 'Incidencia').': '.$e($c->detalle).'</li>')->implode('');
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#111}h1{font-size:14px;margin:0}table{width:100%;border-collapse:collapse;margin-top:8px}
            td,th{border:1px solid #ccc;padding:4px;vertical-align:top}.n{text-align:center;width:40px}.c{font-size:9px;color:#444;font-style:italic}</style></head><body>'
            .'<h1>'.$e($inst->nombre).'</h1><p>Código modular '.$e($inst->codigo_modular).' · '.$e($inst->ugel).'</p>'
            .'<p><b>Libreta de notas · '.$e($p->nombre).' '.$e($s->anio).'</b><br>Alumno(a): '.$e($m['alumno']).' · '.$e($s->grado).' '.$e($s->nombre).'</p>'
            .'<table><thead><tr><th>Área</th><th>Competencia</th><th>Nota</th></tr></thead><tbody>'.($filas ?: '<tr><td colspan="3">Sin notas registradas.</td></tr>').'</tbody></table>'
            .'<p><b>Asistencia:</b> '.$asis['P'].' asistencias, '.$asis['T'].' tardanzas, '.$asis['F'].' faltas, '.$asis['J'].' justificadas.</p>'
            .'<p><b>Conducta:</b></p>'.($cond ? "<ul>{$cond}</ul>" : '<p>Sin registros en el periodo.</p>').'</body></html>';
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->render();

        return (string) $pdf->output();
    }

    // ── EDUN-104 / EDUN-105 · Acta, méritos y recuperación

    /** Nota final por competencia (último periodo con nota o la de recuperación) y situación final. */
    public function acta(int $seccionId): array
    {
        $s = DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->join('anios_escolares as y', 'y.id', '=', 's.anio_escolar_id')
            ->where('s.id', $seccionId)->first(['s.id', 'g.nombre as grado', 's.nombre', 'g.escala_calificacion as escala', 'y.anio', 'y.id as anio_id']);
        abort_if(! $s, 404);
        $periodos = DB::table('periodos')->where('anio_escolar_id', $s->anio_id)->orderBy('numero')->pluck('id', 'numero');
        $alumnos = DB::table('matriculas as m')->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->where('m.seccion_id', $seccionId)->where('m.estado', 'matriculado')
            ->orderBy('a.apellido_paterno')->orderBy('a.nombres')->get(['a.id', DB::raw("a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ', ' || a.nombres as nombre")]);
        $notas = DB::table('calificaciones as c')->join('periodos as p', 'p.id', '=', 'c.periodo_id')->whereIn('c.alumno_id', $alumnos->pluck('id'))->whereIn('c.periodo_id', $periodos->values())
            ->whereNotNull('c.valor')->orderBy('p.numero')->get(['c.alumno_id', 'c.competencia_id', 'c.valor', 'p.numero']);
        $recuperacion = DB::table('evaluaciones_recuperacion')->where('anio_escolar_id', $s->anio_id)->whereIn('alumno_id', $alumnos->pluck('id'))->whereNotNull('valor')
            ->get()->keyBy(fn ($r) => $r->alumno_id.'-'.$r->competencia_id);
        $competencias = DB::table('competencias as k')->join('areas_curriculares as a', 'a.id', '=', 'k.area_curricular_id')
            ->whereIn('k.id', $notas->pluck('competencia_id')->unique())->orderBy('a.orden')->orderBy('k.orden')->orderBy('k.numero')
            ->get(['k.id', 'k.numero', 'a.nombre as area']);
        $num = fn (?string $v) => $v === null ? null : ($s->escala === 'literal' ? (RegistroNotas::LITERAL[$v] ?? null) : (int) $v);

        $filas = $alumnos->map(function ($a) use ($notas, $competencias, $recuperacion, $num, $s) {
            $mias = $notas->where('alumno_id', $a->id);
            $finales = [];
            foreach ($competencias as $k) {
                $ultima = $mias->where('competencia_id', $k->id)->sortByDesc('numero')->first()?->valor;
                $rec = $recuperacion[$a->id.'-'.$k->id]->valor ?? null;
                $finales[$k->id] = ['final' => $rec ?? $ultima, 'anual' => $ultima, 'recuperacion' => $rec];
            }
            $valores = array_filter(array_map(fn ($f) => $num($f['final']), $finales), fn ($v) => $v !== null);
            $desaprobadas = count(array_filter($finales, fn ($f) => $f['final'] !== null && ($s->escala === 'literal' ? $f['final'] === 'C' : (int) $f['final'] < 11)));

            return [
                'alumno_id' => $a->id, 'alumno' => $a->nombre, 'notas' => $finales, 'promedio' => $valores ? round(array_sum($valores) / count($valores), 2) : null,
                'desaprobadas' => $desaprobadas,
                'situacion' => $desaprobadas === 0 ? 'Promovido' : ($desaprobadas <= 3 ? 'Requiere recuperación' : 'Permanece en el grado'),
            ];
        });
        $ordenados = $filas->sortByDesc('promedio')->values();
        $puesto = 0;
        $merito = $ordenados->map(function ($f) use (&$puesto) {
            return $f['promedio'] === null ? null : ['puesto' => ++$puesto, 'alumno' => $f['alumno'], 'promedio' => $f['promedio']];
        })->filter()->values();

        return [
            'seccion' => (array) $s, 'competencias' => $competencias->map(fn ($k) => (array) $k)->all(), 'filas' => $filas->all(), 'merito' => $merito->all(),
        ];
    }

    public function registrarRecuperacion(int $alumnoId, int $anioId, int $competenciaId, ?string $valor, ?string $fecha, int $usuarioId): void
    {
        $escala = DB::table('matriculas as m')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->where('m.alumno_id', $alumnoId)->where('m.anio_escolar_id', $anioId)->value('g.escala_calificacion');
        if (! $escala) {
            throw ValidationException::withMessages(['alumno_id' => ['El alumno no estuvo matriculado ese año.']]);
        }
        $valor = $valor !== null && trim($valor) !== '' ? mb_strtoupper(trim($valor)) : null;
        if (! RegistroNotas::valido($valor, $escala)) {
            throw ValidationException::withMessages(['valor' => [$escala === 'literal' ? 'Usa AD, A, B o C.' : 'Usa una nota de 00 a 20.']]);
        }
        DB::table('evaluaciones_recuperacion')->upsert([[
            'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'anio_escolar_id' => $anioId, 'competencia_id' => $competenciaId,
            'valor' => $valor, 'fecha' => $fecha ?? now()->toDateString(), 'registrado_por' => $usuarioId,
        ]], ['alumno_id', 'anio_escolar_id', 'competencia_id'], ['valor', 'fecha', 'registrado_por']);
        $this->auditoria->registrar('recuperacion_registrada', 'evaluaciones_recuperacion', null, null, ['alumno_id' => $alumnoId, 'competencia_id' => $competenciaId, 'valor' => $valor]);
    }

    public function actaExcel(int $seccionId): string
    {
        $a = $this->acta($seccionId);
        $ruta = tempnam(sys_get_temp_dir(), 'acta').'.xlsx';
        $w = new Writer;
        $w->openToFile($ruta);
        $w->addRow(Row::fromValues(["Acta {$a['seccion']['grado']} {$a['seccion']['nombre']} {$a['seccion']['anio']}"], (new Style)->setFontBold()));
        $w->addRow(Row::fromValues(array_merge(['Alumno'], array_map(fn ($k) => "{$k['area']} C{$k['numero']}", $a['competencias']), ['Promedio', 'Situación final']), (new Style)->setFontBold()));
        foreach ($a['filas'] as $f) {
            $w->addRow(Row::fromValues(array_merge([$f['alumno']], array_map(fn ($k) => $f['notas'][$k['id']]['final'] ?? '', $a['competencias']), [$f['promedio'], $f['situacion']])));
        }
        $w->addRow(Row::fromValues(['']));
        $w->addRow(Row::fromValues(['Cuadro de méritos'], (new Style)->setFontBold()));
        foreach ($a['merito'] as $m) {
            $w->addRow(Row::fromValues([$m['puesto'], $m['alumno'], $m['promedio']]));
        }
        $w->close();
        $contenido = (string) file_get_contents($ruta);
        @unlink($ruta);

        return $contenido;
    }

    public function actaPdf(int $seccionId): string
    {
        $a = $this->acta($seccionId);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $cab = implode('', array_map(fn ($k) => '<th>'.$e(mb_substr($k['area'], 0, 12)).' C'.$k['numero'].'</th>', $a['competencias']));
        $filas = implode('', array_map(fn ($f) => '<tr><td>'.$e($f['alumno']).'</td>'.implode('', array_map(fn ($k) => '<td class="n">'.$e($f['notas'][$k['id']]['final'] ?? '')
            .(($f['notas'][$k['id']]['recuperacion'] ?? null) ? '*' : '').'</td>', $a['competencias'])).'<td class="n">'.$e($f['promedio']).'</td><td>'.$e($f['situacion']).'</td></tr>', $a['filas']));
        $merito = implode('', array_map(fn ($m) => '<tr><td class="n">'.$m['puesto'].'</td><td>'.$e($m['alumno']).'</td><td class="n">'.$e($m['promedio']).'</td></tr>', array_slice($a['merito'], 0, 10)));
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:8px}table{width:100%;border-collapse:collapse;margin:6px 0}td,th{border:1px solid #bbb;padding:3px}.n{text-align:center}</style></head><body>'
            .'<h2>Acta de evaluación · '.$e($a['seccion']['grado'].' '.$a['seccion']['nombre'].' '.$a['seccion']['anio']).'</h2>'
            .'<table><thead><tr><th>Alumno</th>'.$cab.'<th>Prom.</th><th>Situación</th></tr></thead><tbody>'.$filas.'</tbody></table><p>* Nota de recuperación pedagógica.</p>'
            .'<h3>Cuadro de méritos</h3><table><thead><tr><th>Puesto</th><th>Alumno</th><th>Promedio</th></tr></thead><tbody>'.$merito.'</tbody></table></body></html>';
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return (string) $pdf->output();
    }
}
