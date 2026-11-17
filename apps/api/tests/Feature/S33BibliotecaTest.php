<?php

use App\Models\User;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| S33 · Biblioteca: EDUN-151 libros y ejemplares · 152 prestar y devolver · 153 vencidos · 154 reservas ·
| 155 catálogo · 156 plan lector · 157 digitales con licencias · 158 inventario y bajas · 159 reglas · 160 estadísticas.
*/

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]]), 'openlibrary.org/*' => Http::response([
        'ISBN:9786123191234' => ['title' => 'Paco Yunque', 'authors' => [['name' => 'César Vallejo']], 'publishers' => [['name' => 'Editorial Ejemplo']], 'publish_date' => '2019'],
    ])]);
    Storage::fake(AlmacenArchivos::DISCO);
    $db = dueno();
    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    $m = $db->table('modulos_catalogo')->where('codigo', 'BIB')->value('id');
    if (! $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->exists()) {
        $db->table('suscripcion_modulos')->insert(['institucion_id' => demoId(), 'suscripcion_id' => $sus, 'modulo_id' => $m]);
    }
    $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->update(['activo' => true]);
    $db->table('bib_reglas')->where('institucion_id', demoId())->delete();
});

function lectorBib(): array
{
    $db = dueno();
    $inst = demoId();
    $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $seccion = $db->table('secciones')->where('anio_escolar_id', $anio)->orderBy('id')->first();
    $carne = 'EN'.strtoupper(bin2hex(random_bytes(5)));
    $u = $db->table('users')->insertGetId(['nombres' => 'Lector', 'apellidos' => 'Bib '.uniqid(), 'numero_documento' => (string) random_int(70000000, 79999999), 'email' => 'lb.'.uniqid().'@alumno.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $u, 'rol_id' => $db->table('roles')->where('codigo', 'alumno')->value('id')]);
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'user_id' => $u, 'apellido_paterno' => 'Bib'.uniqid(), 'nombres' => 'Lía', 'numero_documento' => (string) random_int(60000000, 69999999), 'codigo_carne' => $carne]);
    $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion->id, 'origen' => 'manual']);
    $db->table('dispositivos_moviles')->insert(['user_id' => $u, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);

    return ['alumno' => $alumno, 'carne' => $carne, 'usuario' => User::query()->findOrFail($u), 'grado' => $seccion->grado_id, 'anio' => $anio];
}

it('EDUN-151 / 155 / 159 · libros por ISBN o a mano con ejemplares, catálogo con filtros y reglas', function () {
    $bib = personaDemo('bibliotecario');
    $isbn = enDemo($bib, 'GET', '/biblioteca/isbn/978-612-319-123-4')->assertOk();
    expect($isbn->json('titulo'))->toBe('Paco Yunque')->and($isbn->json('autor'))->toBe('César Vallejo');
    actuarComo($bib, true, demoId());
    $l = test()->post('/api/v1/biblioteca/libros', ['titulo' => 'Paco Yunque', 'autor' => 'César Vallejo', 'isbn' => '9786123191234', 'tipo' => 'libro', 'nivel' => 'primaria', 'categoria' => 'Cuento', 'ejemplares' => 3, 'estante' => 'A1'], ['X-Institucion' => (string) demoId(), 'Accept' => 'application/json'])->assertCreated();
    expect($l->json('ejemplares'))->toBe(3)->and(collect($l->json('ejemplares_detalle'))->pluck('codigo')->all())->toBe(['B'.$l->json('id').'-001', 'B'.$l->json('id').'-002', 'B'.$l->json('id').'-003']);
    expect(collect(enDemo($bib, 'GET', '/biblioteca/catalogo?buscar=vallejo&nivel=primaria')->assertOk()->json('data'))->pluck('id'))->toContain($l->json('id'));
    expect(collect(enDemo($bib, 'GET', '/biblioteca/catalogo?nivel=secundaria')->json('data'))->pluck('id'))->not->toContain($l->json('id'));
    enDemo($bib, 'PUT', '/biblioteca/reglas', ['max_alumno' => 1, 'dias_alumno' => 5])->assertOk()->assertJsonPath('max_alumno', 1);
    // El catálogo lo ve un alumno, la ficha sin decir quién tiene cada ejemplar.
    $a = lectorBib();
    expect(enDemo($a['usuario'], 'GET', "/biblioteca/libros/{$l->json('id')}")->assertOk()->json('ejemplares_detalle.0.lector'))->toBeNull();
    enDemo($a['usuario'], 'POST', '/biblioteca/libros', ['titulo' => 'X', 'tipo' => 'libro'])->assertForbidden();
});

it('EDUN-152 / 153 / 154 · préstamo con límite, devolución con suspensión por atraso y reserva que avisa', function () {
    $bib = personaDemo('bibliotecario');
    enDemo($bib, 'PUT', '/biblioteca/reglas', ['max_alumno' => 1, 'dias_alumno' => 7, 'suspension_por_atraso' => true])->assertOk();
    actuarComo($bib, true, demoId());
    $libro = test()->post('/api/v1/biblioteca/libros', ['titulo' => 'El caballero Carmelo', 'tipo' => 'libro', 'ejemplares' => 2], ['X-Institucion' => (string) demoId(), 'Accept' => 'application/json'])->json('id');
    $e1 = "B{$libro}-001";
    $e2 = "B{$libro}-002";
    $a = lectorBib();
    $b = lectorBib();
    $p = enDemo($bib, 'POST', '/biblioteca/prestamos', ['ejemplar' => strtolower($e1), 'lector' => $a['carne']])->assertCreated();
    expect($p->json('vence'))->toBe(now()->addDays(7)->toDateString());
    enDemo($bib, 'POST', '/biblioteca/prestamos', ['ejemplar' => $e1, 'lector' => $b['carne']])->assertUnprocessable();
    enDemo($bib, 'POST', '/biblioteca/prestamos', ['ejemplar' => $e2, 'lector' => $a['carne']])->assertUnprocessable()->assertJsonValidationErrors('lector');

    // B reserva: queda el segundo ejemplar separado para él.
    $r = enDemo($b['usuario'], 'POST', '/biblioteca/reservas', ['bib_libro_id' => $libro, 'alumno_id' => $b['alumno']])->assertCreated();
    expect($r->json('estado'))->toBe('disponible');
    $otro = lectorBib();
    enDemo($bib, 'POST', '/biblioteca/prestamos', ['ejemplar' => $e2, 'lector' => $otro['carne']])->assertUnprocessable();
    enDemo($bib, 'POST', '/biblioteca/prestamos', ['ejemplar' => $e2, 'lector' => $b['carne']])->assertCreated();
    expect(dueno()->table('bib_reservas')->find($r->json('id'))->estado)->toBe('atendida');

    // Vencido: aviso diario (una vez) y, al devolver con 3 días de atraso, suspensión de 3 días.
    dueno()->table('bib_prestamos')->where('id', $p->json('id'))->update(['vence' => now()->subDays(3)->toDateString()]);
    Artisan::call('bib:vencidos');
    Artisan::call('bib:vencidos');
    expect(dueno()->table('bib_prestamos')->find($p->json('id'))->avisado_at)->not->toBeNull();
    Http::assertSent(fn ($q) => str_contains(json_encode($q->data(), JSON_UNESCAPED_UNICODE), 'Libro por devolver'));
    $d = enDemo($bib, 'POST', '/biblioteca/devoluciones', ['ejemplar' => $e1])->assertOk();
    expect($d->json('atraso_dias'))->toBe(3)->and($d->json('suspendido_hasta'))->toBe(now()->addDays(3)->toDateString());
    enDemo($bib, 'POST', '/biblioteca/prestamos', ['ejemplar' => $e1, 'lector' => $a['carne']])->assertUnprocessable()->assertJsonValidationErrors('lector');

    // Otra reserva en espera: al devolver, se separa y avisa.
    $c = lectorBib();
    enDemo($bib, 'POST', '/biblioteca/prestamos', ['ejemplar' => $e1, 'lector' => $c['carne']])->assertCreated();
    enDemo($a['usuario'], 'POST', '/biblioteca/reservas', ['bib_libro_id' => $libro, 'alumno_id' => $a['alumno']])->assertCreated()->assertJsonPath('estado', 'en_espera');
    expect(enDemo($bib, 'POST', '/biblioteca/devoluciones', ['ejemplar' => $e2])->json('reservado'))->toBeTrue();
    Http::assertSent(fn ($q) => str_contains(json_encode($q->data(), JSON_UNESCAPED_UNICODE), 'Tu reserva está lista'));
    expect(collect(enDemo($a['usuario'], 'GET', '/biblioteca/mia')->assertOk()->json('reservas'))->first()['estado'])->toBe('disponible');
});

it('EDUN-156 · plan lector por grado y periodo con avance y recordatorio', function () {
    $docente = personaDemo('docente');
    $bib = personaDemo('bibliotecario');
    actuarComo($bib, true, demoId());
    $libro = test()->post('/api/v1/biblioteca/libros', ['titulo' => 'Cuentos andinos', 'tipo' => 'libro', 'ejemplares' => 1], ['X-Institucion' => (string) demoId(), 'Accept' => 'application/json'])->json('id');
    $a = lectorBib();
    $periodo = dueno()->table('periodos')->where('anio_escolar_id', $a['anio'])->orderBy('numero')->value('id');
    $plan = enDemo($docente, 'POST', '/biblioteca/plan-lector', ['grado_id' => $a['grado'], 'periodo_id' => $periodo, 'bib_libro_id' => $libro])->assertCreated()->json('id');
    enDemo($docente, 'POST', '/biblioteca/plan-lector', ['grado_id' => $a['grado'], 'periodo_id' => $periodo, 'bib_libro_id' => $libro])->assertUnprocessable();
    enDemo($docente, 'PUT', "/biblioteca/plan-lector/{$plan}/avance/{$a['alumno']}", ['estado' => 'leyendo'])->assertOk();
    expect(collect(enDemo($docente, 'GET', "/biblioteca/plan-lector/{$plan}/avance")->assertOk()->json('data'))->firstWhere('alumno_id', $a['alumno'])['estado'])->toBe('leyendo');
    expect(enDemo($docente, 'POST', "/biblioteca/plan-lector/{$plan}/recordatorio")->assertOk()->json('enviados'))->toBeGreaterThanOrEqual(1);
    expect(collect(enDemo($a['usuario'], 'GET', '/biblioteca/mia')->json('plan_lector'))->firstWhere('titulo', 'Cuentos andinos')['estado'])->toBe('leyendo');
});

it('EDUN-157 / 158 / 160 · digitales con licencias, inventario por estante, bajas, etiquetas y estadísticas', function () {
    $bib = personaDemo('bibliotecario');
    actuarComo($bib, true, demoId());
    $h = ['X-Institucion' => (string) demoId(), 'Accept' => 'application/json'];
    test()->post('/api/v1/biblioteca/libros', ['titulo' => 'Sin licencias', 'tipo' => 'ebook'], $h)->assertUnprocessable();
    $eb = test()->post('/api/v1/biblioteca/libros', ['titulo' => 'Libro digital', 'tipo' => 'ebook', 'licencias' => 1, 'archivo' => UploadedFile::fake()->create('libro.pdf', 300, 'application/pdf')], $h)->assertCreated()->json('id');
    $a = lectorBib();
    $b = lectorBib();
    expect(enDemo($a['usuario'], 'POST', "/biblioteca/digitales/{$eb}/lectura")->assertOk()->json('url'))->toBeString();
    enDemo($b['usuario'], 'POST', "/biblioteca/digitales/{$eb}/lectura")->assertUnprocessable();
    enDemo($a['usuario'], 'POST', "/biblioteca/digitales/{$eb}/devolucion")->assertOk();
    enDemo($b['usuario'], 'POST', "/biblioteca/digitales/{$eb}/lectura")->assertOk();
    // Vence solo.
    dueno()->table('bib_lecturas_digitales')->where('bib_libro_id', $eb)->whereNull('devuelto_at')->update(['vence' => now()->subMinute()]);
    Artisan::call('bib:vencidos');
    expect(dueno()->table('bib_lecturas_digitales')->where('bib_libro_id', $eb)->whereNull('devuelto_at')->count())->toBe(0);

    actuarComo($bib, true, demoId());
    $libro = test()->post('/api/v1/biblioteca/libros', ['titulo' => 'Inventario', 'tipo' => 'libro', 'ejemplares' => 3, 'estante' => 'Z9'], $h)->json('id');
    $inv = enDemo($bib, 'POST', '/biblioteca/inventario', ['estante' => 'Z9', 'codigos' => ["B{$libro}-001", "B{$libro}-002", 'NOEXISTE']])->assertOk();
    expect($inv->json('leidos'))->toBe(2)->and($inv->json('desconocidos'))->toBe(['NOEXISTE'])->and(collect($inv->json('faltan'))->pluck('codigo')->all())->toBe(["B{$libro}-003"]);
    $ej = dueno()->table('bib_ejemplares')->where('codigo', "B{$libro}-003")->value('id');
    enDemo($bib, 'PATCH', "/biblioteca/ejemplares/{$ej}", ['baja' => true])->assertUnprocessable();
    enDemo($bib, 'PATCH', "/biblioteca/ejemplares/{$ej}", ['baja' => true, 'motivo' => 'Extraviado en inventario'])->assertOk();
    expect(dueno()->table('bib_ejemplares')->find($ej)->estado)->toBe('baja');
    actuarComo($bib, true, demoId());
    $pdf = test()->postJson('/api/v1/biblioteca/etiquetas', ['ids' => [$ej]], $h);
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $est = enDemo($bib, 'GET', '/biblioteca/estadisticas')->assertOk();
    expect($est->json())->toHaveKeys(['por_mes', 'mas_leidos', 'lectores_por_nivel']);
});
