<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
| Sprint 20 · Integración SIAGIE por archivos:
| EDUN-106 nómina · EDUN-107 no figuran · EDUN-108 validación · EDUN-109 plantilla · EDUN-110 mapeo manual · EDUN-111 lote
| EDUN-112: estos Excel son sintéticos (formato según docs/07); falta confirmarlos con archivos reales de un colegio piloto.
*/

beforeEach(fn () => Storage::fake('objetos'));

/** Sección propia (3.° secundaria «S») con un curso de Matemática y dos alumnos con código SIAGIE. */
function seccionSiagie(): array
{
    static $s = null;
    if ($s) {
        return $s;
    }
    $db = dueno();
    $inst = demoId();
    $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $grado = (int) $db->table('grados')->where('institucion_id', $inst)->where('nivel', 'secundaria')->where('numero', 3)->value('id');
    $seccion = $db->table('secciones')->where('anio_escolar_id', $anio)->where('grado_id', $grado)->where('nombre', 'S')->value('id')
        ?? $db->table('secciones')->insertGetId(['institucion_id' => $inst, 'anio_escolar_id' => $anio, 'grado_id' => $grado, 'nombre' => 'S']);
    $personal = $db->table('personal')->insertGetId(['institucion_id' => $inst, 'numero_documento' => (string) random_int(10000000, 99999999), 'apellidos' => 'Docente', 'nombres' => 'SIAGIE', 'cargo' => 'Docente']);
    $area = (int) $db->table('areas_curriculares')->where('codigo', 'SEC-MAT')->value('id');
    $db->table('cargas_docentes')->insert(['institucion_id' => $inst, 'seccion_id' => $seccion, 'area_curricular_id' => $area, 'personal_id' => $personal]);
    $alumnos = [];
    foreach ([['Lucía', '1111222'], ['Mario', '3333444']] as $i => [$n, $dni]) {
        $a = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Siagie', 'nombres' => $n, 'numero_documento' => $dni.$i,
            'codigo_estudiante_siagie' => '0000099990000'.$i]);
        $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $a, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion]);
        $alumnos[] = $a;
    }

    return $s = ['anio' => $anio, 'grado' => $grado, 'seccion' => $seccion, 'area' => $area, 'alumnos' => $alumnos,
        'competencias' => $db->table('competencias')->where('area_curricular_id', $area)->where('vigente', true)->orderBy('numero')->get(['id', 'numero', 'nombre'])->all(),
        'periodo' => (int) $db->table('periodos')->where('anio_escolar_id', $anio)->where('numero', 2)->value('id')];
}

function excel(Spreadsheet $libro, string $nombre): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    return new UploadedFile($ruta, $nombre, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** Plantilla de notas al estilo del SIAGIE: título del área, encabezado en la fila 9 y alumnos desde la 11. */
function plantillaNotas(array $s, string $tituloHoja = 'Matemática', array $codigosExtra = []): UploadedFile
{
    $libro = new Spreadsheet;
    $h = $libro->getActiveSheet();
    $h->setTitle($tituloHoja);
    $h->setCellValue('A2', 'REGISTRO DE EVALUACIÓN · ÁREA: '.mb_strtoupper($tituloHoja));
    $h->setCellValue('A9', 'N°')->setCellValue('B9', 'Código del Estudiante')->setCellValue('C9', 'DNI')->setCellValue('D9', 'Apellidos y nombres');
    $col = 'E';
    foreach ($s['competencias'] as $i => $k) {
        $h->setCellValue($col.'9', 'C'.$k->numero);
        $col++;
        if ($i === 0) {
            $h->setCellValue($col.'9', 'Conclusión descriptiva');
            $col++;
        }
    }
    $fila = 11;
    foreach ([...array_map(fn ($a) => dueno()->table('alumnos')->where('id', $a)->value('codigo_estudiante_siagie'), $s['alumnos']), ...$codigosExtra] as $i => $codigo) {
        $h->setCellValueExplicit('B'.$fila, $codigo, DataType::TYPE_STRING);
        $h->setCellValue('A'.$fila, $i + 1);
        $fila++;
    }
    $h->setCellValue('Z11', '=1+1');

    return excel($libro, 'plantilla-3S.xlsx');
}

function notasCompletas(array $s, string $valor = 'A'): void
{
    foreach ($s['alumnos'] as $a) {
        foreach ($s['competencias'] as $k) {
            dueno()->table('calificaciones')->updateOrInsert(['alumno_id' => $a, 'competencia_id' => $k->id, 'periodo_id' => $s['periodo']],
                ['institucion_id' => demoId(), 'seccion_id' => $s['seccion'], 'valor' => $valor, 'conclusion_descriptiva' => $valor === 'C' ? 'Reforzar cálculo.' : null]);
        }
    }
}

it('importa la nómina en cola: crea y actualiza alumnos y matrículas, errores por fila y alumnos que no figuran', function () {
    $s = seccionSiagie();
    $secretaria = personaDemo('secretaria');
    $libro = new Spreadsheet;
    $h = $libro->getActiveSheet();
    $h->fromArray([['NÓMINA DE MATRÍCULA 2027'], [], [], ['Código del Estudiante', 'Tipo de documento', 'Número de documento', 'Apellido Paterno', 'Apellido Materno', 'Nombres', 'Sexo', 'Fecha de Nacimiento']], null, 'A1');
    $existente = dueno()->table('alumnos')->find($s['alumnos'][0]);
    $h->fromArray([
        [$existente->codigo_estudiante_siagie, 'DNI', $existente->numero_documento, 'SIAGIE', 'NUEVO', 'LUCÍA ANDREA', 'Mujer', '14/05/2012'],
        ['00000888770011', 'DNI', '7012345', 'QUISPE', 'MAMANI', 'JOSÉ', 'Hombre', '02/03/2012'],
        ['00000888770012', 'DNI', '70999888', '', '', '', 'Hombre', ''],
    ], null, 'A5', true);
    $h->setCellValueExplicit('C6', '7012345', DataType::TYPE_NUMERIC);

    enDemo($secretaria, 'POST', '/siagie/nominas', ['archivo' => UploadedFile::fake()->create('nomina.pdf', 10), 'seccion_id' => $s['seccion']])->assertUnprocessable();
    $r = enDemo($secretaria, 'POST', '/siagie/nominas', ['archivo' => excel($libro, 'nomina-3S.xlsx'), 'seccion_id' => $s['seccion']])->assertStatus(202);
    $p = enDemo($secretaria, 'GET', '/siagie/procesos/'.$r->json('id'))->assertOk();
    expect($p->json('estado'))->toBe('con_errores')->and($p->json('total_filas'))->toBe(3)->and($p->json('creados'))->toBe(1)->and($p->json('actualizados'))->toBe(1)
        ->and($p->json('errores.0.fila'))->toBe(7)->and($p->json('errores.0.mensaje'))->toContain('Faltan apellidos');
    // El existente se actualizó; el nuevo quedó con su DNI completado a 8 dígitos y matriculado en la sección.
    expect(dueno()->table('alumnos')->find($s['alumnos'][0])->apellido_materno)->toBe('Nuevo');
    $nuevo = dueno()->table('alumnos')->where('codigo_estudiante_siagie', '00000888770011')->first();
    expect($nuevo->numero_documento)->toBe('07012345')->and($nuevo->sexo)->toBe('H')->and($nuevo->fecha_nacimiento)->toBe('2012-03-02')
        ->and(dueno()->table('matriculas')->where('alumno_id', $nuevo->id)->where('seccion_id', $s['seccion'])->value('origen'))->toBe('siagie');
    // Mario está matriculado en Edunova pero no figura en la nómina.
    expect(collect($p->json('advertencias.no_figuran'))->pluck('id')->all())->toBe([$s['alumnos'][1]]);
    expect(collect(enDemo($secretaria, 'GET', '/siagie/procesos?tipo=nomina')->json('data'))->firstWhere('id', $r->json('id'))['archivo'])->toBe('nomina-3S.xlsx');
    // Se retira al alumno nuevo para que las demás pruebas usen la sección con sus dos alumnos.
    dueno()->table('cargos')->where('alumno_id', $nuevo->id)->delete();
    dueno()->table('matriculas')->where('alumno_id', $nuevo->id)->delete();
});

it('valida las notas antes de exportar con enlace a la celda a corregir', function () {
    $s = seccionSiagie();
    $coordinador = personaDemo('coordinador');
    dueno()->table('calificaciones')->whereIn('alumno_id', $s['alumnos'])->where('periodo_id', $s['periodo'])->delete();
    $v = enDemo($coordinador, 'GET', "/siagie/validacion?seccion_id={$s['seccion']}&periodo_id={$s['periodo']}")->assertOk();
    expect($v->json('valido'))->toBeFalse()->and($v->json('resumen.vacia'))->toBe(count($s['alumnos']) * count($s['competencias']))
        ->and($v->json('errores.0.enlace'))->toStartWith('#/notas/');

    notasCompletas($s, 'C');
    dueno()->table('calificaciones')->where('alumno_id', $s['alumnos'][0])->where('competencia_id', $s['competencias'][0]->id)->where('periodo_id', $s['periodo'])->update(['conclusion_descriptiva' => null]);
    $v = enDemo($coordinador, 'GET', "/siagie/validacion?seccion_id={$s['seccion']}&periodo_id={$s['periodo']}")->assertOk();
    expect($v->json('resumen'))->toBe(['c_sin_conclusion' => 1]);
    notasCompletas($s);
    expect(enDemo($coordinador, 'GET', "/siagie/validacion?seccion_id={$s['seccion']}&periodo_id={$s['periodo']}")->json('valido'))->toBeTrue();
});

it('llena la plantilla del SIAGIE como texto sin tocar fórmulas y conserva el original', function () {
    $s = seccionSiagie();
    $secretaria = personaDemo('secretaria');
    dueno()->table('calificaciones')->whereIn('alumno_id', $s['alumnos'])->where('periodo_id', $s['periodo'])->delete();
    enDemo($secretaria, 'POST', '/siagie/plantillas', ['plantilla' => plantillaNotas($s), 'seccion_id' => $s['seccion'], 'periodo_id' => $s['periodo']])
        ->assertUnprocessable()->assertJsonValidationErrors('notas');

    notasCompletas($s, 'C');
    $r = enDemo($secretaria, 'POST', '/siagie/plantillas', ['plantilla' => plantillaNotas($s, 'Matemática', ['00000777770001']), 'seccion_id' => $s['seccion'], 'periodo_id' => $s['periodo']])->assertCreated();
    expect($r->json('estado'))->toBe('completado')->and($r->json('total_filas'))->toBe(2)->and($r->json('actualizados'))->toBe(2 * count($s['competencias']))
        ->and(collect($r->json('advertencias'))->implode(' '))->toContain('00000777770001');

    $archivo = dueno()->table('archivos')->find($r->json('archivo_resultado_id'));
    $ruta = tempnam(sys_get_temp_dir(), 'r').'.xlsx';
    file_put_contents($ruta, Storage::disk('objetos')->get($archivo->ruta));
    $h = IOFactory::load($ruta)->getSheet(0);
    expect($h->getCell('E11')->getValue())->toBe('C')->and($h->getCell('E11')->getDataType())->toBe('s')
        ->and($h->getCell('F11')->getValue())->toBe('Reforzar cálculo.')->and($h->getCell('Z11')->getValue())->toBe('=1+1');
    expect($r->json('archivo_original_id'))->not->toBe($r->json('archivo_resultado_id'));
    enDemo($secretaria, 'GET', '/siagie/procesos/'.$r->json('id').'/resultado')->assertOk()->assertJsonStructure(['url']);
});

it('si no reconoce la hoja usa el mapeo manual guardado por grado', function () {
    $s = seccionSiagie();
    notasCompletas($s);
    $coordinador = personaDemo('coordinador');
    $raro = plantillaNotas($s, 'Hoja1');
    enDemo($coordinador, 'POST', '/siagie/plantillas', ['plantilla' => $raro, 'seccion_id' => $s['seccion'], 'periodo_id' => $s['periodo']])->assertUnprocessable()->assertJsonValidationErrors('plantilla');

    $competencias = [];
    $col = 'E';
    foreach ($s['competencias'] as $i => $k) {
        $competencias[$k->id] = $col;
        $col++;
        if ($i === 0) {
            $col++;
        }
    }
    enDemo($coordinador, 'PUT', "/siagie/mapeos/{$s['grado']}", ['hojas' => [['hoja' => 'Hoja1', 'area_id' => $s['area'], 'fila_inicio' => 11, 'col_codigo' => 'b', 'competencias' => $competencias]]])
        ->assertUnprocessable();
    enDemo($coordinador, 'PUT', "/siagie/mapeos/{$s['grado']}", ['hojas' => [['hoja' => 'Hoja1', 'area_id' => $s['area'], 'fila_inicio' => 11, 'col_codigo' => 'B', 'competencias' => $competencias]]])->assertOk();
    expect(enDemo($coordinador, 'GET', "/siagie/mapeos/{$s['grado']}")->json('mapeo.0.col_codigo'))->toBe('B');
    $r = enDemo($coordinador, 'POST', '/siagie/plantillas', ['plantilla' => plantillaNotas($s, 'Hoja1'), 'seccion_id' => $s['seccion'], 'periodo_id' => $s['periodo']])->assertCreated();
    expect($r->json('actualizados'))->toBe(2 * count($s['competencias']));
    dueno()->table('siagie_mapeos')->where('grado_id', $s['grado'])->update(['vigente' => false]);
});

it('genera en cola las plantillas de varias secciones en un ZIP con el resumen de advertencias', function () {
    $s = seccionSiagie();
    notasCompletas($s);
    $secretaria = personaDemo('secretaria');
    $ajena = new Spreadsheet;
    $ajena->getActiveSheet()->setCellValue('A1', 'Sin alumnos');
    $r = enDemo($secretaria, 'POST', '/siagie/plantillas/lote', ['plantillas' => [plantillaNotas($s), excel($ajena, 'otra.xlsx')], 'periodo_id' => $s['periodo']])->assertStatus(202);
    $p = enDemo($secretaria, 'GET', '/siagie/procesos/'.$r->json('id'))->assertOk();
    expect($p->json('estado'))->toBe('con_errores')->and($p->json('total_filas'))->toBe(1)
        ->and(collect($p->json('advertencias'))->implode(' '))->toContain('otra.xlsx');
    $zip = new ZipArchive;
    $ruta = tempnam(sys_get_temp_dir(), 'z').'.zip';
    file_put_contents($ruta, Storage::disk('objetos')->get(dueno()->table('archivos')->find($p->json('archivo_resultado_id'))->ruta));
    $zip->open($ruta);
    $nombres = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    expect($nombres)->toContain('RESUMEN.txt')->and(collect($nombres)->filter(fn ($n) => str_ends_with($n, '.xlsx'))->count())->toBe(1);
});
