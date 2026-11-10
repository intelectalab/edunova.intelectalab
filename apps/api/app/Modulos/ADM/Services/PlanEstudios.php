<?php

namespace App\Modulos\ADM\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-45 / ADM-H03 · Plan de estudios por grado y año.
 *
 * - Los grados salen de los niveles del colegio (un servicio educativo por nivel).
 * - Las áreas se toman del catálogo del CNEB del nivel (areas_curriculares), con horas.
 * - Escala literal o vigesimal por grado.
 * - Se puede copiar el plan de un año a otro (solo a los grados que aún no tienen plan).
 */
class PlanEstudios
{
    /** Grados de cada nivel en la Educación Básica Regular. */
    public const GRADOS = [
        'inicial' => [3, 4, 5],
        'primaria' => [1, 2, 3, 4, 5, 6],
        'secundaria' => [1, 2, 3, 4, 5],
    ];

    public function __construct(private Auditoria $auditoria) {}

    public static function nombreGrado(string $nivel, int $numero): string
    {
        return $nivel === 'inicial' ? "{$numero} años" : "{$numero}.° de ".ucfirst($nivel);
    }

    /** Crea los grados que falten en cada nivel del colegio. */
    public function generarGrados(): int
    {
        $institucion = ContextoInstitucion::requerido();
        $creados = 0;
        foreach (DB::table('servicios_educativos')->get(['id', 'nivel']) as $s) {
            foreach (self::GRADOS[$s->nivel] ?? [] as $n) {
                $creados += DB::table('grados')->insertOrIgnore([
                    'institucion_id' => $institucion, 'servicio_educativo_id' => $s->id, 'nivel' => $s->nivel,
                    'numero' => $n, 'nombre' => self::nombreGrado($s->nivel, $n),
                ]);
            }
        }

        return $creados;
    }

    /** @return list<array<string, mixed>> grados del colegio con su plan del año */
    public function plan(int $anioId): array
    {
        self::anio($anioId);
        $areas = DB::table('area_curricular_grado as acg')
            ->join('areas_curriculares as a', 'a.id', '=', 'acg.area_curricular_id')
            ->where('acg.anio_escolar_id', $anioId)
            ->orderBy('acg.orden')->orderBy('a.orden')
            ->get(['acg.grado_id', 'a.id', 'a.codigo', 'a.nombre', 'a.es_transversal', 'acg.horas_semanales'])
            ->groupBy('grado_id');

        return DB::table('grados')->orderByRaw("case nivel when 'inicial' then 1 when 'primaria' then 2 else 3 end")->orderBy('numero')
            ->get(['id', 'nivel', 'numero', 'nombre', 'escala_calificacion'])
            ->map(fn ($g) => [
                'id' => (int) $g->id, 'nivel' => $g->nivel, 'numero' => (int) $g->numero, 'nombre' => $g->nombre, 'escala' => $g->escala_calificacion,
                'areas' => ($areas[$g->id] ?? collect())->map(fn ($a) => [
                    'area_curricular_id' => (int) $a->id, 'codigo' => $a->codigo, 'nombre' => $a->nombre,
                    'es_transversal' => (bool) $a->es_transversal, 'horas_semanales' => $a->horas_semanales === null ? null : (int) $a->horas_semanales,
                ])->values()->all(),
            ])->all();
    }

    /** @return array<string, list<array{id: int, codigo: string, nombre: string, es_transversal: bool}>> */
    public function catalogo(): array
    {
        return DB::table('areas_curriculares')->where('vigente', true)->orderBy('orden')->get(['id', 'codigo', 'nombre', 'nivel', 'es_transversal'])
            ->groupBy('nivel')->map(fn ($g) => $g->map(fn ($a) => [
                'id' => (int) $a->id, 'codigo' => $a->codigo, 'nombre' => $a->nombre, 'es_transversal' => (bool) $a->es_transversal,
            ])->values()->all())->all();
    }

    /** @param list<array{area_curricular_id: int, horas_semanales?: int|null}> $areas */
    public function guardarGrado(int $gradoId, int $anioId, string $escala, array $areas): void
    {
        self::anio($anioId);
        $grado = DB::table('grados')->find($gradoId);
        abort_if(! $grado, 404);
        $ids = array_map(fn ($a) => (int) $a['area_curricular_id'], $areas);
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['areas' => ['Hay un área repetida.']]);
        }
        $delNivel = DB::table('areas_curriculares')->where('nivel', $grado->nivel)->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (count($delNivel) !== count($ids)) {
            throw ValidationException::withMessages(['areas' => ["Solo se pueden usar áreas del CNEB de {$grado->nivel}."]]);
        }

        $antes = collect($this->plan($anioId))->firstWhere('id', $gradoId);
        DB::table('grados')->where('id', $gradoId)->update(['escala_calificacion' => $escala]);
        // Las áreas que ya tienen carga docente no se quitan (primero se quita la carga).
        $quitar = DB::table('area_curricular_grado')->where('grado_id', $gradoId)->where('anio_escolar_id', $anioId)->whereNotIn('area_curricular_id', $ids)->pluck('area_curricular_id');
        if ($quitar->isNotEmpty()) {
            $conCarga = DB::table('cargas_docentes as c')->join('secciones as s', 's.id', '=', 'c.seccion_id')
                ->where('s.grado_id', $gradoId)->where('s.anio_escolar_id', $anioId)->whereIn('c.area_curricular_id', $quitar)
                ->join('areas_curriculares as a', 'a.id', '=', 'c.area_curricular_id')->value('a.nombre');
            if ($conCarga) {
                throw ValidationException::withMessages(['areas' => ["{$conCarga} ya tiene docente asignado en una sección: quita primero la carga docente."]]);
            }
            DB::table('area_curricular_grado')->where('grado_id', $gradoId)->where('anio_escolar_id', $anioId)->whereIn('area_curricular_id', $quitar)->delete();
        }
        $institucion = ContextoInstitucion::requerido();
        foreach (array_values($areas) as $i => $a) {
            DB::table('area_curricular_grado')->upsert(
                [['institucion_id' => $institucion, 'grado_id' => $gradoId, 'anio_escolar_id' => $anioId, 'area_curricular_id' => (int) $a['area_curricular_id'],
                    'horas_semanales' => $a['horas_semanales'] ?? null, 'orden' => $i + 1]],
                ['grado_id', 'anio_escolar_id', 'area_curricular_id'],
                ['horas_semanales', 'orden'],
            );
        }
        $despues = collect($this->plan($anioId))->firstWhere('id', $gradoId);
        $resumen = fn ($g) => ['escala' => $g['escala'], 'areas' => array_map(fn ($a) => $a['nombre'].($a['horas_semanales'] ? " ({$a['horas_semanales']} h)" : ''), $g['areas'])];
        if ($resumen($antes) !== $resumen($despues)) {
            $this->auditoria->registrar('plan_estudios_editado', 'area_curricular_grado', $gradoId, $resumen($antes), $resumen($despues));
        }
    }

    /** Copia el plan de un año a otro, solo en los grados sin plan en el año destino. */
    public function copiar(int $desde, int $hacia): array
    {
        self::anio($desde);
        self::anio($hacia);
        if ($desde === $hacia) {
            throw ValidationException::withMessages(['desde_anio_id' => ['Elige un año distinto.']]);
        }
        $conPlan = DB::table('area_curricular_grado')->where('anio_escolar_id', $hacia)->distinct()->pluck('grado_id')->all();
        $filas = DB::table('area_curricular_grado')->where('anio_escolar_id', $desde)->whereNotIn('grado_id', $conPlan)
            ->get(['grado_id', 'area_curricular_id', 'horas_semanales', 'orden']);
        $institucion = ContextoInstitucion::requerido();
        foreach ($filas as $f) {
            DB::table('area_curricular_grado')->insert([
                'institucion_id' => $institucion, 'grado_id' => $f->grado_id, 'anio_escolar_id' => $hacia,
                'area_curricular_id' => $f->area_curricular_id, 'horas_semanales' => $f->horas_semanales, 'orden' => $f->orden,
            ]);
        }
        $grados = $filas->pluck('grado_id')->unique()->count();
        $this->auditoria->registrar('plan_estudios_copiado', 'area_curricular_grado', null, ['anio_escolar_id' => $desde], ['anio_escolar_id' => $hacia, 'grados' => $grados]);

        return ['grados' => $grados, 'omitidos' => count($conPlan)];
    }

    public static function anio(int $id): object
    {
        $anio = DB::table('anios_escolares')->find($id);
        if (! $anio) {
            throw ValidationException::withMessages(['anio_escolar_id' => ['Elige un año escolar del colegio.']]);
        }

        return $anio;
    }
}
