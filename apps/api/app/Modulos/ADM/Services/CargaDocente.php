<?php

namespace App\Modulos\ADM\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\PLA\Services\ModulosColegio;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-48 / ADM-H06 · Docente por área y sección (según el plan de estudios del año).
 * Al asignar se crea el curso del aula virtual (AUL) y, si el colegio usa Moodle, el curso
 * queda pendiente para que el conector lo cree en Moodle.
 */
class CargaDocente
{
    public function __construct(private Auditoria $auditoria, private ModulosColegio $modulos) {}

    public function listar(int $anioId): array
    {
        PlanEstudios::anio($anioId);
        $plan = DB::table('area_curricular_grado as acg')->join('areas_curriculares as a', 'a.id', '=', 'acg.area_curricular_id')
            ->where('acg.anio_escolar_id', $anioId)->orderBy('acg.orden')
            ->get(['acg.grado_id', 'a.id', 'a.nombre', 'acg.horas_semanales'])->groupBy('grado_id');
        $cargas = DB::table('cargas_docentes as c')
            ->join('secciones as s', 's.id', '=', 'c.seccion_id')
            ->join('personal as p', 'p.id', '=', 'c.personal_id')
            ->leftJoin('aula_cursos as ac', 'ac.carga_docente_id', '=', 'c.id')
            ->leftJoin('moodle_cursos as mc', 'mc.carga_docente_id', '=', 'c.id')
            ->where('s.anio_escolar_id', $anioId)
            ->get(['c.id', 'c.seccion_id', 'c.area_curricular_id', 'c.personal_id', 'c.horas_semanales', DB::raw("p.nombres || ' ' || p.apellidos as docente"),
                DB::raw('ac.id is not null as curso_aula'), 'mc.estado as curso_moodle'])
            ->keyBy(fn ($c) => $c->seccion_id.'-'.$c->area_curricular_id);

        $secciones = collect(app(Secciones::class)->listar($anioId))->map(fn ($s) => [
            'seccion_id' => $s['id'], 'seccion' => "{$s['grado']} {$s['nombre']}", 'nivel' => $s['nivel'],
            'areas' => ($plan[$s['grado_id']] ?? collect())->map(function ($a) use ($cargas, $s) {
                $c = $cargas[$s['id'].'-'.$a->id] ?? null;

                return [
                    'area_curricular_id' => (int) $a->id, 'area' => $a->nombre, 'horas_plan' => $a->horas_semanales,
                    'carga_id' => $c ? (int) $c->id : null, 'personal_id' => $c ? (int) $c->personal_id : null, 'docente' => $c?->docente,
                    'horas_semanales' => $c?->horas_semanales, 'curso_aula' => (bool) ($c?->curso_aula), 'curso_moodle' => $c?->curso_moodle,
                ];
            })->values()->all(),
        ])->all();

        return [
            'secciones' => $secciones,
            'docentes' => DB::table('personal')->where('estado', 'activo')->orderBy('apellidos')
                ->get(['id', DB::raw("nombres || ' ' || apellidos as nombre"), 'especialidad']),
        ];
    }

    /**
     * Asigna (o quita, con personal_id null) el docente de un área en una sección.
     *
     * @return array{carga_id: int|null, aviso: string|null}
     */
    public function asignar(int $seccionId, int $areaId, ?int $personalId, ?int $horas): array
    {
        $seccion = DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->where('s.id', $seccionId)
            ->first(['s.id', 's.anio_escolar_id', 's.grado_id', 's.nombre', 'g.nombre as grado']);
        abort_if(! $seccion, 404);
        $enPlan = DB::table('area_curricular_grado')->where('grado_id', $seccion->grado_id)->where('anio_escolar_id', $seccion->anio_escolar_id)
            ->where('area_curricular_id', $areaId)->exists();
        if (! $enPlan) {
            throw ValidationException::withMessages(['area_curricular_id' => ['Esa área no está en el plan de estudios del grado.']]);
        }
        $actual = DB::table('cargas_docentes')->where('seccion_id', $seccionId)->where('area_curricular_id', $areaId)->first();
        $area = (string) DB::table('areas_curriculares')->where('id', $areaId)->value('nombre');

        if ($personalId === null) {
            if ($actual) {
                $this->quitar((int) $actual->id);
                $this->auditoria->registrar('carga_quitada', 'cargas_docentes', (int) $actual->id, ['seccion_id' => $seccionId, 'area' => $area, 'personal_id' => $actual->personal_id], null);
            }

            return ['carga_id' => null, 'aviso' => null];
        }
        if (! DB::table('personal')->where('id', $personalId)->where('estado', 'activo')->exists()) {
            throw ValidationException::withMessages(['personal_id' => ['Elige un docente activo del colegio.']]);
        }

        if ($actual) {
            try {
                DB::transaction(function () use ($actual, $personalId, $horas) {
                    DB::table('cargas_docentes')->where('id', $actual->id)->update(['personal_id' => $personalId, 'horas_semanales' => $horas]);
                    // El horario ya armado pasa al nuevo docente (si no se le cruza).
                    DB::table('horario_sesiones')->where('carga_docente_id', $actual->id)->update(['personal_id' => $personalId]);
                });
            } catch (QueryException $e) {
                if ($e->getCode() === '23505') {
                    throw ValidationException::withMessages(['personal_id' => ['Ese docente ya tiene clase en alguno de los bloques de esta área: ajusta primero el horario.']]);
                }
                throw $e;
            }
            $id = (int) $actual->id;
            if ((int) $actual->personal_id !== $personalId || (int) $actual->horas_semanales !== (int) $horas) {
                $this->auditoria->registrar('carga_asignada', 'cargas_docentes', $id, ['personal_id' => (int) $actual->personal_id, 'horas' => $actual->horas_semanales], ['personal_id' => $personalId, 'horas' => $horas]);
            }
        } else {
            $id = (int) DB::table('cargas_docentes')->insertGetId([
                'institucion_id' => ContextoInstitucion::requerido(), 'seccion_id' => $seccionId, 'area_curricular_id' => $areaId,
                'personal_id' => $personalId, 'horas_semanales' => $horas,
            ]);
            $this->auditoria->registrar('carga_asignada', 'cargas_docentes', $id, null, ['seccion' => "{$seccion->grado} {$seccion->nombre}", 'area' => $area, 'personal_id' => $personalId, 'horas' => $horas]);
        }

        return ['carga_id' => $id, 'aviso' => $this->crearCursos($id, "{$area} · {$seccion->grado} {$seccion->nombre}")];
    }

    /** Curso del aula a medida y/o curso de Moodle por crear, según la opción de aula del colegio. */
    private function crearCursos(int $cargaId, string $titulo): ?string
    {
        $institucion = ContextoInstitucion::requerido();
        if ($this->modulos->activo('AUL')) {
            DB::table('aula_cursos')->insertOrIgnore(['institucion_id' => $institucion, 'carga_docente_id' => $cargaId, 'titulo' => mb_substr($titulo, 0, 150)]);
        }
        if ($this->modulos->activo('MDL')) {
            $conexion = DB::table('moodle_conexiones')->value('id');
            if (! $conexion) {
                return 'El curso de Moodle se creará cuando configures el conector de Moodle.';
            }
            DB::table('moodle_cursos')->insertOrIgnore(['institucion_id' => $institucion, 'moodle_conexion_id' => $conexion, 'carga_docente_id' => $cargaId, 'estado' => 'pendiente']);
        }

        return null;
    }

    private function quitar(int $cargaId): void
    {
        try {
            DB::transaction(function () use ($cargaId) {
                DB::table('moodle_cursos')->where('carga_docente_id', $cargaId)->where('estado', 'pendiente')->delete();
                // El curso del aula se borra en cascada con la carga: solo si aún no tiene contenido.
                $curso = DB::table('aula_cursos')->where('carga_docente_id', $cargaId)->value('id');
                if ($curso && DB::table('aula_unidades')->where('aula_curso_id', $curso)->exists()) {
                    throw ValidationException::withMessages(['personal_id' => ['El curso de esta área ya tiene contenido en el aula virtual: cambia el docente en lugar de quitarlo.']]);
                }
                DB::table('cargas_docentes')->where('id', $cargaId)->delete();
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23503') {
                throw ValidationException::withMessages(['personal_id' => ['El curso de esta área ya tiene contenido o notas: cambia el docente en lugar de quitarlo.']]);
            }
            throw $e;
        }
    }
}
