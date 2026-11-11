<?php

namespace App\Modulos\CAL\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SEG\Services\NotificacionesPush;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CAL · Revisión, cierre y reapertura del periodo.
 *
 * - EDUN-97 · CAL-H07 Avance por sección y área, recordatorio a los docentes con notas
 *   pendientes y cierre: al cerrar, la BD bloquea cualquier cambio de notas.
 * - EDUN-98 · CAL-H08 Reabrir exige motivo, queda en la auditoría y la sección vuelve a revisión.
 */
class CierrePeriodos
{
    public function __construct(private Auditoria $auditoria, private NotificacionesPush $push) {}

    /** Avance de cada sección y área del periodo. */
    public function avance(int $periodoId): array
    {
        $p = DB::table('periodos')->find($periodoId);
        abort_if(! $p, 404);
        $cargas = DB::table('cargas_docentes as c')->join('secciones as s', 's.id', '=', 'c.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->join('areas_curriculares as a', 'a.id', '=', 'c.area_curricular_id')->join('personal as pe', 'pe.id', '=', 'c.personal_id')
            ->where('s.anio_escolar_id', $p->anio_escolar_id)->orderBy('g.nivel')->orderBy('g.numero')->orderBy('s.nombre')->orderBy('a.orden')
            ->get(['c.id', 'c.seccion_id', 'c.area_curricular_id', 'pe.user_id', DB::raw("g.nombre || ' ' || s.nombre as seccion"), 'a.nombre as area', DB::raw("pe.nombres || ' ' || pe.apellidos as docente")]);
        $alumnos = DB::table('matriculas')->whereIn('seccion_id', $cargas->pluck('seccion_id')->unique())->where('estado', 'matriculado')->get(['seccion_id', 'alumno_id'])->groupBy('seccion_id');
        $competencias = DB::table('competencias')->where('vigente', true)->whereIn('area_curricular_id', $cargas->pluck('area_curricular_id')->unique())->get(['id', 'area_curricular_id'])->groupBy('area_curricular_id');
        $notas = DB::table('calificaciones')->where('periodo_id', $periodoId)->whereNotNull('valor')->get(['alumno_id', 'competencia_id', 'seccion_id', 'valor', 'conclusion_descriptiva']);
        $porSeccion = $notas->groupBy('seccion_id');
        $cierres = DB::table('cierres_periodo')->where('periodo_id', $periodoId)->get()->keyBy('seccion_id');

        $filas = $cargas->map(function ($c) use ($alumnos, $competencias, $porSeccion) {
            $ids = ($alumnos[$c->seccion_id] ?? collect())->pluck('alumno_id')->flip();
            $comp = ($competencias[$c->area_curricular_id] ?? collect())->pluck('id')->flip();
            $mias = ($porSeccion[$c->seccion_id] ?? collect())->filter(fn ($n) => isset($ids[$n->alumno_id]) && isset($comp[$n->competencia_id]));
            $total = count($ids) * count($comp);

            return [
                'carga_id' => $c->id, 'seccion_id' => $c->seccion_id, 'seccion' => $c->seccion, 'area' => $c->area, 'docente' => $c->docente, 'user_id' => $c->user_id,
                'total' => $total, 'llenas' => $mias->count(), 'c_sin_conclusion' => $mias->filter(fn ($n) => $n->valor === 'C' && ! trim((string) $n->conclusion_descriptiva))->count(),
                'avance' => $total ? (int) floor($mias->count() * 100 / $total) : 0,
            ];
        });

        $secciones = $filas->groupBy('seccion_id')->map(fn ($g, $sid) => [
            'seccion_id' => (int) $sid, 'seccion' => $g->first()['seccion'], 'estado' => $cierres[$sid]->estado ?? 'registro',
            'cerrado_at' => $cierres[$sid]->cerrado_at ?? null, 'motivo_reapertura' => $cierres[$sid]->motivo_reapertura ?? null,
            'completo' => $g->every(fn ($f) => $f['total'] > 0 && $f['llenas'] === $f['total'] && $f['c_sin_conclusion'] === 0),
            'avance' => (int) floor($g->sum('llenas') * 100 / max(1, $g->sum('total'))), 'areas' => $g->values()->all(),
        ])->values();

        return ['periodo' => (array) $p, 'secciones' => $secciones->all(), 'todas_cerradas' => $secciones->isNotEmpty() && $secciones->every(fn ($s) => $s['estado'] === 'cerrado')];
    }

    /** Avisa (push y bandeja) a los docentes con notas pendientes en el periodo. */
    public function recordar(int $periodoId): array
    {
        $a = $this->avance($periodoId);
        $pendientes = collect($a['secciones'])->filter(fn ($s) => $s['estado'] !== 'cerrado')->flatMap(fn ($s) => $s['areas'])
            ->filter(fn ($f) => $f['user_id'] && ($f['llenas'] < $f['total'] || $f['c_sin_conclusion'] > 0));
        $avisados = 0;
        foreach ($pendientes->groupBy('user_id') as $userId => $cursos) {
            $lista = $cursos->map(fn ($f) => "{$f['area']} {$f['seccion']} ({$f['avance']} %)")->implode(', ');
            $this->push->enviar([(int) $userId], 'notas', 'Notas pendientes de '.$a['periodo']['nombre'], "Completa: {$lista}.", ['pantalla' => 'notas', 'id' => $periodoId]);
            $avisados++;
        }
        DB::table('cierres_periodo')->where('periodo_id', $periodoId)->update(['recordatorio_at' => now()]);
        $this->auditoria->registrar('recordatorio_notas', 'periodos', $periodoId, null, ['docentes' => $avisados]);

        return ['docentes' => $avisados, 'cursos' => $pendientes->count()];
    }

    /** Cierra la sección en el periodo: exige todas las notas y las conclusiones de las C. */
    public function cerrar(int $seccionId, int $periodoId, int $usuarioId): void
    {
        $s = collect($this->avance($periodoId)['secciones'])->firstWhere('seccion_id', $seccionId);
        if (! $s) {
            throw ValidationException::withMessages(['seccion_id' => ['La sección no tiene cursos en este periodo.']]);
        }
        if ($s['estado'] === 'cerrado') {
            throw ValidationException::withMessages(['seccion_id' => ['La sección ya está cerrada.']]);
        }
        if (! $s['completo']) {
            $faltan = collect($s['areas'])->filter(fn ($f) => $f['llenas'] < $f['total'] || $f['c_sin_conclusion'] > 0)->map(fn ($f) => $f['area'])->implode(', ');
            throw ValidationException::withMessages(['seccion_id' => ["Faltan notas o conclusiones en: {$faltan}."]]);
        }
        DB::table('cierres_periodo')->updateOrInsert(['seccion_id' => $seccionId, 'periodo_id' => $periodoId], [
            'institucion_id' => ContextoInstitucion::requerido(), 'estado' => 'cerrado', 'aprobado_por' => $usuarioId, 'cerrado_at' => now(), 'updated_at' => now(),
        ]);
        $this->auditoria->registrar('seccion_cerrada', 'cierres_periodo', null, null, ['seccion_id' => $seccionId, 'periodo_id' => $periodoId]);
        if ($this->avance($periodoId)['todas_cerradas']) {
            DB::table('periodos')->where('id', $periodoId)->update(['estado' => 'cerrado', 'cerrado_at' => now(), 'cerrado_por' => $usuarioId]);
            $this->auditoria->registrar('periodo_cerrado', 'periodos', $periodoId, null, null);
        }
    }

    /** EDUN-98 · Reabre la sección (y el periodo, si estaba cerrado): vuelve a revisión. */
    public function reabrir(int $seccionId, int $periodoId, string $motivo, int $usuarioId): void
    {
        $c = DB::table('cierres_periodo')->where('seccion_id', $seccionId)->where('periodo_id', $periodoId)->first();
        if (! $c || $c->estado !== 'cerrado') {
            throw ValidationException::withMessages(['seccion_id' => ['Solo se reabre una sección cerrada.']]);
        }
        DB::transaction(function () use ($c, $periodoId, $motivo, $usuarioId) {
            DB::table('cierres_periodo')->where('id', $c->id)->update([
                'estado' => 'revision', 'reabierto_por' => $usuarioId, 'reabierto_at' => now(), 'motivo_reapertura' => $motivo, 'cerrado_at' => null,
            ]);
            DB::table('periodos')->where('id', $periodoId)->where('estado', 'cerrado')->update(['estado' => 'abierto', 'cerrado_at' => null, 'cerrado_por' => null]);
            $this->auditoria->registrar('periodo_reabierto', 'cierres_periodo', (int) $c->id, ['estado' => 'cerrado'], ['estado' => 'revision', 'motivo' => $motivo]);
        });
    }
}
