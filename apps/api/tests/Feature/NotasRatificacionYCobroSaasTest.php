<?php

use App\Models\User;
use App\Modulos\ADM\Services\RegistroAlumnos;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\PAG\Services\Cobros;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/*
| Sprint 18:
| EDUN-91 notas por competencia · EDUN-93 actividades · EDUN-97 cierre · EDUN-98 reapertura
| EDUN-55 ratificar matrícula desde la app · EDUN-82 estado de cuenta · EDUN-27 cobro mensual del SaaS
*/

function anio2027s18(): int
{
    return (int) dueno()->table('anios_escolares')->where('institucion_id', demoId())->where('anio', 2027)->value('id');
}

function periodoS18(int $numero = 3): int
{
    return (int) dueno()->table('periodos')->where('anio_escolar_id', anio2027s18())->where('numero', $numero)->value('id');
}

/** Sección propia (3.° de secundaria «N») con dos alumnos, un curso de Matemática y su docente con usuario. */
function cursoS18(): array
{
    static $curso = null;
    if ($curso) {
        return $curso;
    }
    $db = dueno();
    $inst = demoId();
    $grado = $db->table('grados')->where('institucion_id', $inst)->where('nivel', 'secundaria')->where('numero', 3)->value('id');
    $seccion = $db->table('secciones')->insertGetId(['institucion_id' => $inst, 'anio_escolar_id' => anio2027s18(), 'grado_id' => $grado, 'nombre' => 'N']);
    $docente = personaDemo('docente');
    $personal = $db->table('personal')->insertGetId(['institucion_id' => $inst, 'user_id' => $docente->id, 'numero_documento' => (string) random_int(10000000, 99999999),
        'apellidos' => 'Docente S18', 'nombres' => 'Lucía', 'cargo' => 'Docente']);
    $area = $db->table('areas_curriculares')->where('codigo', 'SEC-MAT')->value('id');
    $carga = $db->table('cargas_docentes')->insertGetId(['institucion_id' => $inst, 'seccion_id' => $seccion, 'area_curricular_id' => $area, 'personal_id' => $personal]);
    $alumnos = [];
    foreach (['Ana', 'Beto'] as $n) {
        $a = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Notas', 'nombres' => $n, 'numero_documento' => (string) random_int(70000000, 79999999)]);
        $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $a, 'anio_escolar_id' => anio2027s18(), 'seccion_id' => $seccion]);
        $alumnos[] = $a;
    }

    return $curso = ['seccion' => $seccion, 'carga' => $carga, 'docente' => $docente, 'alumnos' => $alumnos,
        'competencias' => $db->table('competencias')->where('area_curricular_id', $area)->where('vigente', true)->orderBy('orden')->orderBy('numero')->pluck('id')->all()];
}

it('el docente registra notas por competencia con la escala del grado y la conclusión obligatoria con C', function () {
    $c = cursoS18();
    $p = periodoS18();
    $celda = fn (User $u, int $alumno, int $comp, ?string $valor, ?string $conclusion = null) => enDemo($u, 'PUT', "/notas/cargas/{$c['carga']}/celda",
        ['periodo_id' => $p, 'alumno_id' => $alumno, 'competencia_id' => $comp, 'valor' => $valor, 'conclusion' => $conclusion]);

    $cargas = enDemo($c['docente'], 'GET', '/notas/cargas?anio_escolar_id='.anio2027s18())->assertOk();
    expect(collect($cargas->json('cargas'))->pluck('id')->all())->toBe([$c['carga']]);
    $g = enDemo($c['docente'], 'GET', "/notas/cargas/{$c['carga']}?periodo_id=$p")->assertOk();
    expect($g->json('escala'))->toBe('literal')->and($g->json('valores'))->toBe(['AD', 'A', 'B', 'C'])->and($g->json('alumnos'))->toHaveCount(2)
        ->and($g->json('resumen.vacias'))->toBe(2 * count($c['competencias']));

    $celda($c['docente'], $c['alumnos'][0], $c['competencias'][0], 'X')->assertUnprocessable()->assertJsonValidationErrors('valor');
    $celda($c['docente'], $c['alumnos'][0], $c['competencias'][0], 'a')->assertOk()->assertJson(['valor' => 'A', 'observacion' => null]);
    $celda($c['docente'], $c['alumnos'][1], $c['competencias'][0], 'C')->assertOk()->assertJsonPath('observacion', 'Con C la conclusión descriptiva es obligatoria (SIAGIE).');
    expect(enDemo($c['docente'], 'GET', "/notas/cargas/{$c['carga']}?periodo_id=$p")->json('resumen.c_sin_conclusion'))->toBe(1);

    // Otro docente no ve ni califica este curso.
    $otro = personaDemo('docente');
    enDemo($otro, 'GET', "/notas/cargas/{$c['carga']}?periodo_id=$p")->assertForbidden();
    $celda($otro, $c['alumnos'][0], $c['competencias'][0], 'B')->assertForbidden();
});

it('las actividades de evaluación sugieren la nota y el docente confirma la oficial', function () {
    $c = cursoS18();
    $p = periodoS18();
    $fechaDentro = (string) dueno()->table('periodos')->find($p)->fecha_inicio;
    $r = enDemo($c['docente'], 'POST', "/notas/cargas/{$c['carga']}/actividades", ['periodo_id' => $p, 'competencia_id' => $c['competencias'][1], 'nombre' => 'Práctica 1', 'fecha' => $fechaDentro])->assertCreated();
    $r2 = enDemo($c['docente'], 'POST', "/notas/cargas/{$c['carga']}/actividades", ['periodo_id' => $p, 'competencia_id' => $c['competencias'][1], 'nombre' => 'Práctica 2'])->assertCreated();
    enDemo($c['docente'], 'PUT', '/notas/actividades/'.$r->json('id').'/notas', ['notas' => [$c['alumnos'][0] => ['valor' => 'Z']]])->assertUnprocessable();
    enDemo($c['docente'], 'PUT', '/notas/actividades/'.$r->json('id').'/notas', ['notas' => [$c['alumnos'][0] => ['valor' => 'AD', 'comentario' => 'Muy bien']]])->assertOk()->assertJson(['guardadas' => 1]);
    enDemo($c['docente'], 'PUT', '/notas/actividades/'.$r2->json('id').'/notas', ['notas' => [$c['alumnos'][0] => ['valor' => 'B']]])->assertOk();

    $act = enDemo($c['docente'], 'GET', "/notas/cargas/{$c['carga']}/actividades?periodo_id=$p")->assertOk();
    expect($act->json('actividades'))->toHaveCount(2)->and($act->json('actividades.0.notas.'.$c['alumnos'][0].'.comentario'))->toBe('Muy bien');
    // (AD=4 + B=2) / 2 = 3 → A. Es sugerencia: la nota oficial sigue vacía.
    $g = enDemo($c['docente'], 'GET', "/notas/cargas/{$c['carga']}?periodo_id=$p")->json('celdas');
    expect($g[$c['alumnos'][0].'-'.$c['competencias'][1]])->sugerencia->toBe('A')->valor->toBeNull();
});

it('la coordinación ve el avance, recuerda a los docentes, cierra la sección completa y el director la reabre con motivo', function () {
    Http::fake();
    $c = cursoS18();
    $p = periodoS18();
    $coordinador = personaDemo('coordinador');

    $avance = enDemo($coordinador, 'GET', "/notas/periodos/$p/avance")->assertOk();
    $s = collect($avance->json('secciones'))->firstWhere('seccion_id', $c['seccion']);
    expect($s['completo'])->toBeFalse()->and($s['areas'][0]['docente'])->toContain('Lucía');
    expect(enDemo($coordinador, 'POST', "/notas/periodos/$p/recordatorio")->assertOk()->json('docentes'))->toBeGreaterThanOrEqual(1);
    enDemo($coordinador, 'POST', "/notas/periodos/$p/cierre", ['seccion_id' => $c['seccion']])->assertUnprocessable()->assertJsonValidationErrors('seccion_id');
    enDemo($c['docente'], 'POST', "/notas/periodos/$p/cierre", ['seccion_id' => $c['seccion']])->assertForbidden();

    foreach ($c['alumnos'] as $a) {
        foreach ($c['competencias'] as $k) {
            enDemo($c['docente'], 'PUT', "/notas/cargas/{$c['carga']}/celda", ['periodo_id' => $p, 'alumno_id' => $a, 'competencia_id' => $k, 'valor' => 'C', 'conclusion' => 'Necesita reforzar con acompañamiento.'])->assertOk();
        }
    }
    $cerrado = enDemo($coordinador, 'POST', "/notas/periodos/$p/cierre", ['seccion_id' => $c['seccion']])->assertOk();
    expect(collect($cerrado->json('secciones'))->firstWhere('seccion_id', $c['seccion'])['estado'])->toBe('cerrado');
    // Cerrar bloquea cambios (en la BD).
    enDemo($c['docente'], 'PUT', "/notas/cargas/{$c['carga']}/celda", ['periodo_id' => $p, 'alumno_id' => $c['alumnos'][0], 'competencia_id' => $c['competencias'][0], 'valor' => 'AD'])
        ->assertUnprocessable()->assertJsonValidationErrors('periodo_id');

    enDemo(directorDemo(), 'POST', "/notas/periodos/$p/reapertura", ['seccion_id' => $c['seccion'], 'motivo' => 'corto'])->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $re = enDemo(directorDemo(), 'POST', "/notas/periodos/$p/reapertura", ['seccion_id' => $c['seccion'], 'motivo' => 'Error de digitación en la nota de Ana'])->assertOk();
    expect(collect($re->json('secciones'))->firstWhere('seccion_id', $c['seccion'])['estado'])->toBe('revision')
        ->and(dueno()->table('auditoria')->where('accion', 'periodo_reabierto')->latest('id')->value('despues'))->toContain('Error de digitación');
    enDemo($c['docente'], 'PUT', "/notas/cargas/{$c['carga']}/celda", ['periodo_id' => $p, 'alumno_id' => $c['alumnos'][0], 'competencia_id' => $c['competencias'][0], 'valor' => 'AD'])->assertOk();
});

it('el apoderado ratifica la matrícula del año siguiente, paga la matrícula y descarga la constancia; ve su estado de cuenta', function () {
    $db = dueno();
    $inst = demoId();
    $anio2028 = $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2028)->value('id')
        ?? $db->table('anios_escolares')->insertGetId(['institucion_id' => $inst, 'anio' => 2028, 'tipo_periodo' => 'bimestre', 'fecha_inicio' => '2028-03-01', 'fecha_fin' => '2028-12-15']);
    $grado3 = $db->table('grados')->where('institucion_id', $inst)->where('nivel', 'secundaria')->where('numero', 3)->value('id');
    $servicio = $db->table('grados')->find($grado3)->servicio_educativo_id;
    $grado4 = $db->table('grados')->where('servicio_educativo_id', $servicio)->where('numero', 4)->value('id')
        ?? $db->table('grados')->insertGetId(['institucion_id' => $inst, 'servicio_educativo_id' => $servicio, 'nivel' => 'secundaria', 'numero' => 4, 'nombre' => '4.° de secundaria']);
    $sec3 = $db->table('secciones')->insertGetId(['institucion_id' => $inst, 'anio_escolar_id' => anio2027s18(), 'grado_id' => $grado3, 'nombre' => 'R'.random_int(10, 99)]);
    $nombre = $db->table('secciones')->find($sec3)->nombre;
    $sec4 = $db->table('secciones')->insertGetId(['institucion_id' => $inst, 'anio_escolar_id' => $anio2028, 'grado_id' => $grado4, 'nombre' => $nombre, 'vacantes' => 30]);
    if (! $db->table('condiciones_economicas')->where('anio_escolar_id', $anio2028)->exists()) {
        $db->table('condiciones_economicas')->insert(['institucion_id' => $inst, 'anio_escolar_id' => $anio2028, 'version' => 1, 'contenido' => 'Condiciones de prueba 2028.', 'publicado_at' => now()]);
    }
    $mat = $db->table('conceptos_cobro')->where('institucion_id', $inst)->where('codigo', 'MAT')->value('id');
    $pen = $db->table('conceptos_cobro')->where('institucion_id', $inst)->where('codigo', 'PEN')->value('id');
    if (! $db->table('cronogramas')->where('anio_escolar_id', $anio2028)->exists()) {
        $cron = $db->table('cronogramas')->insertGetId(['institucion_id' => $inst, 'anio_escolar_id' => $anio2028, 'nombre' => 'Pensiones 2028']);
        $db->table('cronograma_cuotas')->insert([
            ['institucion_id' => $inst, 'cronograma_id' => $cron, 'concepto_cobro_id' => $mat, 'numero' => 1, 'mes' => '2028-02', 'emision' => '2028-01-15', 'vencimiento' => '2028-02-28', 'monto' => 300],
            ['institucion_id' => $inst, 'cronograma_id' => $cron, 'concepto_cobro_id' => $pen, 'numero' => 2, 'mes' => '2028-03', 'emision' => '2028-03-01', 'vencimiento' => '2028-03-31', 'monto' => 350],
        ]);
    }
    // Familia con un hijo matriculado en 3.° (2027).
    $userId = $db->table('users')->insertGetId(['nombres' => 'Elsa', 'apellidos' => 'Ratifica '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999),
        'email' => 'elsa.'.uniqid().'@familia.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $userId, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $apoderado = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $userId, 'numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'Ratifica', 'nombres' => 'Elsa']);
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Ratifica', 'nombres' => 'Hugo', 'numero_documento' => (string) random_int(70000000, 79999999)]);
    $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'apoderado_id' => $apoderado, 'parentesco' => 'madre', 'es_responsable_pago' => true]);
    DB::transaction(function () use ($alumno, $sec3) {
        ContextoInstitucion::fijar(demoId());
        app(RegistroAlumnos::class)->matricular($alumno, $sec3, 'nuevo');
    });
    $familia = User::query()->findOrFail($userId);

    $o = enDemo($familia, 'GET', '/familia/ratificacion')->assertOk();
    $hijo = collect($o->json('hijos'))->firstWhere('alumno_id', $alumno);
    expect($hijo['anio'])->toBe(2028)->and($hijo['seccion']['id'])->toBe($sec4)->and($hijo['condiciones']['contenido'])->toContain('2028');

    enDemo($familia, 'POST', '/familia/ratificacion', ['alumno_id' => $alumno])->assertUnprocessable()->assertJsonValidationErrors('acepta_condiciones');
    $r = enDemo($familia, 'POST', '/familia/ratificacion', ['alumno_id' => $alumno, 'acepta_condiciones' => true, 'celular' => '987111222', 'direccion' => 'Av. Prueba 123'])->assertCreated();
    expect($r->json('estado'))->toBe('reservado')->and($db->table('apoderados')->find($apoderado)->celular)->toBe('987111222')
        ->and($db->table('condiciones_aceptaciones')->where('matricula_id', $r->json('matricula_id'))->exists())->toBeTrue()
        ->and($db->table('cargos')->where('matricula_id', $r->json('matricula_id'))->count())->toBe(1);
    enDemo($familia, 'POST', '/familia/ratificacion', ['alumno_id' => $alumno, 'acepta_condiciones' => true])->assertUnprocessable();
    enDemo($familia, 'GET', '/familia/constancias/'.$r->json('matricula_id'))->assertNotFound();

    // Estado de cuenta: aparece la matrícula por pagar como próximo vencimiento.
    $ec = enDemo($familia, 'GET', '/familia/estado-cuenta')->assertOk();
    $h = collect($ec->json('hijos'))->firstWhere('alumno_id', $alumno);
    $proximo = collect($h['cargos'])->whereIn('estado', ['pendiente', 'parcial'])->where('vencimiento', '>=', now()->toDateString())->sortBy('vencimiento')->first();
    expect($h['proximo']['vencimiento'])->toBe($proximo['vencimiento'])->and(collect($h['cargos'])->pluck('id')->all())->toContain($r->json('cargo_id'))
        ->and($ec->json('comprobantes'))->toBeArray();

    // Paga la matrícula: se confirma, se generan las pensiones y hay constancia.
    DB::transaction(function () use ($r) {
        ContextoInstitucion::fijar(demoId());
        app(Cobros::class)->registrar([['id' => $r->json('cargo_id'), 'monto' => 300]], [], ['medio' => 'yape'], null);
    });
    expect($db->table('matriculas')->find($r->json('matricula_id'))->estado)->toBe('matriculado')
        ->and($db->table('cargos')->where('matricula_id', $r->json('matricula_id'))->count())->toBe(2);
    $this->get('/api/v1/familia/constancias/'.$r->json('matricula_id'), ['X-Institucion' => (string) demoId()])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    enDemo(familiaOtraS18(), 'GET', '/familia/constancias/'.$r->json('matricula_id'))->assertNotFound();
});

function familiaOtraS18(): User
{
    return personaDemo('apoderado');
}

it('calcula y emite el cobro mensual con meses gratis, alumnos al día 1 y comprobantes sobre lo incluido', function () {
    $db = dueno();
    $inst = demoId();
    $sus = $db->table('suscripciones')->where('institucion_id', $inst)->first();
    $plan = $db->table('planes')->find($sus->plan_id);
    // Valores de prueba (no son precios reales).
    $db->table('planes')->update(['precio_mensual' => 100, 'precio_comprobante' => 0.5, 'comprobantes_incluidos' => 2]);
    $db->table('suscripciones')->where('id', $sus->id)->update(['fecha_inicio' => '2027-01-01', 'meses_gratis' => 2, 'precio_pactado' => null, 'precio_congelado_hasta' => null]);
    $db->table('facturas_saas')->where('suscripcion_id', $sus->id)->delete();
    $prov = $db->table('proveedores_facturacion')->where('institucion_id', $inst)->value('id');
    $base = (int) $db->table('comprobantes')->where('institucion_id', $inst)->where('serie', 'B999')->max('numero');
    foreach (range(1, 5) as $i) {
        $db->table('comprobantes')->insert(['institucion_id' => $inst, 'proveedor_facturacion_id' => $prov, 'tipo' => '03', 'serie' => 'B999', 'numero' => $base + $i, 'fecha_emision' => '2027-04-1'.$i,
            'cliente_tipo_doc' => '0', 'cliente_numero_doc' => '-', 'cliente_nombre' => 'X', 'total' => 10, 'estado_sunat' => 'aceptado']);
    }
    $sa = superadmin();
    actuarComo($sa, true);

    $feb = $this->getJson('/api/v1/plataforma/cobro-mensual?periodo=2027-02')->assertOk();
    expect(collect($feb->json('calculo'))->firstWhere('suscripcion_id', $sus->id))->gratis->toBeTrue()->total->toEqual(0);

    $abr = $this->getJson('/api/v1/plataforma/cobro-mensual?periodo=2027-04')->assertOk();
    $f = collect($abr->json('calculo'))->firstWhere('suscripcion_id', $sus->id);
    $alumnos = $db->table('matriculas')->where('institucion_id', $inst)->where('anio_escolar_id', anio2027s18())->where('estado', 'matriculado')->where('fecha_matricula', '<=', '2027-04-01')->count();
    expect($f['gratis'])->toBeFalse()->and($f['alumnos'])->toBe($alumnos)->and($f['comprobantes_emitidos'])->toBe(5)->and($f['comprobantes_cobrados'])->toBe(3)
        ->and($f['monto_comprobantes'])->toEqual(1.5)->and($f['total'])->toEqual(101.5)->and($f['lineas'])->toHaveCount(2);

    $e = $this->postJson('/api/v1/plataforma/cobro-mensual', ['periodo' => '2027-04'])->assertOk();
    $emitida = collect($e->json('emitidas'))->firstWhere('colegio', $f['colegio']);
    expect($emitida['comprobante'])->toStartWith('F001-');
    $factura = $db->table('facturas_saas')->where('suscripcion_id', $sus->id)->where('periodo', '2027-04')->first();
    expect((float) $factura->monto)->toBe(101.5)->and($factura->comprobante_tipo)->toBe('01')->and(json_decode($factura->detalle, true))->toHaveCount(2);
    // Volver a emitir el mismo mes no duplica.
    expect(collect($this->postJson('/api/v1/plataforma/cobro-mensual', ['periodo' => '2027-04'])->json('emitidas'))->firstWhere('colegio', $f['colegio']))->toBeNull();
    $this->patchJson("/api/v1/plataforma/facturas-saas/{$factura->id}", ['estado' => 'pagada'])->assertOk();
    expect($db->table('facturas_saas')->find($factura->id)->estado)->toBe('pagada');

    // Un director del colegio no entra a la consola.
    actuarComo(directorDemo(), true);
    $this->getJson('/api/v1/plataforma/cobro-mensual?periodo=2027-04')->assertForbidden();
});
