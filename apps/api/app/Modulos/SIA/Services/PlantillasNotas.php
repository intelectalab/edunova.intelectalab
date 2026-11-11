<?php

namespace App\Modulos\SIA\Services;

use App\Modulos\CAL\Services\RegistroNotas;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SIA\Jobs\GenerarPlantillasLote;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ZipArchive;

/**
 * SIA · Plantilla de notas del SIAGIE.
 *
 * - EDUN-108 · SIA-H03 Validación previa: vacías, inválidas, C sin conclusión y alumnos sin código,
 *   cada error con el enlace directo a la celda a corregir.
 * - EDUN-109 · SIA-H04 Llenado: detecta por hoja el área, el código del estudiante y las columnas de
 *   competencias (fila 11 como respaldo); escribe solo las notas como texto, no toca fórmulas y
 *   devuelve un archivo nuevo (la plantilla original se guarda intacta).
 * - EDUN-110 · SIA-H05 Mapeo manual por grado cuando la detección no reconoce columnas.
 * - EDUN-111 · SIA-H06 Todas las secciones: varias plantillas en cola, ZIP y resumen de advertencias.
 */
class PlantillasNotas
{
    public function __construct(private AlmacenArchivos $almacen, private Auditoria $auditoria) {}

    // ── Validación previa

    public function validar(int $seccionId, int $periodoId): array
    {
        $s = DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->where('s.id', $seccionId)->first(['s.id', 's.anio_escolar_id', 'g.escala_calificacion as escala']);
        abort_if(! $s, 404);
        abort_if(! DB::table('periodos')->where('id', $periodoId)->where('anio_escolar_id', $s->anio_escolar_id)->exists(), 404);
        $alumnos = DB::table('matriculas as m')->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->where('m.seccion_id', $seccionId)->where('m.estado', 'matriculado')
            ->orderBy('a.apellido_paterno')->get(['a.id', 'a.codigo_estudiante_siagie', DB::raw("a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ', ' || a.nombres as nombre")]);
        $cargas = DB::table('cargas_docentes as c')->join('areas_curriculares as a', 'a.id', '=', 'c.area_curricular_id')->where('c.seccion_id', $seccionId)
            ->orderBy('a.orden')->get(['c.id', 'c.area_curricular_id', 'a.nombre as area']);
        $competencias = DB::table('competencias')->whereIn('area_curricular_id', $cargas->pluck('area_curricular_id'))->where('vigente', true)->get(['id', 'numero', 'area_curricular_id'])->groupBy('area_curricular_id');
        $notas = DB::table('calificaciones')->where('periodo_id', $periodoId)->whereIn('alumno_id', $alumnos->pluck('id'))->get()->keyBy(fn ($n) => $n->alumno_id.'-'.$n->competencia_id);
        $errores = [];
        foreach ($alumnos as $a) {
            if (! $a->codigo_estudiante_siagie) {
                $errores[] = ['tipo' => 'sin_codigo', 'alumno_id' => $a->id, 'alumno' => $a->nombre, 'mensaje' => 'No tiene código del estudiante SIAGIE: impórtalo con la nómina.', 'enlace' => "#/alumnos/{$a->id}"];
            }
            foreach ($cargas as $c) {
                foreach ($competencias[$c->area_curricular_id] ?? [] as $k) {
                    $n = $notas->get("{$a->id}-{$k->id}");
                    $enlace = "#/notas/{$c->id}/{$periodoId}";
                    if (! $n || $n->valor === null || $n->valor === '') {
                        $errores[] = ['tipo' => 'vacia', 'alumno_id' => $a->id, 'alumno' => $a->nombre, 'area' => $c->area, 'competencia' => $k->numero, 'mensaje' => "Sin nota en {$c->area} C{$k->numero}.", 'enlace' => $enlace];
                    } elseif (! RegistroNotas::valido($n->valor, $s->escala)) {
                        $errores[] = ['tipo' => 'invalida', 'alumno_id' => $a->id, 'alumno' => $a->nombre, 'area' => $c->area, 'competencia' => $k->numero, 'mensaje' => "Nota «{$n->valor}» inválida en {$c->area} C{$k->numero}.", 'enlace' => $enlace];
                    } elseif (in_array($n->valor, (array) config('siagie.notas.exigir_conclusion_en'), true) && ! trim((string) $n->conclusion_descriptiva)) {
                        $errores[] = ['tipo' => 'c_sin_conclusion', 'alumno_id' => $a->id, 'alumno' => $a->nombre, 'area' => $c->area, 'competencia' => $k->numero, 'mensaje' => "C sin conclusión descriptiva en {$c->area} C{$k->numero}.", 'enlace' => $enlace];
                    }
                }
            }
        }

        return ['valido' => $errores === [], 'alumnos' => $alumnos->count(), 'errores' => $errores,
            'resumen' => collect($errores)->countBy('tipo')->all()];
    }

    // ── Mapeo manual (por grado)

    public function mapeo(int $gradoId): ?array
    {
        $m = DB::table('siagie_mapeos')->where('tipo', 'plantilla_notas')->where('grado_id', $gradoId)->where('vigente', true)->orderByDesc('id')->value('mapeo');

        return $m ? json_decode($m, true) : null;
    }

    public function guardarMapeo(int $gradoId, array $hojas): void
    {
        DB::transaction(function () use ($gradoId, $hojas) {
            DB::table('siagie_mapeos')->where('tipo', 'plantilla_notas')->where('grado_id', $gradoId)->update(['vigente' => false]);
            DB::table('siagie_mapeos')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'tipo' => 'plantilla_notas', 'grado_id' => $gradoId, 'mapeo' => json_encode($hojas), 'vigente' => true]);
        });
        $this->auditoria->registrar('mapeo_siagie_guardado', 'siagie_mapeos', null, null, ['grado_id' => $gradoId, 'hojas' => count($hojas)]);
    }

    // ── Llenado

    /** Llena la plantilla de una sección y devuelve el id del proceso con el archivo resultado. */
    public function generar(UploadedFile $plantilla, int $seccionId, int $periodoId, int $usuarioId): int
    {
        $this->exigirValido($seccionId, $periodoId);
        $ext = strtolower($plantilla->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'xls'], true)) {
            throw ValidationException::withMessages(['plantilla' => ['Sube la plantilla de notas del SIAGIE en Excel (.xlsx o .xls).']]);
        }
        $original = $this->almacen->guardar($plantilla, $usuarioId, true);
        $r = $this->llenar((string) $plantilla->getRealPath(), $ext, $seccionId, $periodoId);
        $resultado = $this->almacen->guardarContenido($r['contenido'], 'llenada-'.$plantilla->getClientOriginalName(), $plantilla->getMimeType() ?: 'application/vnd.ms-excel', $usuarioId, true);

        return $this->registrarProceso($seccionId, $periodoId, $original->id, $resultado->id, $r, $usuarioId);
    }

    private function exigirValido(int $seccionId, int $periodoId): void
    {
        $v = $this->validar($seccionId, $periodoId);
        if (! $v['valido']) {
            throw ValidationException::withMessages(['notas' => ['Hay '.count($v['errores']).' problemas en las notas: corrígelos antes de generar la plantilla (Validar notas).']]);
        }
    }

    /** @return array{contenido: string, celdas: int, alumnos: int, advertencias: list<string>, mapeo: array} */
    public function llenar(string $ruta, string $ext, int $seccionId, int $periodoId): array
    {
        $seccion = DB::table('secciones')->find($seccionId);
        $tipo = IOFactory::identify($ruta);
        $libro = IOFactory::createReader($tipo)->load($ruta); // Sin setReadDataOnly: conserva estilos y validaciones.
        $areas = $this->areasDeSeccion($seccionId);
        $deteccion = $this->detectar($libro, $areas, $this->mapeo((int) $seccion->grado_id));
        if ($deteccion['hojas'] === []) {
            throw ValidationException::withMessages(['plantilla' => ['No se reconoció ninguna hoja de notas. Verifica que sea la plantilla de esta sección y periodo, o configura el mapeo manual.']]);
        }
        $alumnos = DB::table('matriculas as m')->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->where('m.seccion_id', $seccionId)->where('m.estado', 'matriculado')
            ->get(['a.id', 'a.codigo_estudiante_siagie', 'a.numero_documento', DB::raw("a.apellido_paterno || ' ' || a.nombres as nombre")]);
        $porCodigo = $alumnos->filter(fn ($a) => $a->codigo_estudiante_siagie)->keyBy('codigo_estudiante_siagie');
        $porDni = $alumnos->filter(fn ($a) => $a->numero_documento)->keyBy('numero_documento');
        $notas = DB::table('calificaciones')->where('periodo_id', $periodoId)->whereIn('alumno_id', $alumnos->pluck('id'))->get()->keyBy(fn ($n) => $n->alumno_id.'-'.$n->competencia_id);
        $advertencias = $deteccion['advertencias'];
        $encontrados = [];
        $celdas = 0;
        foreach ($deteccion['hojas'] as $indice => $mapeo) {
            $area = $areas->firstWhere('id', $mapeo['area_id']);
            $faltan = $area ? collect($area->competencias)->pluck('id')->diff(array_keys($mapeo['competencias'])) : collect();
            if ($faltan->isNotEmpty()) {
                throw ValidationException::withMessages(['plantilla' => ["En la hoja «{$mapeo['hoja']}» ({$area->nombre}) no se encontró la columna de ".$faltan->count().' competencias. Configura el mapeo manual de este grado.'],
                    'mapeo_detectado' => [json_encode($deteccion['hojas'])]]);
            }
            $celdas += $this->llenarHoja($libro->getSheet((int) $indice), $mapeo, $porCodigo, $porDni, $notas, $encontrados, $advertencias);
        }
        foreach ($alumnos as $a) {
            if (! isset($encontrados[$a->id])) {
                $advertencias[] = "{$a->nombre} no aparece en la plantilla del SIAGIE (revisa si fue matriculado allí).";
            }
        }
        $temporal = tempnam(sys_get_temp_dir(), 'plantilla').'.'.$ext;
        $escritor = IOFactory::createWriter($libro, $tipo);
        $escritor->setPreCalculateFormulas(false);
        $escritor->save($temporal);
        $libro->disconnectWorksheets();
        $contenido = (string) file_get_contents($temporal);
        @unlink($temporal);

        return ['contenido' => $contenido, 'celdas' => $celdas, 'alumnos' => count($encontrados), 'advertencias' => array_values($advertencias), 'mapeo' => $deteccion['hojas']];
    }

    /** Áreas de la sección (por sus cursos) con sus competencias. */
    private function areasDeSeccion(int $seccionId): Collection
    {
        $areas = DB::table('cargas_docentes as c')->join('areas_curriculares as a', 'a.id', '=', 'c.area_curricular_id')->where('c.seccion_id', $seccionId)->get(['a.id', 'a.nombre']);
        $comp = DB::table('competencias')->whereIn('area_curricular_id', $areas->pluck('id'))->where('vigente', true)->get(['id', 'numero', 'nombre', 'area_curricular_id'])->groupBy('area_curricular_id');

        return $areas->map(fn ($a) => (object) ['id' => $a->id, 'nombre' => $a->nombre, 'competencias' => ($comp[$a->id] ?? collect())->values()->all()]);
    }

    /** @return array{hojas: array<int, array>, advertencias: list<string>} */
    public function detectar(Spreadsheet $libro, Collection $areas, ?array $manual = null): array
    {
        $hojas = [];
        $advertencias = [];
        foreach ($libro->getAllSheets() as $i => $hoja) {
            if ($manual && isset($manual[$i])) {
                $hojas[$i] = $manual[$i];

                continue;
            }
            $m = $this->detectarHoja($hoja, $areas);
            if ($m === null) {
                $advertencias[] = "La hoja «{$hoja->getTitle()}» no tiene lista de estudiantes; se deja sin cambios.";
            } elseif ($m['area_id'] === null) {
                $advertencias[] = "No se reconoció el área de la hoja «{$hoja->getTitle()}»; se deja sin cambios.";
            } else {
                $hojas[$i] = $m;
            }
        }

        return ['hojas' => $hojas, 'advertencias' => $advertencias];
    }

    private function detectarHoja(Worksheet $hoja, Collection $areas): ?array
    {
        $filas = min((int) config('siagie.notas.filas_busqueda_encabezado', 20), $hoja->getHighestRow());
        $ultima = Coordinate::columnIndexFromString($hoja->getHighestColumn());
        $filaEnc = null;
        $colCodigo = null;
        $colDoc = null;
        for ($f = 1; $f <= $filas && ! $colCodigo; $f++) {
            for ($c = 1; $c <= $ultima; $c++) {
                $l = Coordinate::stringFromColumnIndex($c);
                $t = $this->texto($hoja, $l, $f);
                if (! $colCodigo && TextoSiagie::coincide($t, config('siagie.notas.alias_codigo_estudiante'))) {
                    $colCodigo = $l;
                    $filaEnc = $f;
                } elseif (! $colDoc && TextoSiagie::coincide($t, config('siagie.notas.alias_documento'))) {
                    $colDoc = $l;
                }
            }
        }
        if (! $colCodigo) {
            return null;
        }
        // Primera fila con alumnos; si no se detecta, la fila por defecto (11).
        $inicio = (int) config('siagie.notas.fila_inicio_por_defecto', 11);
        for ($f = $filaEnc + 1; $f <= $filaEnc + 6; $f++) {
            if (TextoSiagie::codigoEstudiante($this->texto($hoja, $colCodigo, $f))) {
                $inicio = $f;
                break;
            }
        }
        $area = $this->detectarArea($hoja, $areas, $filaEnc, $ultima);
        $competencias = [];
        $conclusiones = [];
        $conclusionGeneral = null;
        if ($area) {
            for ($c = 1; $c <= $ultima; $c++) {
                $l = Coordinate::stringFromColumnIndex($c);
                for ($f = max(1, $filaEnc - 3); $f < $inicio; $f++) {
                    $t = $this->texto($hoja, $l, $f);
                    if (TextoSiagie::coincide($t, config('siagie.notas.alias_conclusion'))) {
                        $conclusionGeneral ??= $l;

                        continue;
                    }
                    $k = $this->competencia($t, collect($area->competencias));
                    if ($k && ! isset($competencias[$k->id])) {
                        $competencias[$k->id] = $l;
                        $sig = Coordinate::stringFromColumnIndex($c + 1);
                        for ($ff = max(1, $filaEnc - 3); $ff < $inicio; $ff++) {
                            if (TextoSiagie::coincide($this->texto($hoja, $sig, $ff), config('siagie.notas.alias_conclusion'))) {
                                $conclusiones[$k->id] = $sig;
                                break;
                            }
                        }
                    }
                }
            }
        }

        return ['hoja' => $hoja->getTitle(), 'area_id' => $area?->id, 'area' => $area?->nombre, 'fila_encabezado' => $filaEnc, 'fila_inicio' => $inicio,
            'col_codigo' => $colCodigo, 'col_documento' => $colDoc, 'competencias' => $competencias, 'conclusiones' => $conclusiones, 'col_conclusion_general' => $conclusionGeneral];
    }

    private function detectarArea(Worksheet $hoja, Collection $areas, int $filaEnc, int $ultima): ?object
    {
        $candidatos = [TextoSiagie::normalizar($hoja->getTitle())];
        for ($f = 1; $f < $filaEnc; $f++) {
            for ($c = 1; $c <= min($ultima, 30); $c++) {
                $candidatos[] = TextoSiagie::normalizar($this->texto($hoja, Coordinate::stringFromColumnIndex($c), $f));
            }
        }
        foreach ($areas->sortByDesc(fn ($a) => mb_strlen($a->nombre)) as $a) {
            $n = TextoSiagie::normalizar($a->nombre);
            foreach ($candidatos as $t) {
                if ($t !== '' && ($t === $n || str_contains($t, $n))) {
                    return $a;
                }
            }
        }

        return null;
    }

    private function competencia(string $texto, Collection $competencias): ?object
    {
        $n = TextoSiagie::normalizar($texto);
        if (mb_strlen($n) < 2) {
            return null;
        }
        if (preg_match('/^(C|COMP|COMPETENCIA)\s*(\d{1,2})$/', $n, $m)) {
            return $competencias->firstWhere('numero', (int) $m[2]);
        }
        foreach ($competencias as $k) {
            $nombre = TextoSiagie::normalizar($k->nombre);
            if ($n === $nombre || str_contains($n, $nombre) || (mb_strlen($n) >= 20 && str_starts_with($nombre, $n)) || str_starts_with($n, mb_substr($nombre, 0, 30))) {
                return $k;
            }
        }

        return null;
    }

    private function llenarHoja(Worksheet $hoja, array $m, Collection $porCodigo, Collection $porDni, Collection $notas, array &$encontrados, array &$adv): int
    {
        $escritas = 0;
        $vacias = 0;
        $ultima = $hoja->getHighestDataRow();
        for ($fila = (int) $m['fila_inicio']; $fila <= $ultima; $fila++) {
            $codigo = TextoSiagie::codigoEstudiante($hoja->getCell($m['col_codigo'].$fila)->getValue());
            $dni = ! empty($m['col_documento']) ? TextoSiagie::dni($hoja->getCell($m['col_documento'].$fila)->getValue()) : null;
            if (! $codigo && ! $dni) {
                if (++$vacias >= (int) config('siagie.notas.filas_vacias_para_terminar', 3)) {
                    break;
                }

                continue;
            }
            $vacias = 0;
            $alumno = ($codigo ? $porCodigo->get($codigo) : null) ?? ($dni ? $porDni->get($dni) : null);
            if (! $alumno) {
                $adv[] = "Hoja «{$m['hoja']}», fila {$fila}: el estudiante ".($codigo ?: $dni).' no está matriculado en esta sección; se dejó en blanco.';

                continue;
            }
            $encontrados[$alumno->id] = true;
            foreach ($m['competencias'] as $kid => $col) {
                $nota = $notas->get("{$alumno->id}-{$kid}");
                if (! $nota || $nota->valor === null) {
                    continue;
                }
                $escritas += $this->escribir($hoja, $col.$fila, $nota->valor, $adv);
                $colC = $m['conclusiones'][$kid] ?? null;
                if ($colC && filled($nota->conclusion_descriptiva)) {
                    $this->escribir($hoja, $colC.$fila, $nota->conclusion_descriptiva, $adv);
                }
            }
            if (empty($m['conclusiones']) && ! empty($m['col_conclusion_general'])) {
                $texto = collect(array_keys($m['competencias']))->map(fn ($id) => $notas->get("{$alumno->id}-{$id}")?->conclusion_descriptiva)->filter()->implode(' ');
                if ($texto !== '') {
                    $this->escribir($hoja, $m['col_conclusion_general'].$fila, $texto, $adv);
                }
            }
        }

        return $escritas;
    }

    /** Como TEXTO para que «AD» o «05» no se conviertan; nunca sobre una fórmula. */
    private function escribir(Worksheet $hoja, string $coord, string $valor, array &$adv): int
    {
        $celda = $hoja->getCell($coord);
        if ($celda->isFormula()) {
            $adv[] = "La celda {$coord} de «{$hoja->getTitle()}» tiene una fórmula; no se modificó.";

            return 0;
        }
        $celda->setValueExplicit($valor, DataType::TYPE_STRING);

        return 1;
    }

    private function texto(Worksheet $hoja, string $letra, int $fila): string
    {
        $c = $hoja->getCell($letra.$fila);

        return (string) ($c->isFormula() ? $c->getOldCalculatedValue() : $c->getValue());
    }

    private function registrarProceso(?int $seccionId, int $periodoId, int $originalId, int $resultadoId, array $r, int $usuarioId): int
    {
        $id = (int) DB::table('siagie_procesos')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'tipo' => 'plantilla_notas', 'seccion_id' => $seccionId, 'periodo_id' => $periodoId,
            'archivo_original_id' => $originalId, 'archivo_resultado_id' => $resultadoId, 'estado' => 'completado', 'total_filas' => $r['alumnos'],
            'actualizados' => $r['celdas'], 'mapeo' => json_encode($r['mapeo']), 'advertencias' => json_encode($r['advertencias']), 'usuario_id' => $usuarioId,
            'iniciado_at' => now(), 'finalizado_at' => now(),
        ]);
        $this->auditoria->registrar('plantilla_siagie_generada', 'siagie_procesos', $id, null, ['seccion_id' => $seccionId, 'periodo_id' => $periodoId, 'celdas' => $r['celdas']]);

        return $id;
    }

    // ── EDUN-111 · Todas las secciones (en cola)

    /**
     * Guarda las plantillas y crea el proceso del lote; la cola lo arma (GenerarPlantillasLote).
     *
     * @param  list<UploadedFile>  $plantillas
     */
    public function subirLote(array $plantillas, int $periodoId, int $usuarioId): int
    {
        $ids = [];
        foreach ($plantillas as $p) {
            if (! in_array(strtolower($p->getClientOriginalExtension()), ['xlsx', 'xls'], true)) {
                throw ValidationException::withMessages(['plantillas' => ["«{$p->getClientOriginalName()}» no es un Excel."]]);
            }
            $ids[] = $this->almacen->guardar($p, $usuarioId, true)->id;
        }
        $id = (int) DB::table('siagie_procesos')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'tipo' => 'plantilla_notas', 'periodo_id' => $periodoId, 'archivo_original_id' => $ids[0],
            'estado' => 'pendiente', 'usuario_id' => $usuarioId, 'mapeo' => json_encode(['lote' => $ids]),
        ]);
        $inst = ContextoInstitucion::requerido();
        DB::afterCommit(fn () => GenerarPlantillasLote::dispatch($inst, $id));

        return $id;
    }

    /** Lo ejecuta la cola: reconoce la sección de cada plantilla por los códigos de sus alumnos. */
    public function procesarLote(int $procesoId): void
    {
        $p = DB::table('siagie_procesos')->find($procesoId);
        if (! $p || $p->estado !== 'pendiente') {
            return;
        }
        DB::table('siagie_procesos')->where('id', $procesoId)->update(['estado' => 'procesando', 'iniciado_at' => now()]);
        $anio = DB::table('periodos')->where('id', $p->periodo_id)->value('anio_escolar_id');
        $codigos = DB::table('matriculas as m')->join('alumnos as a', 'a.id', '=', 'm.alumno_id')->join('secciones as s', 's.id', '=', 'm.seccion_id')
            ->where('s.anio_escolar_id', $anio)->where('m.estado', 'matriculado')->whereNotNull('a.codigo_estudiante_siagie')
            ->pluck('m.seccion_id', 'a.codigo_estudiante_siagie');
        $nombres = DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->where('s.anio_escolar_id', $anio)->selectRaw("s.id, g.nombre || ' ' || s.nombre as nombre")->pluck('nombre', 'id');
        $zipRuta = tempnam(sys_get_temp_dir(), 'lote').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipRuta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $advertencias = [];
        $hechas = [];
        foreach (json_decode($p->mapeo, true)['lote'] ?? [] as $archivoId) {
            $archivo = Archivo::query()->find($archivoId);
            $ext = strtolower(pathinfo($archivo->nombre_original, PATHINFO_EXTENSION));
            $tmp = tempnam(sys_get_temp_dir(), 'pl').'.'.$ext;
            file_put_contents($tmp, Storage::disk($archivo->disco)->get($archivo->ruta));
            try {
                $seccion = $this->seccionDePlantilla($tmp, $codigos);
                if (! $seccion) {
                    $advertencias[] = "{$archivo->nombre_original}: no se reconoció a qué sección pertenece (ningún código coincide).";

                    continue;
                }
                $v = $this->validar($seccion, (int) $p->periodo_id);
                if (! $v['valido']) {
                    $advertencias[] = "{$nombres[$seccion]}: tiene ".count($v['errores']).' problemas en las notas; no se generó.';

                    continue;
                }
                $r = $this->llenar($tmp, $ext, $seccion, (int) $p->periodo_id);
                $zip->addFromString(str_replace(['/', ' '], ['-', '_'], (string) $nombres[$seccion]).'.'.$ext, $r['contenido']);
                $hechas[] = $seccion;
                foreach ($r['advertencias'] as $a) {
                    $advertencias[] = "{$nombres[$seccion]}: {$a}";
                }
            } catch (ValidationException $e) {
                $advertencias[] = "{$archivo->nombre_original}: ".collect($e->errors())->except('mapeo_detectado')->flatten()->implode(' ');
            } finally {
                @unlink($tmp);
            }
        }
        foreach ($nombres as $id => $nombre) {
            if (! in_array($id, $hechas, true) && DB::table('cargas_docentes')->where('seccion_id', $id)->exists()) {
                $advertencias[] = "{$nombre}: sin plantilla generada en este lote.";
            }
        }
        $zip->addFromString('RESUMEN.txt', 'Plantillas generadas: '.count($hechas)."\n\n".implode("\n", $advertencias));
        $zip->close();
        $resultado = $this->almacen->guardarContenido((string) file_get_contents($zipRuta), 'plantillas-siagie.zip', 'application/zip', (int) $p->usuario_id, true);
        @unlink($zipRuta);
        DB::table('siagie_procesos')->where('id', $procesoId)->update([
            'estado' => $hechas ? ($advertencias ? 'con_errores' : 'completado') : 'fallido', 'archivo_resultado_id' => $resultado->id,
            'total_filas' => count($hechas), 'advertencias' => json_encode($advertencias), 'finalizado_at' => now(),
        ]);
    }

    private function seccionDePlantilla(string $ruta, Collection $codigos): ?int
    {
        $lector = IOFactory::createReaderForFile($ruta);
        $lector->setReadDataOnly(true);
        $libro = $lector->load($ruta);
        $conteo = [];
        foreach ($libro->getAllSheets() as $hoja) {
            foreach ($hoja->toArray(null, false, false, false) as $fila) {
                foreach ($fila as $celda) {
                    $c = TextoSiagie::codigoEstudiante($celda);
                    if ($c && strlen(TextoSiagie::soloDigitos($celda)) >= 10 && isset($codigos[$c])) {
                        $conteo[$codigos[$c]] = ($conteo[$codigos[$c]] ?? 0) + 1;
                    }
                }
            }
        }
        $libro->disconnectWorksheets();
        arsort($conteo);

        return $conteo ? (int) array_key_first($conteo) : null;
    }
}
