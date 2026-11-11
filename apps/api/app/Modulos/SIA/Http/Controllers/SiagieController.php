<?php

namespace App\Modulos\SIA\Http\Controllers;

use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SIA\Services\NominaSiagie;
use App\Modulos\SIA\Services\PlantillasNotas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Sprint 20 · Integración SIAGIE por archivos: nómina, validación, plantillas, mapeo e historial. */
class SiagieController
{
    public function __construct(private NominaSiagie $nomina, private PlantillasNotas $plantillas) {}

    public function procesos(Request $request): JsonResponse
    {
        $tipo = $request->validate(['tipo' => ['nullable', 'in:nomina,plantilla_notas']])['tipo'] ?? null;

        return response()->json(['data' => DB::table('siagie_procesos as p')->leftJoin('secciones as s', 's.id', '=', 'p.seccion_id')->leftJoin('grados as g', 'g.id', '=', 's.grado_id')
            ->leftJoin('periodos as pe', 'pe.id', '=', 'p.periodo_id')->leftJoin('archivos as a', 'a.id', '=', 'p.archivo_original_id')->leftJoin('users as u', 'u.id', '=', 'p.usuario_id')
            ->when($tipo, fn ($q) => $q->where('p.tipo', $tipo))->orderByDesc('p.id')->limit(100)
            ->get(['p.id', 'p.tipo', 'p.estado', 'p.total_filas', 'p.creados', 'p.actualizados', 'p.con_error', 'p.created_at', 'p.finalizado_at', 'p.archivo_resultado_id',
                DB::raw("g.nombre || ' ' || s.nombre as seccion"), 'pe.nombre as periodo', 'a.nombre_original as archivo', DB::raw("u.nombres || ' ' || u.apellidos as usuario")])
            ->map(fn ($p) => (array) $p)->all()]);
    }

    public function proceso(int $proceso): JsonResponse
    {
        $p = DB::table('siagie_procesos')->find($proceso);
        abort_if(! $p, 404);

        return response()->json(array_merge((array) $p, [
            'advertencias' => json_decode($p->advertencias ?? 'null', true), 'mapeo' => json_decode($p->mapeo ?? 'null', true),
            'errores' => DB::table('siagie_errores')->where('siagie_proceso_id', $proceso)->orderBy('fila')->limit(500)->get(['fila', 'columna', 'mensaje']),
        ]));
    }

    public function archivo(int $proceso, string $cual, AlmacenArchivos $almacen): JsonResponse
    {
        $p = DB::table('siagie_procesos')->find($proceso);
        $id = $p ? ($cual === 'resultado' ? $p->archivo_resultado_id : $p->archivo_original_id) : null;
        abort_if(! $id, 404, 'El proceso aún no tiene ese archivo.');

        return response()->json(['url' => $almacen->urlTemporal(Archivo::query()->findOrFail($id))]);
    }

    public function subirNomina(Request $request): JsonResponse
    {
        $d = $request->validate(['archivo' => ['required', 'file', 'max:'.(config('siagie.max_mb') * 1024)], 'seccion_id' => ['required', 'integer']],
            ['archivo.required' => 'Sube la nómina del SIAGIE.', 'archivo.max' => 'El archivo supera los '.config('siagie.max_mb').' MB.']);

        return response()->json(['id' => $this->nomina->subir($d['archivo'], (int) $d['seccion_id'], (int) $request->user()->id)], 202);
    }

    public function validar(Request $request): JsonResponse
    {
        $d = $request->validate(['seccion_id' => ['required', 'integer'], 'periodo_id' => ['required', 'integer']]);

        return response()->json($this->plantillas->validar((int) $d['seccion_id'], (int) $d['periodo_id']));
    }

    public function generar(Request $request): JsonResponse
    {
        $d = $request->validate(['plantilla' => ['required', 'file', 'max:'.(config('siagie.max_mb') * 1024)], 'seccion_id' => ['required', 'integer'], 'periodo_id' => ['required', 'integer']],
            ['plantilla.required' => 'Sube la plantilla vacía descargada del SIAGIE.']);
        $id = $this->plantillas->generar($d['plantilla'], (int) $d['seccion_id'], (int) $d['periodo_id'], (int) $request->user()->id);

        return response()->json($this->proceso($id)->getData(true), 201);
    }

    public function lote(Request $request): JsonResponse
    {
        $d = $request->validate(['plantillas' => ['required', 'array', 'min:1', 'max:60'], 'plantillas.*' => ['file', 'max:'.(config('siagie.max_mb') * 1024)], 'periodo_id' => ['required', 'integer']],
            ['plantillas.required' => 'Sube las plantillas de las secciones.']);

        return response()->json(['id' => $this->plantillas->subirLote($d['plantillas'], (int) $d['periodo_id'], (int) $request->user()->id)], 202);
    }

    public function mapeo(int $grado): JsonResponse
    {
        $areas = DB::table('cargas_docentes as c')->join('secciones as s', 's.id', '=', 'c.seccion_id')->join('areas_curriculares as a', 'a.id', '=', 'c.area_curricular_id')
            ->where('s.grado_id', $grado)->distinct()->get(['a.id', 'a.nombre']);
        $competencias = DB::table('competencias')->whereIn('area_curricular_id', $areas->pluck('id'))->where('vigente', true)->orderBy('numero')->get(['id', 'numero', 'nombre', 'area_curricular_id']);

        return response()->json(['mapeo' => $this->plantillas->mapeo($grado), 'areas' => $areas->map(fn ($a) => (array) $a + ['competencias' => $competencias->where('area_curricular_id', $a->id)->values()->all()])->all()]);
    }

    public function guardarMapeo(Request $request, int $grado): JsonResponse
    {
        $d = $request->validate([
            'hojas' => ['required', 'array'], 'hojas.*.hoja' => ['required', 'string', 'max:100'], 'hojas.*.area_id' => ['required', 'integer'],
            'hojas.*.fila_inicio' => ['required', 'integer', 'min:1', 'max:500'], 'hojas.*.col_codigo' => ['required', 'regex:/^[A-Z]{1,3}$/'],
            'hojas.*.col_documento' => ['nullable', 'regex:/^[A-Z]{1,3}$/'], 'hojas.*.competencias' => ['required', 'array'], 'hojas.*.competencias.*' => ['regex:/^[A-Z]{1,3}$/'],
            'hojas.*.conclusiones' => ['nullable', 'array'], 'hojas.*.conclusiones.*' => ['regex:/^[A-Z]{1,3}$/'], 'hojas.*.col_conclusion_general' => ['nullable', 'regex:/^[A-Z]{1,3}$/'],
        ], ['hojas.*.col_codigo.regex' => 'Las columnas se escriben con letras (A, B… AA).', 'hojas.*.competencias.*.regex' => 'Las columnas se escriben con letras (A, B… AA).']);
        $hojas = collect($d['hojas'])->map(fn ($h) => $h + ['conclusiones' => [], 'col_documento' => null, 'col_conclusion_general' => null, 'fila_encabezado' => max(1, (int) $h['fila_inicio'] - 1)])->all();
        $this->plantillas->guardarMapeo($grado, $hojas);

        return response()->json(['mapeo' => $this->plantillas->mapeo($grado)]);
    }
}
