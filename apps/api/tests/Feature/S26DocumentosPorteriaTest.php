<?php

use App\Models\User;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| S26 · EDUN-119 / 120 expedientes con vencimientos · 121 documentos institucionales con lectura ·
| 122 autorizaciones firmadas por los padres · 187 autorizados desde la app · 189 salida
| anticipada · 190 visitas.
*/

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    Storage::fake(AlmacenArchivos::DISCO);
    $db = dueno();
    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    foreach (['DOC', 'POR'] as $codigo) {
        $m = $db->table('modulos_catalogo')->where('codigo', $codigo)->value('id');
        if (! $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->exists()) {
            $db->table('suscripcion_modulos')->insert(['institucion_id' => demoId(), 'suscripcion_id' => $sus, 'modulo_id' => $m]);
        }
        $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->update(['activo' => true]);
    }
});

function postArchivoS26(User $usuario, string $url, array $datos)
{
    actuarComo($usuario, true, demoId());

    return test()->post('/api/v1'.$url, $datos, ['X-Institucion' => (string) demoId(), 'Accept' => 'application/json']);
}

/** Alumno en una sección propia de 2027 con su madre (con cuenta y teléfono). */
function familiaS26(): array
{
    $db = dueno();
    $inst = demoId();
    $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $seccion = (int) $db->table('secciones')->where('anio_escolar_id', $anio)->orderByDesc('id')->value('id');
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Docs'.uniqid(), 'nombres' => 'Teo', 'numero_documento' => (string) random_int(60000000, 69999999)]);
    $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion, 'origen' => 'manual']);
    $u = $db->table('users')->insertGetId(['nombres' => 'Ana', 'apellidos' => 'Docs '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999), 'email' => 'ana.'.uniqid().'@familia.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $u, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $apo = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $u, 'numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'Docs', 'nombres' => 'Ana']);
    $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'apoderado_id' => $apo, 'parentesco' => 'madre', 'puede_recoger' => true]);
    $db->table('dispositivos_moviles')->insert(['user_id' => $u, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);

    return ['alumno' => $alumno, 'seccion' => $seccion, 'familia' => User::query()->findOrFail($u)];
}

it('EDUN-119 / 120 · expedientes por categoría con vencimiento y alerta; el del personal es solo de dirección', function () {
    $f = familiaS26();
    $secretaria = personaDemo('secretaria');
    $d = postArchivoS26($secretaria, "/expedientes/alumno/{$f['alumno']}", ['categoria' => 'dni', 'vence' => now()->addDays(7)->toDateString(), 'archivo' => UploadedFile::fake()->create('dni.pdf', 40, 'application/pdf')])->assertCreated();
    expect($d->json('vencimiento.codigo'))->toBe('por_vencer')->and($d->json('categoria_nombre'))->toBe('DNI o carné de extranjería');
    postArchivoS26($secretaria, "/expedientes/alumno/{$f['alumno']}", ['categoria' => 'inventada', 'archivo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertUnprocessable();
    expect(enDemo($secretaria, 'GET', "/expedientes/documentos/{$d->json('id')}/archivo")->assertOk()->json('url'))->toBeString();
    expect(collect(enDemo($secretaria, 'GET', '/expedientes/vencimientos')->assertOk()->json('data'))->pluck('id'))->toContain($d->json('id'));

    // El personal: secretaría no entra; dirección sí, y el fin de contrato entra en las alertas.
    $personal = dueno()->table('personal')->insertGetId(['institucion_id' => demoId(), 'nombres' => 'Lucía', 'apellidos' => 'Contrato '.uniqid(), 'numero_documento' => (string) random_int(30000000, 39999999), 'cargo' => 'Docente', 'contrato_fin' => now()->addDays(30)->toDateString()]);
    enDemo($secretaria, 'GET', "/expedientes/personal/{$personal}")->assertForbidden();
    $director = personaDemo('director');
    postArchivoS26($director, "/expedientes/personal/{$personal}", ['categoria' => 'colegiatura', 'vence' => now()->subDay()->toDateString(), 'archivo' => UploadedFile::fake()->create('cole.pdf', 40, 'application/pdf')])->assertCreated()
        ->assertJsonPath('vencimiento.codigo', 'vencido');
    $alertas = collect(enDemo($director, 'GET', '/expedientes/vencimientos')->assertOk()->json('data'));
    expect($alertas->where('titular_id', $personal)->pluck('categoria')->all())->toEqualCanonicalizing(['colegiatura', 'contrato']);

    // Aviso diario: vence en 7 días (alumno) y en 30 (contrato).
    dueno()->table('dispositivos_moviles')->insert(['user_id' => $director->id, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);
    Artisan::call('doc:vencimientos');
    Http::assertSent(fn ($r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'por vencer'));
});

it('EDUN-121 · documentos institucionales con versiones y confirmación de lectura', function () {
    $director = personaDemo('director');
    $f = familiaS26();
    $nombre = 'Reglamento '.uniqid();
    $v1 = postArchivoS26($director, '/documentos/institucionales', ['tipo' => 'reglamento', 'nombre' => $nombre, 'version' => '2027', 'requiere_lectura' => 1, 'archivo' => UploadedFile::fake()->create('r.pdf', 80, 'application/pdf')])->assertCreated();
    postArchivoS26($director, '/documentos/institucionales', ['tipo' => 'reglamento', 'nombre' => $nombre, 'version' => '2027', 'archivo' => UploadedFile::fake()->create('r.pdf', 80, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors('version');
    $v2 = postArchivoS26($director, '/documentos/institucionales', ['tipo' => 'reglamento', 'nombre' => $nombre, 'version' => '2027-B', 'requiere_lectura' => 1, 'archivo' => UploadedFile::fake()->create('r2.pdf', 80, 'application/pdf')])->assertCreated();
    expect(dueno()->table('documentos_institucionales')->find($v1->json('id'))->vigente)->toBeFalse();

    $mios = collect(enDemo($f['familia'], 'GET', '/familia/documentos')->assertOk()->json('data'));
    expect($mios->pluck('id'))->toContain($v2->json('id'))->not->toContain($v1->json('id'));
    enDemo($f['familia'], 'GET', "/familia/documentos/{$v1->json('id')}/archivo")->assertNotFound();
    enDemo($f['familia'], 'POST', "/familia/documentos/{$v2->json('id')}/lectura")->assertOk();
    enDemo($f['familia'], 'POST', "/familia/documentos/{$v2->json('id')}/lectura")->assertOk();
    $lecturas = collect(enDemo($director, 'GET', "/documentos/institucionales/{$v2->json('id')}/lecturas")->assertOk()->json('data'));
    expect($lecturas->firstWhere('user_id', $f['familia']->id)['leido_at'])->not->toBeNull()
        ->and(dueno()->table('documento_lecturas')->where('documento_institucional_id', $v2->json('id'))->count())->toBe(1);
    enDemo(personaDemo('docente'), 'POST', '/documentos/institucionales', [])->assertForbidden();
});

it('EDUN-122 · el docente pide una autorización a sus secciones y cada apoderado la firma una vez en la app', function () {
    $f = familiaS26();
    $otra = familiaS26();
    $director = personaDemo('director');
    $a = enDemo($director, 'POST', '/autorizaciones', ['titulo' => 'Paseo al museo', 'contenido' => 'Autorizo a mi hijo a ir al museo el viernes.', 'secciones' => [$f['seccion']], 'vence' => now()->addDays(5)->toDateString()])->assertCreated();
    expect($a->json('total'))->toBeGreaterThanOrEqual(2)->and($a->json('pendientes'))->toBe($a->json('total'));
    Http::assertSent(fn ($r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Paseo al museo'));

    $mias = collect(enDemo($f['familia'], 'GET', '/familia/autorizaciones')->assertOk()->json('data'))->where('id', $a->json('id'));
    expect($mias->first()['estado'])->toBe('pendiente');
    enDemo($f['familia'], 'POST', "/familia/autorizaciones/{$a->json('id')}/firma", ['alumno_id' => $otra['alumno'], 'acepta' => true])->assertForbidden();
    enDemo($f['familia'], 'POST', "/familia/autorizaciones/{$a->json('id')}/firma", ['alumno_id' => $f['alumno'], 'acepta' => true])->assertOk();
    enDemo($f['familia'], 'POST', "/familia/autorizaciones/{$a->json('id')}/firma", ['alumno_id' => $f['alumno'], 'acepta' => false])->assertUnprocessable();
    enDemo($otra['familia'], 'POST', "/familia/autorizaciones/{$a->json('id')}/firma", ['alumno_id' => $otra['alumno'], 'acepta' => false])->assertOk();

    $s = enDemo($director, 'GET', "/autorizaciones/{$a->json('id')}")->assertOk();
    $porAlumno = collect($s->json('alumnos'))->pluck('estado', 'alumno_id');
    expect($porAlumno[$f['alumno']])->toBe('acepta')->and($porAlumno[$otra['alumno']])->toBe('no_acepta')
        ->and($s->json('aceptan'))->toBe(1)->and($s->json('no_aceptan'))->toBe(1);
    expect(dueno()->table('autorizacion_firmas')->where('autorizacion_id', $a->json('id'))->where('alumno_id', $f['alumno'])->value('ip'))->not->toBeNull();

    // Un docente sin secciones a su cargo no puede pedirla a cualquiera.
    enDemo(personaDemo('docente'), 'POST', '/autorizaciones', ['titulo' => 'Otra cosa', 'contenido' => 'Contenido de prueba largo', 'secciones' => [$f['seccion']], 'vence' => now()->addDay()->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors('secciones');
});

it('EDUN-187 / 189 · la familia registra autorizados y pide una salida anticipada que portería cumple', function () {
    $f = familiaS26();
    $otra = familiaS26();
    $aut = enDemo($f['familia'], 'POST', '/familia/autorizados', ['alumno_id' => $f['alumno'], 'nombres' => 'Pedro Tío', 'numero_documento' => '4567 8901', 'parentesco' => 'Tío', 'vigente_hasta' => now()->addMonth()->toDateString()])->assertCreated();
    expect($aut->json('numero_documento'))->toBe('45678901')->and($aut->json('vigente'))->toBeTrue();
    enDemo($f['familia'], 'POST', '/familia/autorizados', ['alumno_id' => $otra['alumno'], 'nombres' => 'Intruso', 'numero_documento' => '11112222', 'parentesco' => 'Otro'])->assertForbidden();
    enDemo($f['familia'], 'POST', '/familia/autorizados', ['alumno_id' => $f['alumno'], 'nombres' => 'Malo', 'numero_documento' => '12', 'parentesco' => 'Otro'])->assertUnprocessable();

    $s = enDemo($f['familia'], 'POST', '/familia/salidas', ['alumno_id' => $f['alumno'], 'fecha' => now()->toDateString(), 'hora' => '11:30', 'motivo' => 'Cita médica', 'por_autorizado_id' => $aut->json('id')])->assertCreated();
    expect($s->json('estado'))->toBe('solicitada')->and($s->json('recoge'))->toBe('Pedro Tío (Tío)');
    enDemo($f['familia'], 'POST', '/familia/salidas', ['alumno_id' => $f['alumno'], 'fecha' => now()->toDateString(), 'hora' => '12:00', 'motivo' => 'Otra'])->assertUnprocessable();

    $secretaria = personaDemo('secretaria');
    enDemo($secretaria, 'POST', "/porteria/anticipadas/{$s->json('id')}/decision", ['decision' => 'rechazar'])->assertUnprocessable()->assertJsonValidationErrors('respuesta');
    enDemo($secretaria, 'POST', "/porteria/anticipadas/{$s->json('id')}/decision", ['decision' => 'autorizar'])->assertOk()->assertJsonPath('estado', 'autorizada');
    Http::assertSent(fn ($r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'autorizada'));

    // La portería la ve en su lista y, al verificar a quien recoge, se cumple esa misma salida.
    $porteria = personaDemo('porteria');
    $item = collect(enDemo($porteria, 'GET', '/porteria/lista')->assertOk()->json('alumnos'))->firstWhere('id', $f['alumno']);
    expect($item['salida_anticipada']['hora'])->toBe('11:30');
    enDemo($porteria, 'POST', '/porteria/salidas', ['alumno_id' => $f['alumno'], 'numero_documento' => '45678901'])->assertCreated();
    $fila = dueno()->table('por_salidas')->find($s->json('id'));
    expect($fila->estado)->toBe('salio')->and($fila->recogido_documento)->toBe('45678901')
        ->and(dueno()->table('por_salidas')->where('alumno_id', $f['alumno'])->count())->toBe(1);
    expect(collect(enDemo($f['familia'], 'GET', '/familia/salidas')->assertOk()->json('data'))->firstWhere('id', $s->json('id'))['estado'])->toBe('salio');

    // Desactivado, ya no sirve para recoger.
    enDemo($f['familia'], 'PUT', "/familia/autorizados/{$aut->json('id')}", ['alumno_id' => $f['alumno'], 'nombres' => 'Pedro Tío', 'numero_documento' => '45678901', 'parentesco' => 'Tío', 'activo' => false])->assertOk()->assertJsonPath('vigente', false);
    enDemo($porteria, 'POST', '/porteria/salidas', ['alumno_id' => $f['alumno'], 'numero_documento' => '45678901'])->assertUnprocessable();
});

it('EDUN-190 · la portería registra visitas con ingreso y salida; el documento se muestra enmascarado', function () {
    $porteria = personaDemo('porteria');
    $v = enDemo($porteria, 'POST', '/porteria/visitas', ['nombres' => 'Carlos Proveedor', 'numero_documento' => '70123456', 'visita_a' => 'Dirección', 'motivo' => 'Entrega de útiles'])->assertCreated();
    expect($v->json('numero_documento'))->toBe('•••••456')->and($v->json('salida_at'))->toBeNull();
    enDemo($porteria, 'POST', "/porteria/visitas/{$v->json('id')}/salida")->assertOk();
    enDemo($porteria, 'POST', "/porteria/visitas/{$v->json('id')}/salida")->assertUnprocessable();
    $hoy = collect(enDemo(personaDemo('director'), 'GET', '/porteria/visitas')->assertOk()->json('data'))->firstWhere('id', $v->json('id'));
    expect($hoy['salida_at'])->not->toBeNull();
    enDemo(personaDemo('docente'), 'GET', '/porteria/visitas')->assertForbidden();
});
