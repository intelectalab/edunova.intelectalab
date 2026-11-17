<?php

use App\Models\User;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| S25 · Trámites en línea: EDUN-113 catálogo · 114 solicitud desde la app · 115 bandeja ·
| 116 firma por lote · 117 plantillas con variables · 118 verificación pública por QR.
*/

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    Storage::fake(AlmacenArchivos::DISCO);
    $db = dueno();
    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    $doc = $db->table('modulos_catalogo')->where('codigo', 'DOC')->value('id');
    if (! $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $doc)->exists()) {
        $db->table('suscripcion_modulos')->insert(['institucion_id' => demoId(), 'suscripcion_id' => $sus, 'modulo_id' => $doc]);
    }
    $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $doc)->update(['activo' => true]);
});

/** Petición con archivos (multipart) como $usuario en el colegio demo. */
function conArchivosS25(User $usuario, string $url, array $datos)
{
    actuarComo($usuario, true, demoId());

    return test()->post('/api/v1'.$url, $datos, ['X-Institucion' => (string) demoId(), 'Accept' => 'application/json']);
}

/** Alumno matriculado en 2027 con su madre (con cuenta). */
function familiaS25(): array
{
    $db = dueno();
    $inst = demoId();
    $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $seccion = (int) $db->table('secciones')->where('anio_escolar_id', $anio)->orderBy('id')->value('id');
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Tramite'.uniqid(), 'apellido_materno' => 'Rojas', 'nombres' => 'Lía', 'numero_documento' => (string) random_int(60000000, 69999999)]);
    $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion, 'origen' => 'manual']);
    $u = $db->table('users')->insertGetId(['nombres' => 'Rosa', 'apellidos' => 'Tramite '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999), 'email' => 'rosa.'.uniqid().'@familia.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $u, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $apo = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $u, 'numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'Tramite', 'nombres' => 'Rosa']);
    $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'apoderado_id' => $apo, 'parentesco' => 'madre']);
    $db->table('dispositivos_moviles')->insert(['user_id' => $u, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);

    return ['alumno' => $alumno, 'familia' => User::query()->findOrFail($u)];
}

function conceptoTramiteS25(float $monto): int
{
    return (int) dueno()->table('conceptos_cobro')->insertGetId(['institucion_id' => demoId(), 'codigo' => 'TR'.random_int(1000, 99999), 'nombre' => 'Constancia', 'tipo' => 'tramite', 'monto' => $monto]);
}

it('EDUN-117 · las plantillas usan variables documentadas, se versionan y limpian el HTML', function () {
    $director = personaDemo('director');
    enDemo($director, 'POST', '/documentos/plantillas', ['nombre' => 'Mala', 'tipo' => 'constancia', 'contenido_html' => '<p>Hola {alumno.apodo}</p>'])
        ->assertUnprocessable()->assertJsonValidationErrors('contenido_html');

    $p = enDemo($director, 'POST', '/documentos/plantillas', [
        'nombre' => 'Constancia de estudios', 'tipo' => 'constancia',
        'contenido_html' => '<h1>Constancia</h1><p onclick="x()">Se hace constar que {alumno.nombre} cursa {seccion} en {colegio.nombre}.</p><script>alert(1)</script>',
    ])->assertCreated();
    expect($p->json('version'))->toBe(1)->and($p->json('contenido_html'))->not->toContain('script')->not->toContain('onclick');

    $v2 = enDemo($director, 'PUT', '/documentos/plantillas/'.$p->json('id'), ['nombre' => 'Constancia de estudios', 'tipo' => 'constancia', 'contenido_html' => '<p>{alumno.nombre} · {fecha_larga}</p>'])->assertOk();
    expect($v2->json('version'))->toBe(2)->and($v2->json('plantilla_origen_id'))->toBe($p->json('id'))
        ->and(dueno()->table('plantillas_documento')->find($p->json('id'))->activa)->toBeFalse();
    $lista = enDemo($director, 'GET', '/documentos/plantillas')->assertOk();
    $vigente = collect($lista->json('data'))->firstWhere('id', $v2->json('id'));
    expect(collect($vigente['versiones'])->pluck('version')->all())->toEqual([2, 1])
        ->and(collect($lista->json('variables'))->pluck('variable'))->toContain('{alumno.nombre}');

    // Vista previa con datos reales de un alumno.
    $f = familiaS25();
    actuarComo($director, true, demoId());
    $pdf = test()->get('/api/v1/documentos/plantillas/'.$v2->json('id').'/vista-previa?alumno_id='.$f['alumno'], ['X-Institucion' => (string) demoId()]);
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    // Restaurar la versión 1 crea la versión 3.
    $v3 = enDemo($director, 'POST', '/documentos/plantillas/'.$p->json('id').'/restaurar')->assertOk();
    expect($v3->json('version'))->toBe(3)->and($v3->json('contenido_html'))->toContain('Se hace constar');
    enDemo(personaDemo('docente'), 'GET', '/documentos/plantillas')->assertForbidden();
});

it('EDUN-113 a 116 · la familia pide un trámite con costo, la secretaría lo observa y el director lo firma al estar pagado', function () {
    $director = personaDemo('director');
    $secretaria = personaDemo('secretaria');
    $plantilla = enDemo($director, 'POST', '/documentos/plantillas', ['nombre' => 'Constancia S25', 'tipo' => 'constancia', 'contenido_html' => '<p>Constancia para {alumno.nombre}, trámite {tramite.numero}.</p>'])->json('id');
    $tipo = enDemo($director, 'POST', '/tramites/catalogo', [
        'nombre' => 'Constancia de estudios '.uniqid(), 'concepto_cobro_id' => conceptoTramiteS25(15), 'plazo_dias' => 3, 'plantilla_documento_id' => $plantilla,
        'requisitos' => [['descripcion' => 'Copia del DNI del apoderado', 'obligatorio' => true], ['descripcion' => 'Foto', 'obligatorio' => false]],
    ])->assertCreated();
    expect((float) $tipo->json('costo'))->toBe(15.0)->and($tipo->json('requisitos'))->toHaveCount(2);
    $requisito = $tipo->json('requisitos.0.id');

    $f = familiaS25();
    expect(collect(enDemo($f['familia'], 'GET', '/familia/tramites/tipos')->assertOk()->json('data'))->pluck('id'))->toContain($tipo->json('id'));
    // Sin el requisito obligatorio no se envía.
    conArchivosS25($f['familia'], '/familia/tramites', ['tramite_tipo_id' => $tipo->json('id'), 'alumno_id' => $f['alumno']])->assertUnprocessable()->assertJsonValidationErrors('adjuntos');
    // No se piden trámites de alumnos ajenos.
    conArchivosS25($f['familia'], '/familia/tramites', ['tramite_tipo_id' => $tipo->json('id'), 'alumno_id' => familiaS25()['alumno']])->assertForbidden();

    $t = conArchivosS25($f['familia'], '/familia/tramites', [
        'tramite_tipo_id' => $tipo->json('id'), 'alumno_id' => $f['alumno'], 'comentario' => 'Para una beca',
        'adjuntos' => [$requisito => UploadedFile::fake()->create('dni.pdf', 50, 'application/pdf')],
    ])->assertCreated();
    expect($t->json('estado'))->toBe('solicitado')->and($t->json('numero'))->toMatch('/^T\d{2}-\d{6}$/')->and($t->json('pagado'))->toBeFalse()
        ->and($t->json('adjuntos'))->toHaveCount(1)->and($t->json('semaforo'))->toBe('verde');
    $cargo = dueno()->table('cargos')->find(dueno()->table('tramites')->where('id', $t->json('id'))->value('cargo_id'));
    expect((float) $cargo->monto)->toBe(15.0)->and($cargo->descripcion)->toContain($t->json('numero'));
    $id = $t->json('id');

    // Bandeja: observar exige comentario; la familia responde y vuelve a revisión.
    expect(collect(enDemo($secretaria, 'GET', '/tramites')->assertOk()->json('data'))->pluck('id'))->toContain($id);
    enDemo($secretaria, 'POST', "/tramites/{$id}/estado", ['accion' => 'observar'])->assertUnprocessable()->assertJsonValidationErrors('comentario');
    enDemo($secretaria, 'POST', "/tramites/{$id}/estado", ['accion' => 'observar', 'comentario' => 'El DNI está borroso'])->assertOk()->assertJsonPath('estado', 'observado');
    $r = conArchivosS25($f['familia'], "/familia/tramites/{$id}/respuesta", ['comentario' => 'Va de nuevo', 'adjuntos' => [$requisito => UploadedFile::fake()->create('dni2.pdf', 40, 'application/pdf')]])->assertOk();
    expect($r->json('estado'))->toBe('en_revision')->and($r->json('adjuntos'))->toHaveCount(2);

    // Con el pago pendiente no pasa a firma.
    enDemo($secretaria, 'POST', "/tramites/{$id}/estado", ['accion' => 'a_firma'])->assertUnprocessable()->assertJsonValidationErrors('cargo');
    dueno()->table('cargos')->where('id', $cargo->id)->update(['estado' => 'pagado', 'pagado' => 15]);
    enDemo($secretaria, 'POST', "/tramites/{$id}/estado", ['accion' => 'a_firma'])->assertOk()->assertJsonPath('estado', 'por_firmar');
    // La secretaría no firma.
    enDemo($secretaria, 'POST', '/tramites/firmar', ['ids' => [$id]])->assertForbidden();

    $firma = enDemo($director, 'POST', '/tramites/firmar', ['ids' => [$id, 999999]])->assertOk();
    expect($firma->json('resultados.0.ok'))->toBeTrue()->and($firma->json('resultados.1.ok'))->toBeFalse();
    $codigo = $firma->json('resultados.0.codigo');
    $doc = dueno()->table('documentos_emitidos')->where('codigo_verificacion', $codigo)->first();
    expect(dueno()->table('tramites')->find($id)->estado)->toBe('entregado')->and(strlen($doc->sha256))->toBe(64)->and($doc->tramite_id)->toBe($id);
    Http::assertSent(fn ($req) => str_contains(json_encode($req->data(), JSON_UNESCAPED_UNICODE), 'está listo'));

    // La familia descarga el documento; la verificación pública lo da por vigente.
    expect(enDemo($f['familia'], 'GET', "/familia/tramites/{$id}/documento")->assertOk()->json('url'))->toBeString();
    $this->getJson('/api/v1/verificar/'.strtolower($codigo))->assertOk()->assertJson(['vigente' => true, 'tipo' => 'Constancia S25']);
    $this->getJson('/api/v1/verificar/DXXXX-XXXX-XXXX')->assertNotFound();

    // Anular no borra: la verificación indica que ya no es válido.
    enDemo($director, 'POST', "/documentos/emitidos/{$doc->id}/anulacion", ['motivo' => 'Datos errados'])->assertOk();
    $this->getJson('/api/v1/verificar/'.$codigo)->assertOk()->assertJson(['vigente' => false]);
    expect(dueno()->table('documentos_emitidos')->where('id', $doc->id)->exists())->toBeTrue();
});

it('EDUN-115 · un trámite «sin deuda» no pasa a firma si el alumno tiene cargos vencidos, y el rechazo anula su cargo', function () {
    $director = personaDemo('director');
    $tipo = enDemo($director, 'POST', '/tramites/catalogo', ['nombre' => 'No adeudo '.uniqid(), 'plazo_dias' => 2, 'requiere_sin_deuda' => true, 'concepto_cobro_id' => conceptoTramiteS25(10)])->json('id');
    $f = familiaS25();
    $concepto = conceptoTramiteS25(100);
    dueno()->table('cargos')->insert(['institucion_id' => demoId(), 'alumno_id' => $f['alumno'], 'concepto_cobro_id' => $concepto, 'descripcion' => 'Pensión vencida', 'emision' => now()->subMonth()->toDateString(), 'vencimiento' => now()->subDays(10)->toDateString(), 'monto' => 100]);
    $id = conArchivosS25($f['familia'], '/familia/tramites', ['tramite_tipo_id' => $tipo, 'alumno_id' => $f['alumno']])->assertCreated()->json('id');
    dueno()->table('cargos')->where('id', dueno()->table('tramites')->find($id)->cargo_id)->update(['estado' => 'pagado', 'pagado' => 10]);
    // Un trámite igual en curso no se repite.
    conArchivosS25($f['familia'], '/familia/tramites', ['tramite_tipo_id' => $tipo, 'alumno_id' => $f['alumno']])->assertUnprocessable();

    enDemo($director, 'POST', "/tramites/{$id}/estado", ['accion' => 'a_firma'])->assertUnprocessable()->assertJsonValidationErrors('deuda');

    // Otro trámite que se rechaza sin pagar: su cargo se anula.
    $otro = enDemo($director, 'POST', '/tramites/catalogo', ['nombre' => 'Duplicado de libreta '.uniqid(), 'plazo_dias' => 2, 'concepto_cobro_id' => conceptoTramiteS25(8)])->json('id');
    $t2 = conArchivosS25($f['familia'], '/familia/tramites', ['tramite_tipo_id' => $otro, 'alumno_id' => $f['alumno']])->json('id');
    enDemo($director, 'POST', "/tramites/{$t2}/estado", ['accion' => 'rechazar', 'comentario' => 'Ya tiene su libreta en la app'])->assertOk()->assertJsonPath('estado', 'rechazado');
    expect(dueno()->table('cargos')->find(dueno()->table('tramites')->find($t2)->cargo_id)->estado)->toBe('anulado');
    expect(collect(enDemo($f['familia'], 'GET', '/familia/tramites')->assertOk()->json('data'))->pluck('estado', 'id')->all())->toMatchArray([$id => 'solicitado', $t2 => 'rechazado']);
});

it('EDUN-116 · un trámite sin plantilla se entrega subiendo el PDF firmado y el módulo apagado no responde', function () {
    $director = personaDemo('director');
    $tipo = enDemo($director, 'POST', '/tramites/catalogo', ['nombre' => 'Certificado de estudios '.uniqid(), 'plazo_dias' => 5])->json('id');
    $f = familiaS25();
    $id = conArchivosS25($f['familia'], '/familia/tramites', ['tramite_tipo_id' => $tipo, 'alumno_id' => $f['alumno']])->json('id');
    enDemo($director, 'POST', "/tramites/{$id}/estado", ['accion' => 'a_firma'])->assertOk();
    $lote = enDemo($director, 'POST', '/tramites/firmar', ['ids' => [$id]])->assertOk();
    expect($lote->json('resultados.0.ok'))->toBeFalse()->and($lote->json('resultados.0.mensaje'))->toContain('plantilla');
    $e = conArchivosS25($director, "/tramites/{$id}/entrega", ['documento' => UploadedFile::fake()->create('certificado.pdf', 80, 'application/pdf')])->assertOk();
    expect($e->json('estado'))->toBe('entregado')->and($e->json('codigo'))->toBeString();

    $db = dueno();
    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $db->table('modulos_catalogo')->where('codigo', 'DOC')->value('id'))->delete();
    enDemo($director, 'GET', '/tramites')->assertForbidden();
});
